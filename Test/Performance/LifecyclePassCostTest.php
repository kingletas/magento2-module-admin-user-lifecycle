<?php
/**
 * LifecyclePassCostTest.php
 *
 * @package     Commerce_AdminUserLifecycle
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Performance;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Model\Service\DeactivateInactiveUsers;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Test\Behaviour\Fake\InMemoryDirectory;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ArrayScopeConfig;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingEventManager;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingNotifier;
use Commerce\Foundation\Test\Support\BudgetAssertions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What one nightly pass costs.
 */
final class LifecyclePassCostTest extends TestCase
{
    use BudgetAssertions;

    private const SECTION = 'commerce_adminuserlifecycle';
    private const DAY = 86400;

    private InMemoryDirectory $directory;

    private int $findCalls = 0;
    private int $activeCounts = 0;
    private int $journalWrites = 0;
    private int $journalEntries = 0;

    /** @var array<string, string> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->directory = new InMemoryDirectory();
        $this->findCalls = 0;
        $this->activeCounts = 0;
        $this->journalWrites = 0;
        $this->journalEntries = 0;
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/general/dry_run' => '0',
            self::SECTION . '/general/batch_size' => '50',
            self::SECTION . '/warn/enabled' => '0',
            self::SECTION . '/deactivate/enabled' => '1',
            self::SECTION . '/deactivate/inactive_days' => '180',
            self::SECTION . '/deactivate/new_account_grace_days' => '30',
            self::SECTION . '/delete/enabled' => '0',
            self::SECTION . '/protect/min_active_admins' => '1',
        ];
    }

    public function testDormantAccountsAreReadInPagesRatherThanAllAtOnce(): void
    {
        self::assertCostPerBatch(
            'queries reading the dormant set',
            50,
            function (int $admins): int {
                $this->reset($admins);
                $this->runPass();

                // The last page is the one that comes back short, and that is
                // the extra call: n pages of work is n+1 reads.
                return max(1, $this->findCalls - 1);
            },
            [50, 200, 1000]
        );
    }

    /**
     * Last-administrator protection is decided against this number.
     */
    public function testTheActiveCountIsAskedOncePerPassRatherThanPerCandidate(): void
    {
        self::assertConstantCost(
            'active-administrator counts per pass',
            function (int $admins): int {
                $this->reset($admins);
                $this->runPass();

                return $this->activeCounts;
            },
            [10, 200]
        );
    }

    /**
     * A pass that acts on two hundred accounts is two hundred rows and one
     * statement.
     */
    public function testTheJournalIsWrittenOncePerPass(): void
    {
        self::assertConstantCost(
            'journal statements per pass',
            function (int $admins): int {
                $this->reset($admins);
                $this->runPass();

                return $this->journalWrites;
            },
            [10, 200]
        );
    }

    /**
     * Batching the write must not become writing less: an audit journal that
     * summarises is not an audit journal.
     */
    public function testBatchingTheJournalDoesNotMeanRecordingLess(): void
    {
        $this->reset(200);

        $this->runPass();

        self::assertGreaterThanOrEqual(
            199,
            $this->journalEntries,
            'Every account the pass considered should have an entry, whatever it decided.'
        );
    }

    /**
     * This is the ordinary case - every night, on every store, for years.
     */
    public function testAPassWithNothingToDoIsOneEmptyRead(): void
    {
        $this->reset(0);

        for ($id = 1; $id <= 40; $id++) {
            $this->directory->add($this->admin($id, lastLoginDaysAgo: 1));
        }

        $this->runPass();

        self::assertCostAtMost('reads for a pass with nothing to do', 1, $this->findCalls);
        self::assertSame(0, $this->journalWrites, 'Nothing happened, so nothing was journalled.');
    }

    private function reset(int $dormantAdmins): void
    {
        $this->directory = new InMemoryDirectory();
        $this->findCalls = 0;
        $this->activeCounts = 0;
        $this->journalWrites = 0;
        $this->journalEntries = 0;

        // One person still using the store, so protection has something to
        // protect and does not stop the pass on its first candidate.
        $this->directory->add($this->admin(1, lastLoginDaysAgo: 1));

        for ($id = 2; $id <= $dormantAdmins + 1; $id++) {
            $this->directory->add($this->admin($id, lastLoginDaysAgo: 400));
        }
    }

    private function runPass(): void
    {
        $context = new StageContext($this->config(), new NullLogger(), new JournalEntryMapper());
        $finder = $this->countingFinder();

        (new LifecycleRunner(
            $this->config(),
            $finder,
            $this->journal(),
            new NullLogger(),
            new JournalEntryMapper(),
            new LifecycleEventDispatcher(new RecordingEventManager(), new NullLogger()),
            [
                'deactivate' => new DeactivateInactiveUsers(
                    $context,
                    $finder,
                    new AccountTransition(
                        $this->config(),
                        new JournalEntryMapper(),
                        $this->directory,
                        new InactivityPolicy($this->config()),
                        new ProtectionPolicy($this->config()),
                        $this->sessions(),
                        // Never reached: this pass runs the deactivation stage
                        // alone.
                        new RecordingNotifier()
                    )
                ),
            ]
        ))->run('cron');
    }

    /**
     * The directory, with its reads counted.
     */
    private function countingFinder(): AdminUserFinderInterface
    {
        return new class ($this->directory, $this) implements AdminUserFinderInterface {
            public function __construct(
                private readonly InMemoryDirectory $directory,
                private readonly LifecyclePassCostTest $test
            ) {
            }

            /**
             * @return Candidate[]
             */
            public function findDormant(
                int $dormantSeconds,
                int $graceSeconds,
                int $now,
                int $limit,
                int $afterUserId
            ): array {
                $this->test->recordFind();

                return $this->directory->findDormant($dormantSeconds, $graceSeconds, $now, $limit, $afterUserId);
            }

            /**
             * @return Candidate[]
             */
            public function findInactive(int $limit, int $afterUserId): array
            {
                $this->test->recordFind();

                return $this->directory->findInactive($limit, $afterUserId);
            }

            public function countActive(): int
            {
                $this->test->recordActiveCount();

                return $this->directory->countActive();
            }

            public function getById(int $userId): ?Candidate
            {
                return $this->directory->getById($userId);
            }
        };
    }

    /**
     * @internal Called by the counting finder.
     */
    public function recordFind(): void
    {
        $this->findCalls++;
    }

    /**
     * @internal Called by the counting finder.
     */
    public function recordActiveCount(): void
    {
        $this->activeCounts++;
    }

    /**
     * @param JournalEntry[] $entries
     *
     * @internal Called by the journal double.
     */
    public function recordJournal(array $entries): void
    {
        $this->journalWrites++;
        $this->journalEntries += count($entries);
    }

    private function journal(): LifecycleJournalInterface
    {
        return new class ($this) implements LifecycleJournalInterface {
            public function __construct(private readonly LifecyclePassCostTest $test)
            {
            }

            /**
             * @param JournalEntry[] $entries
             */
            public function recordAll(array $entries): void
            {
                if ($entries !== []) {
                    $this->test->recordJournal($entries);
                }
            }

            /**
             * @param  int[] $userIds
             * @return array<int, int>
             */
            public function getDeactivatedAt(array $userIds): array
            {
                return [];
            }

            /**
             * @param  int[] $userIds
             * @return array<int, int>
             */
            public function getWarnedAt(array $userIds): array
            {
                return [];
            }

            public function prune(int $olderThanTimestamp): int
            {
                return 0;
            }
        };
    }

    private function sessions(): SessionTerminatorInterface
    {
        return new class implements SessionTerminatorInterface {
            public function terminateFor(int $userId): int
            {
                return 0;
            }
        };
    }

    private function config(): Config
    {
        return new Config(new ArrayScopeConfig($this->settings), self::SECTION);
    }

    private function admin(int $userId, int $lastLoginDaysAgo): Candidate
    {
        $now = time();

        return new Candidate(
            $userId,
            'admin-' . $userId,
            'admin-' . $userId . '@example.test',
            'Admin ' . $userId,
            true,
            $now - ($lastLoginDaysAgo * self::DAY),
            $now - (500 * self::DAY)
        );
    }
}
