<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Api\LifecycleStageInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryJournal;
use Commerce\AdminUserLifecycle\Test\Support\RecordingEventManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class LifecycleRunnerTest extends TestCase
{
    use ShippedConfig;

    private InMemoryJournal $journal;

    private RecordingEventManager $events;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournal();
        $this->events = new RecordingEventManager();
    }

    public function testEveryStageRunsInTheOrderItWasRegistered(): void
    {
        $order = [];
        $report = $this->runner([
            'warn' => $this->stage('warn', $order),
            'deactivate' => $this->stage('deactivate', $order),
            'delete' => $this->stage('delete', $order),
        ])->run(JournalEntry::ACTOR_CRON);

        $this->assertSame(['warn', 'deactivate', 'delete'], $order);
        $this->assertCount(3, $report->getStages());
    }

    /**
     * The stages are independent controls.
     */
    public function testAThrowingStageDoesNotStopTheOnesAfterIt(): void
    {
        $order = [];
        $report = $this->runner([
            'warn' => $this->explodingStage('warn'),
            'deactivate' => $this->stage('deactivate', $order),
        ])->run(JournalEntry::ACTOR_CRON);

        $this->assertSame(['deactivate'], $order);
        $this->assertTrue($report->hasFailures());
        $this->assertStringContainsString(
            'stage "warn" threw',
            $report->getStages()[0]->getFailed()[0]->getReason()
        );
    }

    public function testEveryEntryFromEveryStageReachesTheJournalInOneWrite(): void
    {
        $order = [];
        $this->runner([
            'warn' => $this->stage('warn', $order, acted: 2),
            'deactivate' => $this->stage('deactivate', $order, acted: 3),
        ])->run(JournalEntry::ACTOR_CRON);

        $this->assertCount(5, $this->journal->entries);
    }

    /**
     * A failing journal insert must not also destroy the report of what the
     * pass did.
     */
    public function testAFailingJournalWriteDoesNotLoseTheReport(): void
    {
        $this->journal->throwOnWrite = true;
        $order = [];

        $report = $this->runner(['deactivate' => $this->stage('deactivate', $order, acted: 1)])
            ->run(JournalEntry::ACTOR_CRON);

        $this->assertTrue($report->hasChanges());
        $this->assertCount(1, $report->getAllEntries());
    }

    public function testTheConfiguredDryRunIsUsedWhenTheCallerDoesNotOverrideIt(): void
    {
        $order = [];
        $runner = $this->runner(['warn' => $this->stage('warn', $order)], ['general/dry_run' => '1']);

        $this->assertTrue($runner->run(JournalEntry::ACTOR_CRON)->isDryRun());
    }

    public function testACallerCanForceALiveRunOverADryRunDefault(): void
    {
        $order = [];
        $runner = $this->runner(['warn' => $this->stage('warn', $order)], ['general/dry_run' => '1']);

        $this->assertFalse($runner->run(JournalEntry::ACTOR_CLI, false)->isDryRun());
    }

    public function testTheActiveAdministratorCountIsCapturedBeforeThePass(): void
    {
        $order = [];
        $finder = new InMemoryAdminUserFinder([
            $this->candidate(1, true),
            $this->candidate(2, true),
            $this->candidate(3, false),
        ]);

        $report = $this->runner(['warn' => $this->stage('warn', $order)], [], $finder)
            ->run(JournalEntry::ACTOR_CRON);

        $this->assertSame(2, $report->getActiveAdminsBefore());
    }

    /**
     * @param array<string, LifecycleStageInterface> $stages
     * @param array<string, string> $overrides
     */
    private function runner(
        array $stages,
        array $overrides = [],
        ?InMemoryAdminUserFinder $finder = null
    ): LifecycleRunner {
        return new LifecycleRunner(
            $this->config($overrides),
            $finder ?? new InMemoryAdminUserFinder([]),
            $this->journal,
            new NullLogger(),
            new JournalEntryMapper(),
            new LifecycleEventDispatcher($this->events, new NullLogger()),
            $stages
        );
    }

    /**
     * @param string[] $order
     */
    private function stage(string $name, array &$order, int $acted = 0): LifecycleStageInterface
    {
        return new class ($name, $order, $acted) implements LifecycleStageInterface {
            /**
             * @param string[] $order
             */
            public function __construct(
                private readonly string $name,
                private array &$order,
                private readonly int $acted
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function execute(RunContext $context): StageResult
            {
                $this->order[] = $this->name;
                $entries = [];

                for ($index = 0; $index < $this->acted; $index++) {
                    $entries[] = new JournalEntry(
                        $index + 1,
                        'user' . $index,
                        'u@example.test',
                        JournalEntry::ACTION_DEACTIVATED,
                        'dormant',
                        $context->getActor(),
                        $context->isDryRun(),
                        $context->getNow()
                    );
                }

                return new StageResult(
                    true,
                    $this->name,
                    $entries,
                    [],
                    [],
                    $this->acted
                );
            }
        };
    }

    private function explodingStage(string $name): LifecycleStageInterface
    {
        return new class ($name) implements LifecycleStageInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function execute(RunContext $context): StageResult
            {
                throw new RuntimeException('the finder is unavailable');
            }
        };
    }

    private function candidate(int $userId, bool $active): Candidate
    {
        return new Candidate($userId, 'user' . $userId, 'u@example.test', 'User', $active, 1_000, 500);
    }
}
