<?php
/**
 * CandidateListingCostTest.php
 *
 * @package     Commerce_AdminUserLifecycle
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Performance;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleCandidateProviderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\Api\CandidateProvider;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryDirectory;
use Commerce\Foundation\Test\Support\CountingScopeConfig;
use Commerce\Foundation\Test\Support\BudgetAssertions;
use PHPUnit\Framework\TestCase;

/**
 * What answering "what is due?" costs.
 */
class CandidateListingCostTest extends TestCase
{
    use BudgetAssertions;

    private const SECTION = 'commerce_adminusers';
    private const DAY = 86400;

    private InMemoryDirectory $directory;

    private int $journalReads = 0;

    private int $activeCounts = 0;

    /** @var array<string, string> */
    private array $settings = [];

    protected function setUp(): void
    {
        $this->directory = new InMemoryDirectory();
        $this->journalReads = 0;
        $this->activeCounts = 0;
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            self::SECTION . '/general/batch_size' => '1000',
            self::SECTION . '/deactivate/enabled' => '1',
            self::SECTION . '/deactivate/inactive_days' => '180',
            self::SECTION . '/deactivate/new_account_grace_days' => '30',
            self::SECTION . '/delete/enabled' => '1',
            self::SECTION . '/delete/deactivated_days' => '90',
            self::SECTION . '/protect/min_active_admins' => '1',
        ];
    }

    /**
     * The floor is one question about the whole store, not one per row.
     */
    public function testTheActiveAdministratorCountIsAskedOncePerPageHoweverLongThePageIs(): void
    {
        $this->assertConstantCost(
            'counting active administrators while listing deactivation candidates',
            function (int $accounts): int {
                $this->givenDormantAccounts($accounts);
                $this->activeCounts = 0;
                $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

                return $this->activeCounts;
            }
        );
    }

    /**
     * "When was this deactivated" is the read that gates every deletion.
     */
    public function testTheDeletionClockIsReadOncePerPageRatherThanOncePerAccount(): void
    {
        $this->assertConstantCost(
            'reading recorded deactivations while listing deletion candidates',
            function (int $accounts): int {
                $this->givenInactiveAccounts($accounts);
                $this->journalReads = 0;
                $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

                return $this->journalReads;
            }
        );
    }

    /**
     * A page of nothing costs nothing.
     */
    public function testAStoreWithNothingDueAsksTheJournalNothing(): void
    {
        $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertSame(0, $this->journalReads);
    }

    /**
     * Settings are read per candidate, and that is a decision rather than an
     * oversight.
     */
    public function testTheSettingsAreReadAFixedNumberOfTimesPerCandidate(): void
    {
        $counting = new CountingScopeConfig($this->settings);
        $this->givenDormantAccounts(200);

        $this->provider($counting)->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertCostAtMost(
            'configuration reads for a page of 200 deactivation candidates',
            (5 * 200) + 3,
            $counting->reads(),
            $counting->summary()
        );
    }

    private function givenDormantAccounts(int $count): void
    {
        $this->directory = new InMemoryDirectory();

        for ($id = 1; $id <= $count; $id++) {
            $this->directory->add($this->account($id, active: true));
        }
    }

    private function givenInactiveAccounts(int $count): void
    {
        $this->directory = new InMemoryDirectory();

        for ($id = 1; $id <= $count; $id++) {
            $this->directory->add($this->account($id, active: false));
        }
    }

    private function provider(?CountingScopeConfig $scopeConfig = null): CandidateProvider
    {
        $config = new Config($scopeConfig ?? new CountingScopeConfig($this->settings), self::SECTION);

        return new CandidateProvider(
            $config,
            $this->countingDirectory(),
            $this->countingJournal(),
            new InactivityPolicy($config),
            new ProtectionPolicy($config),
            new Instant()
        );
    }

    private function countingDirectory(): AdminUserFinderInterface
    {
        return new class ($this->directory, $this) implements AdminUserFinderInterface {
            public function __construct(
                private readonly InMemoryDirectory $inner,
                private readonly CandidateListingCostTest $test
            ) {
            }

            /**
             * @inheritDoc
             */
            public function findDormant(
                int $dormantSeconds,
                int $graceSeconds,
                int $now,
                int $limit,
                int $afterUserId
            ): array {
                return $this->inner->findDormant($dormantSeconds, $graceSeconds, $now, $limit, $afterUserId);
            }

            /**
             * @inheritDoc
             */
            public function findInactive(int $limit, int $afterUserId): array
            {
                return $this->inner->findInactive($limit, $afterUserId);
            }

            /**
             * @inheritDoc
             */
            public function countActive(): int
            {
                $this->test->recordActiveCount();

                return $this->inner->countActive();
            }

            /**
             * @inheritDoc
             */
            public function getById(int $userId): ?Candidate
            {
                return $this->inner->getById($userId);
            }
        };
    }

    private function countingJournal(): LifecycleJournalInterface
    {
        return new class ($this) implements LifecycleJournalInterface {
            public function __construct(private readonly CandidateListingCostTest $test)
            {
            }

            /**
             * @inheritDoc
             */
            public function recordAll(array $entries): void
            {
            }

            /**
             * @inheritDoc
             */
            public function getDeactivatedAt(array $userIds): array
            {
                $this->test->recordJournalRead();

                return [];
            }

            /**
             * @inheritDoc
             */
            public function getWarnedAt(array $userIds): array
            {
                $this->test->recordJournalRead();

                return [];
            }

            /**
             * @inheritDoc
             */
            public function prune(int $olderThanTimestamp): int
            {
                return 0;
            }
        };
    }

    /**
     * @internal Called by the counting double.
     */
    public function recordJournalRead(): void
    {
        $this->journalReads++;
    }

    /**
     * @internal Called by the counting double.
     */
    public function recordActiveCount(): void
    {
        $this->activeCounts++;
    }

    private function account(int $userId, bool $active): Candidate
    {
        $now = time();

        return new Candidate(
            $userId,
            'user' . $userId,
            sprintf('user%d@example.com', $userId),
            'A Person',
            $active,
            $now - (400 * self::DAY),
            $now - (900 * self::DAY),
            3
        );
    }
}
