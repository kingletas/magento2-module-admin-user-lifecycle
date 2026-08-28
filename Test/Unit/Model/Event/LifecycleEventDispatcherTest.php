<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Event;

use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingEventManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LifecycleEventDispatcherTest extends TestCase
{
    private RecordingEventManager $events;

    protected function setUp(): void
    {
        $this->events = new RecordingEventManager();
    }

    public function testEachThingThatHappenedIsAnnouncedUnderItsOwnName(): void
    {
        $this->dispatcher()->announceAll([
            $this->entry(JournalEntry::ACTION_WARNED),
            $this->entry(JournalEntry::ACTION_DEACTIVATED),
            $this->entry(JournalEntry::ACTION_DELETED),
        ]);

        self::assertSame(
            [
                LifecycleEventDispatcher::EVENT_WARNED,
                LifecycleEventDispatcher::EVENT_DEACTIVATED,
                LifecycleEventDispatcher::EVENT_DELETED,
            ],
            $this->events->names()
        );
    }

    /**
     * The rule that keeps a subscriber trustworthy.
     */
    public function testADryRunAnnouncesNothing(): void
    {
        $this->dispatcher()->announceAll([
            $this->entry(JournalEntry::ACTION_DEACTIVATED, dryRun: true),
            $this->entry(JournalEntry::ACTION_DELETED, dryRun: true),
        ]);
        $this->dispatcher()->announceRun($this->context(dryRun: true), [JournalEntry::ACTION_DELETED => 1], 4);

        self::assertSame([], $this->events->dispatched);
    }

    /**
     * A protection rule firing is in the report and in the journal, where
     * somebody reads it once.
     */
    public function testARefusalIsNotAnnounced(): void
    {
        $this->dispatcher()->announceAll([
            $this->entry(JournalEntry::ACTION_SKIPPED),
            $this->entry(JournalEntry::ACTION_FAILED),
            $this->entry(JournalEntry::ACTION_ADOPTED),
        ]);

        self::assertSame([], $this->events->dispatched);
    }

    public function testThePayloadIsFlatScalarsWithTheDateSpelledOutInUtc(): void
    {
        $this->dispatcher()->announce($this->entry(JournalEntry::ACTION_DEACTIVATED));

        $payload = $this->events->payloadsFor(LifecycleEventDispatcher::EVENT_DEACTIVATED)[0];

        self::assertSame(
            [
                'user_id' => 7,
                'username' => 'dormant.user',
                'email' => 'dormant@example.com',
                'action' => 'deactivated',
                'reason' => 'no sign-in for 200 days',
                'actor' => 'api',
                'occurred_at' => '2026-08-27T14:30:00Z',
            ],
            $payload
        );

        foreach ($payload as $value) {
            self::assertIsScalar($value, 'A subscriber outside the store receives this as JSON.');
        }
    }

    /**
     * Dispatched whether or not anything changed, because a scheduler waits on
     * the pass finishing.
     */
    public function testAPassThatChangedNothingStillSaysItFinished(): void
    {
        $this->dispatcher()->announceRun($this->context(), [], 4);

        $payload = $this->events->payloadsFor(LifecycleEventDispatcher::EVENT_RUN_COMPLETED)[0];

        self::assertSame('api', $payload['actor']);
        self::assertSame(4, $payload['active_admins_before']);
        self::assertSame(0, $payload['deactivated']);
        self::assertSame(0, $payload['failed']);
    }

    public function testTheRunSummaryCountsEveryActionTheJournalRecorded(): void
    {
        $this->dispatcher()->announceRun(
            $this->context(),
            [
                JournalEntry::ACTION_WARNED => 3,
                JournalEntry::ACTION_DEACTIVATED => 2,
                JournalEntry::ACTION_SKIPPED => 1,
            ],
            9
        );

        $payload = $this->events->payloadsFor(LifecycleEventDispatcher::EVENT_RUN_COMPLETED)[0];

        self::assertSame(3, $payload['warned']);
        self::assertSame(2, $payload['deactivated']);
        self::assertSame(1, $payload['skipped']);
        self::assertSame(0, $payload['deleted']);
    }

    /**
     * An observer somebody else installed must not be able to undo a retirement
     * that has already happened, or stop the ones queued behind it.
     */
    public function testAnObserverThatThrowsDoesNotStopTheAnnouncementsAfterIt(): void
    {
        $this->events->explodeOn = [LifecycleEventDispatcher::EVENT_WARNED];

        $this->dispatcher()->announceAll([
            $this->entry(JournalEntry::ACTION_WARNED),
            $this->entry(JournalEntry::ACTION_DEACTIVATED),
        ]);

        self::assertSame([LifecycleEventDispatcher::EVENT_DEACTIVATED], $this->events->names());
    }

    private function dispatcher(): LifecycleEventDispatcher
    {
        return new LifecycleEventDispatcher($this->events, new NullLogger());
    }

    private function entry(string $action, bool $dryRun = false): JournalEntry
    {
        return new JournalEntry(
            7,
            'dormant.user',
            'dormant@example.com',
            $action,
            'no sign-in for 200 days',
            JournalEntry::ACTOR_API,
            $dryRun,
            1787841000
        );
    }

    private function context(bool $dryRun = false): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_API, $dryRun, 1787841000);
    }
}
