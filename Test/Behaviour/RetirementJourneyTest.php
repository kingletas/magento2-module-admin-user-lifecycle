<?php
/**
 * RetirementJourneyTest.php
 *
 * @package     Commerce_AdminUserLifecycle
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Behaviour;

use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;
use Commerce\AdminUserLifecycle\Api\UserNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Model\Service\DeactivateInactiveUsers;
use Commerce\AdminUserLifecycle\Model\Service\DeleteDeactivatedUsers;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\Service\WarnInactiveUsers;
use Commerce\AdminUserLifecycle\Test\Behaviour\Fake\InMemoryDirectory;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingEventManager;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ArrayScopeConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An administrator leaves, and eighteen months later their account is gone.
 */
class RetirementJourneyTest extends TestCase
{
    private const SECTION = 'commerce_adminuserlifecycle';
    private const DAY = 86400;

    private InMemoryDirectory $directory;

    private RecordingEventManager $events;

    private ?AccountTransition $transitionInstance = null;

    /** @var JournalEntry[] Everything written to the journal, in order. */
    private array $journal = [];

    /** @var array<int, array{userId: int, deactivateAt: int}> Warnings actually sent. */
    private array $warnings = [];

    /** @var int[] Users whose sessions were terminated. */
    private array $terminated = [];

    /** @var array<string, string> */
    private array $settings = [];

    private int $now;

    /**
     * How far the test has notionally moved the clock forward.
     */
    private int $elapsed = 0;

    protected function setUp(): void
    {
        $this->directory = new InMemoryDirectory();
        $this->events = new RecordingEventManager();
        $this->transitionInstance = null;
        $this->journal = [];
        $this->warnings = [];
        $this->terminated = [];
        $this->now = time();
        $this->elapsed = 0;
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            // Off, because this module deletes administrator accounts and a dry
            // run that has to be switched on is a dry run nobody uses.
            self::SECTION . '/general/dry_run' => '0',
            self::SECTION . '/general/batch_size' => '100',
            self::SECTION . '/warn/enabled' => '1',
            self::SECTION . '/warn/days_before' => '14',
            self::SECTION . '/deactivate/enabled' => '1',
            self::SECTION . '/deactivate/inactive_days' => '180',
            self::SECTION . '/deactivate/new_account_grace_days' => '30',
            self::SECTION . '/delete/enabled' => '1',
            self::SECTION . '/delete/deactivated_days' => '90',
            self::SECTION . '/protect/min_active_admins' => '1',
        ];
    }

    public function testADormantAccountIsWarnedThenDeactivatedThenDeleted(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 170);

        // Day 170: inside the warning window, not yet due for deactivation.
        $this->runPass();
        $this->assertSame([2], array_column($this->warnings, 'userId'));
        $this->assertTrue($this->directory->isActive(2));

        // Day 181: past the dormancy threshold.
        $this->passTime(11);
        $this->runPass();
        $this->assertFalse($this->directory->isActive(2), 'The account should have been deactivated.');
        $this->assertContains(2, $this->terminated, 'And its sessions ended.');

        // Day 272: ninety days deactivated.
        $this->passTime(91);
        $this->runPass();
        $this->assertFalse($this->directory->exists(2), 'The account should have been deleted.');
        $this->assertSame([1], $this->directory->userIds());
    }

    public function testAnAccountInDailyUseIsLeftEntirelyAlone(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);

        $this->passTime(400);
        $this->runPass();

        $this->assertSame([], $this->warnings);
        $this->assertTrue($this->directory->isActive(1));
    }

    /**
     * A NULL `logdate` read as "dormant since the epoch" retires every account
     * created for a colleague who has not logged in yet.
     */
    public function testAnAccountCreatedYesterdayIsNotRetired(): void
    {
        $this->activeAdmin(2, 'starts-on-monday', lastLoginDaysAgo: null, createdDaysAgo: 1);

        $this->runPass();

        $this->assertSame([], $this->warnings);
        $this->assertTrue($this->directory->isActive(2));
    }

    /**
     * NULL read as never dormant would leave an account nobody ever signed into
     * open for good.
     */
    public function testAnAccountCreatedAndNeverUsedIsEventuallyRetired(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'never-started', lastLoginDaysAgo: null, createdDaysAgo: 200);

        $this->runPass();

        $this->assertFalse($this->directory->isActive(2));
    }

    /**
     * A store with one dormant administrator is a store somebody has to be able
     * to get back into.
     */
    public function testTheLastActiveAdministratorIsNeverRetired(): void
    {
        $this->activeAdmin(1, 'the-only-one', lastLoginDaysAgo: 400);

        $this->runPass();

        $this->assertTrue($this->directory->isActive(1), 'Retiring the last administrator locks everybody out.');
        $this->assertSame(
            [JournalEntry::ACTION_SKIPPED],
            $this->journalActionsFor(1),
            'And the refusal is recorded rather than being silent.'
        );
    }

    /**
     * This is the failure no single stage can see.
     */
    public function testTwoDormantAdministratorsAreNotBothRetiredInOnePass(): void
    {
        $this->activeAdmin(1, 'left-in-january', lastLoginDaysAgo: 400);
        $this->activeAdmin(2, 'left-in-february', lastLoginDaysAgo: 390);

        $this->runPass();

        $stillActive = array_filter([1, 2], fn (int $id): bool => $this->directory->isActive($id));

        $this->assertCount(1, $stillActive, 'Exactly one administrator must survive the pass.');
    }

    /**
     * This is the mode somebody uses to decide whether to switch the module on.
     */
    public function testADryRunReportsEverythingAndChangesNothing(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 400);

        $report = $this->runPass(dryRun: true);

        $this->assertTrue($this->directory->isActive(2), 'A dry run must not deactivate anybody.');
        $this->assertSame([], $this->warnings, 'Nor send anybody an email.');
        $this->assertTrue($report->hasChanges(), 'And it must still report what it would have done.');
    }

    /**
     * A store can disable a stage, and the pass still runs the rest.
     */
    public function testASwitchedOffStageDoesNotStopThePass(): void
    {
        $this->settings[self::SECTION . '/warn/enabled'] = '0';
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 400);

        $this->runPass();

        $this->assertSame([], $this->warnings);
        $this->assertFalse($this->directory->isActive(2), 'Deactivation should still have run.');
    }

    /**
     * The gap is the recovery window: somebody comes back from a long absence,
     * or the deactivation was wrong.
     */
    public function testADeactivatedAccountIsNotDeletedBeforeItsWaitingPeriod(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 400);

        $this->runPass();
        $this->assertFalse($this->directory->isActive(2));

        // Halfway through the ninety-day window.
        $this->passTime(45);
        $this->runPass();

        $this->assertTrue($this->directory->exists(2), 'Still inside its recovery window.');
    }

    /**
     * What the pass did to which account has to be a query rather than an
     * inference.
     */
    public function testEverythingThePassDidIsRecorded(): void
    {
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 400);

        $this->runPass();

        $this->assertContains(JournalEntry::ACTION_DEACTIVATED, $this->journalActionsFor(2));

        foreach ($this->journal as $entry) {
            $this->assertSame('cron', $entry->getActor(), 'Every entry names who did it.');
        }
    }

    public function testWithTheModuleOffNothingHappens(): void
    {
        $this->settings[self::SECTION . '/general/enabled'] = '0';
        $this->activeAdmin(1, 'still-here', lastLoginDaysAgo: 1);
        $this->activeAdmin(2, 'left-the-company', lastLoginDaysAgo: 400);

        $this->assertFalse($this->runner()->isEnabled());
        $this->assertTrue($this->directory->isActive(2));
    }

    /**
     * Let a number of days pass.
     */
    private function passTime(int $days): void
    {
        $this->elapsed += $days * self::DAY;
        $this->directory->shiftBack($days * self::DAY);
    }

    private function runPass(bool $dryRun = false): RunReport
    {
        return $this->runner()->run('cron', $dryRun);
    }

    private function runner(): LifecycleRunner
    {
        $context = new StageContext($this->config(), new NullLogger(), new JournalEntryMapper());

        return new LifecycleRunner(
            $this->config(),
            $this->directory,
            $this->journal(),
            new NullLogger(),
            new JournalEntryMapper(),
            new LifecycleEventDispatcher($this->events, new NullLogger()),
            [
                'warn' => new WarnInactiveUsers(
                    $context,
                    $this->directory,
                    $this->journal(),
                    $this->inactivity(),
                    $this->transition()
                ),
                'deactivate' => new DeactivateInactiveUsers(
                    $context,
                    $this->directory,
                    $this->transition()
                ),
                'delete' => new DeleteDeactivatedUsers(
                    $context,
                    $this->directory,
                    $this->journal(),
                    $this->transition()
                ),
            ]
        );
    }

    /**
     * The one object all three stages act through, wired as `di.xml` wires it.
     */
    private function transition(): AccountTransition
    {
        return $this->transitionInstance ??= new AccountTransition(
            $this->config(),
            new JournalEntryMapper(),
            $this->directory,
            $this->inactivity(),
            $this->protection(),
            $this->sessions(),
            $this->notifier()
        );
    }

    private function inactivity(): InactivityPolicy
    {
        return new InactivityPolicy($this->config());
    }

    private function protection(): ProtectionPolicy
    {
        return new ProtectionPolicy($this->config(), $this->directory);
    }

    /**
     * The journal, shared across every stage and every pass.
     */
    private function journal(): LifecycleJournalInterface
    {
        return $this->journalInstance ??= new class ($this) implements LifecycleJournalInterface {
            public function __construct(private readonly RetirementJourneyTest $test)
            {
            }

            /**
             * @param JournalEntry[] $entries
             */
            public function recordAll(array $entries): void
            {
                $this->test->recordJournal($entries);
            }

            /**
             * @param  int[] $userIds
             * @return array<int, int>
             */
            public function getDeactivatedAt(array $userIds): array
            {
                return $this->test->lastActionAt($userIds, JournalEntry::ACTION_DEACTIVATED);
            }

            /**
             * @param  int[] $userIds
             * @return array<int, int>
             */
            public function getWarnedAt(array $userIds): array
            {
                return $this->test->lastActionAt($userIds, JournalEntry::ACTION_WARNED);
            }

            public function prune(int $olderThanTimestamp): int
            {
                return 0;
            }
        };
    }

    private ?LifecycleJournalInterface $journalInstance = null;

    /**
     * @param JournalEntry[] $entries
     *
     * @internal Called by the journal double.
     */
    public function recordJournal(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->journal[] = $entry;
        }
    }

    /**
     * @param  int[] $userIds
     * @return array<int, int> User id => when the action last happened.
     *
     * @internal Called by the journal double.
     */
    public function lastActionAt(array $userIds, string $action): array
    {
        $found = [];

        foreach ($this->journal as $entry) {
            if ($entry->isDryRun() || $entry->getAction() !== $action) {
                continue;
            }

            if (in_array($entry->getUserId(), array_map('intval', $userIds), true)) {
                // Aged along with the accounts, so an entry written "ninety
                // days ago" reads as ninety days old.
                $found[$entry->getUserId()] = $entry->getOccurredAt() - $this->elapsed;
            }
        }

        return $found;
    }

    private function notifier(): UserNotifierInterface
    {
        return new class ($this) implements UserNotifierInterface {
            public function __construct(private readonly RetirementJourneyTest $test)
            {
            }

            public function warn(Candidate $candidate, int $deactivateAt, ?int $storeId = null): bool
            {
                return $this->test->recordWarning($candidate->getUserId(), $deactivateAt);
            }
        };
    }

    /**
     * @internal Called by the notifier double.
     */
    public function recordWarning(int $userId, int $deactivateAt): bool
    {
        $this->warnings[] = ['userId' => $userId, 'deactivateAt' => $deactivateAt];

        return true;
    }

    private function sessions(): SessionTerminatorInterface
    {
        return new class ($this) implements SessionTerminatorInterface {
            public function __construct(private readonly RetirementJourneyTest $test)
            {
            }

            public function terminateFor(int $userId): int
            {
                return $this->test->recordTermination($userId);
            }
        };
    }

    /**
     * @internal Called by the session terminator double.
     */
    public function recordTermination(int $userId): int
    {
        $this->terminated[] = $userId;

        return 1;
    }

    private function config(): Config
    {
        return new Config(new ArrayScopeConfig($this->settings), self::SECTION);
    }

    private function activeAdmin(
        int $userId,
        string $username,
        ?int $lastLoginDaysAgo,
        int $createdDaysAgo = 500
    ): void {
        $this->directory->add(new Candidate(
            $userId,
            $username,
            $username . '@example.test',
            ucfirst(str_replace('-', ' ', $username)),
            true,
            $lastLoginDaysAgo === null ? null : $this->now - ($lastLoginDaysAgo * self::DAY),
            $this->now - ($createdDaysAgo * self::DAY)
        ));
    }

    /**
     * @return string[]
     */
    private function journalActionsFor(int $userId): array
    {
        $actions = [];

        foreach ($this->journal as $entry) {
            if ($entry->getUserId() === $userId) {
                $actions[] = $entry->getAction();
            }
        }

        return array_values(array_unique($actions));
    }
}
