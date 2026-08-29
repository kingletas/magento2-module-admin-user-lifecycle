<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Behaviour;

use Commerce\AdminUserLifecycle\Api\LifecycleCandidateProviderInterface;
use Commerce\AdminUserLifecycle\Model\Api\CandidateProvider;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\Api\Converter\RunReportConverter;
use Commerce\AdminUserLifecycle\Model\Api\JournalReader;
use Commerce\AdminUserLifecycle\Model\Api\LifecycleManagement;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Model\Service\DeactivateInactiveUsers;
use Commerce\AdminUserLifecycle\Model\Service\DeleteDeactivatedUsers;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\Service\WarnInactiveUsers;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryDirectory;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryJournal;
use Commerce\AdminUserLifecycle\Test\Support\RecordingEventManager;
use Commerce\AdminUserLifecycle\Test\Support\RecordingNotifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Retirement driven from outside the store.
 */
class OutOfProcessJourneyTest extends TestCase
{
    private const SECTION = 'commerce_adminusers';
    private const DAY = 86400;

    private InMemoryDirectory $directory;

    private InMemoryJournal $journal;

    private RecordingEventManager $events;

    private RecordingNotifier $notifier;

    /** @var array<string, string> */
    private array $settings = [];

    private int $now;

    private ?AccountTransition $transition = null;

    protected function setUp(): void
    {
        $this->directory = new InMemoryDirectory();
        $this->journal = new InMemoryJournal();
        $this->events = new RecordingEventManager();
        $this->notifier = new RecordingNotifier();
        $this->transition = null;
        $this->now = time();
        $this->settings = [
            self::SECTION . '/general/enabled' => '1',
            // Off: the schedule belongs to the application outside the store,
            // which is the arrangement this whole file is about.
            self::SECTION . '/general/cron_enabled' => '0',
            self::SECTION . '/general/dry_run' => '0',
            self::SECTION . '/general/batch_size' => '100',
            self::SECTION . '/warn/enabled' => '1',
            self::SECTION . '/warn/days_before' => '14',
            self::SECTION . '/deactivate/enabled' => '1',
            self::SECTION . '/deactivate/inactive_days' => '180',
            self::SECTION . '/deactivate/new_account_grace_days' => '30',
            self::SECTION . '/delete/enabled' => '1',
            self::SECTION . '/delete/deactivated_days' => '90',
            self::SECTION . '/protect/usernames' => 'break-glass',
            self::SECTION . '/protect/min_active_admins' => '2',
        ];

        $this->directory->add($this->account(1, 'dormant.dave', dormantDays: 200));
        $this->directory->add($this->account(2, 'break-glass', dormantDays: 400));
        $this->directory->add($this->account(3, 'active.alice', dormantDays: 0));
        $this->directory->add($this->account(4, 'active.ahmed', dormantDays: 0));
    }

    /**
     * The whole arrangement, end to end.
     */
    public function testAnApplicationOutsideTheStoreCanSeeWhatIsDueActOnItAndReconcile(): void
    {
        $due = $this->candidates(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertSame([1, 2], array_keys($due), 'Both dormant accounts are visible.');
        $this->assertNull($due[1]->getBlockedReason());
        $this->assertSame(
            ProtectionPolicy::REASON_PROTECTED_USERNAME,
            $due[2]->getBlockedReason(),
            'The store says why it will refuse before anybody asks it to.'
        );

        $applied = $this->management()->deactivate(1, dryRun: false);
        $refused = $this->management()->deactivate(2, dryRun: false);

        $this->assertTrue($applied->isApplied());
        $this->assertFalse($refused->isApplied());
        $this->assertSame([1], $this->directory->deactivated);
        $this->assertTrue($this->directory->isActive(2), 'The protected account was never touched.');

        $entries = $this->reader()->getEntries();

        $this->assertSame(
            [JournalEntry::ACTION_DEACTIVATED, JournalEntry::ACTION_SKIPPED],
            array_map(static fn ($entry): string => $entry->getAction(), $entries),
            'The refusal is in the record, not only the change.'
        );
        $this->assertSame(JournalEntry::ACTOR_API, $entries[0]->getActor());
        $this->assertSame(
            [LifecycleEventDispatcher::EVENT_DEACTIVATED],
            $this->events->names(),
            'One account changed, so one thing was announced.'
        );
    }

    /**
     * A deactivation performed over the API is what a later deletion is
     * measured from.
     */
    public function testAnAccountRetiredThroughTheApiBecomesDeletableOnTheApisOwnRecord(): void
    {
        $this->management()->deactivate(1, dryRun: false);

        $this->passTime(30);
        $soon = $this->candidates(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertFalse($soon[1]->isDue());
        $this->assertNotNull($soon[1]->getDeactivatedAt(), 'The clock started when the API switched it off.');

        $refused = $this->management()->delete(1, dryRun: false);

        $this->assertFalse($refused->isApplied());
        $this->assertStringContainsString('not due until', $refused->getReason());

        $this->passTime(70);
        $overdue = $this->candidates(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertTrue($overdue[1]->isDue());

        $deleted = $this->management()->delete(1, dryRun: false);

        $this->assertTrue($deleted->isApplied());
        $this->assertFalse($this->directory->exists(1));
        $this->assertContains(LifecycleEventDispatcher::EVENT_DELETED, $this->events->names());
    }

    /**
     * A simulated deactivation records what it would have done and nothing
     * more.
     */
    public function testASimulatedRetirementNeverBecomesGroundsForADeletion(): void
    {
        $this->management()->deactivate(1);

        $this->assertSame([], $this->directory->deactivated);

        $this->passTime(200);

        $this->assertSame(
            [],
            $this->candidates(LifecycleCandidateProviderInterface::STAGE_DELETE),
            'The account is still active, so it is not a deletion candidate at all.'
        );
        $this->assertSame([], $this->events->dispatched);
    }

    /**
     * Turning the module off has to stop the API too, or "off" means "off for
     * cron", which is not what anybody reading the setting believes.
     */
    public function testSwitchingTheModuleOffStopsTheApiAndNotJustTheSchedule(): void
    {
        $this->settings[self::SECTION . '/general/enabled'] = '0';

        // Still answerable: seeing what would happen is the point of installing
        // this module before switching it on.
        $this->assertNotSame([], $this->candidates(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE));

        $this->expectException(LocalizedException::class);

        $this->management()->deactivate(1, dryRun: false);
    }

    /**
     * The same store, driven both ways, has to reach the same verdict.
     */
    public function testAPassStartedOverRestActsOnExactlyWhatTheListingOffered(): void
    {
        $offered = array_keys($this->candidates(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE));

        $report = $this->management()->run(dryRun: false);

        $this->assertSame([1, 2], $offered);
        $this->assertSame([1], $this->directory->deactivated, 'The protected account was offered and refused.');
        $this->assertTrue($report->hasChanges());
        $this->assertFalse($report->hasFailures());

        $stages = [];

        foreach ($report->getStages() as $stage) {
            $stages[$stage->getStage()] = $stage;
        }

        $this->assertSame(1, $stages['deactivate']->getActed());
        $this->assertSame(1, $stages['deactivate']->getSkipped());
    }

    /**
     * @return array<int, \Commerce\AdminUserLifecycle\Api\Data\LifecycleCandidateInterface>
     */
    private function candidates(string $stage): array
    {
        $byUserId = [];

        foreach ($this->provider()->getList($stage) as $candidate) {
            $byUserId[$candidate->getUserId()] = $candidate;
        }

        return $byUserId;
    }

    /**
     * Move the store's history further into the past.
     */
    private function passTime(int $days): void
    {
        $seconds = $days * self::DAY;
        $this->directory->shiftBack($seconds);

        $aged = [];

        foreach ($this->journal->entries as $entry) {
            $aged[] = new JournalEntry(
                $entry->getUserId(),
                $entry->getUsername(),
                $entry->getEmail(),
                $entry->getAction(),
                $entry->getReason(),
                $entry->getActor(),
                $entry->isDryRun(),
                $entry->getOccurredAt() - $seconds,
                $entry->getEntryId()
            );
        }

        $this->journal->entries = $aged;
    }

    private function management(): LifecycleManagement
    {
        $instant = new Instant();

        return new LifecycleManagement(
            $this->config(),
            $this->directory,
            $this->journal,
            $this->transition(),
            $this->runner(),
            $this->dispatcher(),
            new RunReportConverter($instant, new JournalEntryConverter($instant)),
            $instant,
            new NullLogger()
        );
    }

    private function provider(): CandidateProvider
    {
        return new CandidateProvider(
            $this->config(),
            $this->directory,
            $this->journal,
            new InactivityPolicy($this->config()),
            new ProtectionPolicy($this->config()),
            new Instant()
        );
    }

    private function reader(): JournalReader
    {
        $instant = new Instant();

        return new JournalReader(
            $this->config(),
            $this->journal,
            new JournalEntryConverter($instant),
            $instant
        );
    }

    private function runner(): LifecycleRunner
    {
        $context = new StageContext($this->config(), new NullLogger(), new JournalEntryMapper());

        return new LifecycleRunner(
            $this->config(),
            $this->directory,
            $this->journal,
            new NullLogger(),
            new JournalEntryMapper(),
            $this->dispatcher(),
            [
                'warn' => new WarnInactiveUsers(
                    $context,
                    $this->directory,
                    $this->journal,
                    new InactivityPolicy($this->config()),
                    $this->transition()
                ),
                'deactivate' => new DeactivateInactiveUsers($context, $this->directory, $this->transition()),
                'delete' => new DeleteDeactivatedUsers(
                    $context,
                    $this->directory,
                    $this->journal,
                    $this->transition()
                ),
            ]
        );
    }

    /**
     * Shared across every call, like the journal and for the same reason: what
     * one request wrote is what the next one has to see.
     */
    private function transition(): AccountTransition
    {
        return $this->transition ??= new AccountTransition(
            $this->config(),
            new JournalEntryMapper(),
            $this->directory,
            new InactivityPolicy($this->config()),
            new ProtectionPolicy($this->config()),
            $this->notifier,
            $this->notifier
        );
    }

    private function dispatcher(): LifecycleEventDispatcher
    {
        return new LifecycleEventDispatcher($this->events, new NullLogger());
    }

    private function config(): Config
    {
        return new Config($this->scopeConfig($this->settings), self::SECTION);
    }

    private function account(int $userId, string $username, int $dormantDays): Candidate
    {
        return new Candidate(
            $userId,
            $username,
            $username . '@example.com',
            'A Person',
            true,
            $this->now - ($dormantDays * self::DAY),
            $this->now - (900 * self::DAY),
            3
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
