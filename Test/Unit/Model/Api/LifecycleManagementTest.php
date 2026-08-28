<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api;

use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\Api\Converter\RunReportConverter;
use Commerce\AdminUserLifecycle\Model\Api\LifecycleManagement;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Model\Service\DeactivateInactiveUsers;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryJournal;
use Commerce\AdminUserLifecycle\Test\Support\RecordingEventManager;
use Commerce\AdminUserLifecycle\Test\Support\RecordingNotifier;
use Commerce\AdminUserLifecycle\Test\Support\RecordingWriter;
use Commerce\AdminUserLifecycle\Test\Support\TransitionBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LifecycleManagementTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;

    private InMemoryAdminUserFinder $finder;

    private InMemoryJournal $journal;

    private RecordingWriter $writer;

    private RecordingNotifier $notifier;

    private RecordingEventManager $events;

    private int $now;

    protected function setUp(): void
    {
        $this->finder = new InMemoryAdminUserFinder([]);
        $this->journal = new InMemoryJournal();
        $this->writer = new RecordingWriter();
        $this->notifier = new RecordingNotifier();
        $this->events = new RecordingEventManager();
        $this->now = time();

        // Enough administrators that the floor is not what every test is
        // measuring; the one test about the floor takes them away again.
        for ($id = 901; $id <= 903; $id++) {
            $this->finder->replace($this->candidate($id, lastLoginDaysAgo: 0));
        }
    }

    // --- the guards ---------------------------------------------------------

    /**
     * The refusal is an error rather than a quiet downgrade to a simulation.
     */
    public function testALiveRequestIsRefusedWhileTheModuleIsSwitchedOff(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/switched off/');

        $this->management(['general/enabled' => '0'])->deactivate(1, dryRun: false);
    }

    public function testASimulationIsStillAnsweredWhileTheModuleIsSwitchedOff(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $result = $this->management(['general/enabled' => '0'])->deactivate(1);

        $this->assertTrue($result->isDryRun());
        $this->assertFalse($result->isApplied());
        $this->assertSame([], $this->writer->deactivated);
    }

    /**
     * A store that has left deletion off has said what it wants.
     */
    public function testTheApiCannotDoWhatTheConfigurationRefusesToDo(): void
    {
        $this->finder->replace($this->candidate(1, active: false));
        $this->recordDeactivation(1, daysAgo: 400);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/"delete" stage is switched off/');

        $this->management(['delete/enabled' => '0'])->delete(1, dryRun: false);
    }

    public function testAnAccountThatIsNotThereIsSaidToBeMissing(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessageMatches('/No admin account with id 404/');

        $this->management()->deactivate(404);
    }

    // --- deactivation -------------------------------------------------------

    public function testADormantAccountIsDeactivatedRecordedAndAnnounced(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $result = $this->management()->deactivate(1, dryRun: false);

        $this->assertTrue($result->isApplied());
        $this->assertSame(JournalEntry::ACTION_DEACTIVATED, $result->getAction());
        $this->assertSame([1], $this->writer->deactivated);
        $this->assertCount(1, $this->journal->entries);
        $this->assertSame(JournalEntry::ACTOR_API, $this->journal->entries[0]->getActor());
        $this->assertSame(
            [LifecycleEventDispatcher::EVENT_DEACTIVATED],
            $this->events->names()
        );
    }

    /**
     * The whole reason the rules live on this side of the boundary.
     */
    public function testTheAdministratorFloorStopsTheApiExactlyAsItStopsTheCronJob(): void
    {
        $this->finder = new InMemoryAdminUserFinder([]);
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));
        $this->finder->replace($this->candidate(2, lastLoginDaysAgo: 0));

        $result = $this->management(['protect/min_active_admins' => '2'])->deactivate(1, dryRun: false);

        $this->assertFalse($result->isApplied());
        $this->assertSame(JournalEntry::ACTION_SKIPPED, $result->getAction());
        $this->assertSame(ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS, $result->getReason());
        $this->assertSame([], $this->writer->deactivated);
    }

    public function testAnAccountThatIsNotDormantEnoughIsRefusedRatherThanRetiredOnDemand(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 3));

        $result = $this->management()->deactivate(1, dryRun: false);

        $this->assertFalse($result->isApplied());
        $this->assertSame('not dormant long enough', $result->getReason());
        $this->assertSame([], $this->writer->deactivated);
    }

    /**
     * A refusal is an answer, not an error: an application that receives one
     * has to record it rather than retry it.
     */
    public function testARefusalIsRecordedInTheJournalAndAnnouncedToNobody(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 3));

        $this->management()->deactivate(1, dryRun: false);

        $this->assertCount(1, $this->journal->entries);
        $this->assertSame(JournalEntry::ACTION_SKIPPED, $this->journal->entries[0]->getAction());
        $this->assertSame([], $this->events->dispatched);
    }

    public function testASimulatedDeactivationChangesNothingAndTellsNobody(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $result = $this->management()->deactivate(1);

        $this->assertFalse($result->isApplied());
        $this->assertTrue($result->isDryRun());
        $this->assertSame(JournalEntry::ACTION_DEACTIVATED, $result->getAction());
        $this->assertSame([], $this->writer->deactivated);
        $this->assertSame([], $this->events->dispatched);
        $this->assertTrue($this->journal->entries[0]->isDryRun());
    }

    // --- deletion -----------------------------------------------------------

    public function testAnInactiveAccountWithNoRecordIsAdoptedRatherThanDeleted(): void
    {
        $this->finder->replace($this->candidate(1, active: false));

        $result = $this->management()->delete(1, dryRun: false);

        $this->assertSame(JournalEntry::ACTION_ADOPTED, $result->getAction());
        $this->assertTrue($result->isApplied(), 'The deletion clock now exists, which is a change.');
        $this->assertSame([], $this->writer->deleted);
        $this->assertSame([], $this->events->names(), 'Nothing was deleted, so nothing is announced.');
    }

    public function testAnAccountDeactivatedLongEnoughAgoIsDeletedAndAnnounced(): void
    {
        $this->finder->replace($this->candidate(1, active: false));
        $this->recordDeactivation(1, daysAgo: 400);

        $result = $this->management()->delete(1, dryRun: false);

        $this->assertTrue($result->isApplied());
        $this->assertSame([1], $this->writer->deleted);
        $this->assertSame([LifecycleEventDispatcher::EVENT_DELETED], $this->events->names());
    }

    public function testAnAccountStillInsideTheDeletionWindowIsRefusedWithTheDateItFallsDue(): void
    {
        $this->finder->replace($this->candidate(1, active: false));
        $this->recordDeactivation(1, daysAgo: 10);

        $result = $this->management()->delete(1, dryRun: false);

        $this->assertFalse($result->isApplied());
        $this->assertStringContainsString('not due until', $result->getReason());
        $this->assertSame([], $this->writer->deleted);
    }

    // --- warning ------------------------------------------------------------

    public function testAnAccountInsideTheNoticeWindowIsWarnedOnceAndOnlyOnce(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 85));
        $management = $this->management();

        $first = $management->warn(1, dryRun: false);
        $second = $management->warn(1, dryRun: false);

        $this->assertTrue($first->isApplied());
        $this->assertFalse($second->isApplied());
        $this->assertSame('already warned about this deactivation', $second->getReason());
        $this->assertCount(1, $this->notifier->warned);
    }

    public function testAnAccountNowhereNearDeactivationIsNotWarned(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 3));

        $result = $this->management()->warn(1, dryRun: false);

        $this->assertFalse($result->isApplied());
        $this->assertSame('not inside the notice window', $result->getReason());
        $this->assertSame([], $this->notifier->warned);
    }

    // --- the whole pass -----------------------------------------------------

    public function testAPassStartedOverTheApiIsRecordedAsTheApisWork(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $report = $this->management()->run(dryRun: false);

        $this->assertSame(JournalEntry::ACTOR_API, $report->getActor());
        $this->assertFalse($report->isDryRun());
        $this->assertTrue($report->hasChanges());
        $this->assertSame([1], $this->writer->deactivated);
        $this->assertSame(JournalEntry::ACTOR_API, $this->journal->entries[0]->getActor());
    }

    public function testALivePassIsRefusedWhileTheModuleIsSwitchedOff(): void
    {
        $this->expectException(LocalizedException::class);

        $this->management(['general/enabled' => '0'])->run(dryRun: false);
    }

    public function testTheReportSaysWhichStagesRanAndWhichAreTurnedOff(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $stages = $this->management()->run()->getStages();

        $this->assertSame(['deactivate'], array_map(static fn ($stage): string => $stage->getStage(), $stages));
        $this->assertTrue($stages[0]->isEnabled());
        $this->assertSame(1, $stages[0]->getActed());
    }

    // --- recording ----------------------------------------------------------

    /**
     * The account is switched off whatever the journal does.
     */
    public function testAJournalThatCannotBeWrittenDoesNotUndoWhatAlreadyHappened(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));
        $this->journal->throwOnWrite = true;

        $result = $this->management()->deactivate(1, dryRun: false);

        $this->assertTrue($result->isApplied());
        $this->assertSame([1], $this->writer->deactivated);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function management(array $overrides = []): LifecycleManagement
    {
        $config = $this->config($overrides);
        $transition = TransitionBuilder::build($config, $this->writer, $this->notifier);
        $instant = new Instant();
        $events = new LifecycleEventDispatcher($this->events, new NullLogger());

        return new LifecycleManagement(
            $config,
            $this->finder,
            $this->journal,
            $transition,
            $this->runner($config, $transition, $events),
            $events,
            new RunReportConverter($instant, new JournalEntryConverter($instant)),
            $instant,
            new NullLogger()
        );
    }

    /**
     * One stage, because what this file is about is the boundary rather than
     * the pipeline - `RetirementJourneyTest` runs all three.
     */
    private function runner(
        Config $config,
        AccountTransition $transition,
        LifecycleEventDispatcher $events
    ): LifecycleRunner {
        $context = new StageContext($config, new NullLogger(), new JournalEntryMapper());

        return new LifecycleRunner(
            $config,
            $this->finder,
            $this->journal,
            new NullLogger(),
            new JournalEntryMapper(),
            $events,
            ['deactivate' => new DeactivateInactiveUsers($context, $this->finder, $transition)]
        );
    }

    private function candidate(int $userId, ?int $lastLoginDaysAgo = 200, bool $active = true): Candidate
    {
        return new Candidate(
            $userId,
            'user' . $userId,
            sprintf('user%d@example.com', $userId),
            'A Person',
            $active,
            $lastLoginDaysAgo === null ? null : $this->now - ($lastLoginDaysAgo * self::DAY),
            $this->now - (900 * self::DAY),
            3
        );
    }

    private function recordDeactivation(int $userId, int $daysAgo): void
    {
        $this->journal->recordAll([
            new JournalEntry(
                $userId,
                'user' . $userId,
                sprintf('user%d@example.com', $userId),
                JournalEntry::ACTION_DEACTIVATED,
                'switched off',
                JournalEntry::ACTOR_CRON,
                false,
                $this->now - ($daysAgo * self::DAY)
            ),
        ]);
    }
}
