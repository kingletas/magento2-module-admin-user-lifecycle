<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleStageInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the stages in order and turns the pass into one report.
 */
class LifecycleRunner
{
    /**
     * @param LifecycleStageInterface[] $stages
     */
    public function __construct(
        private readonly Config $config,
        private readonly AdminUserFinderInterface $finder,
        private readonly LifecycleJournalInterface $journal,
        private readonly LoggerInterface $logger,
        private readonly JournalEntryMapper $entryMapper,
        private readonly LifecycleEventDispatcher $events,
        private readonly array $stages = []
    ) {
    }

    /**
     * @param bool|null $dryRunOverride Forces a dry run on, or off, for this
     *                                  pass only. Null uses the configuration.
     */
    public function run(string $actor, ?bool $dryRunOverride = null, ?int $storeId = null): RunReport
    {
        $startedAt = microtime(true);
        $dryRun = $dryRunOverride ?? $this->config->isDryRun($storeId);
        $context = new RunContext($actor, $dryRun, time(), $storeId);

        $activeBefore = $this->countActiveSafely();
        $results = [];

        foreach ($this->stages as $name => $stage) {
            $results[] = $this->runStage($stage, (string) $name, $context);
        }

        $report = new RunReport($context, $results, $activeBefore, microtime(true) - $startedAt);

        $this->writeJournal($report);
        // Announced after the journal, never before it.
        $this->announce($report);
        $this->logger->info($report->summarise());

        return $report;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isEnabled($storeId);
    }

    private function runStage(
        LifecycleStageInterface $stage,
        string $name,
        RunContext $context
    ): StageResult {
        try {
            return $stage->execute($context);
        } catch (Throwable $exception) {
            $this->logger->error(
                sprintf('Admin user lifecycle stage "%s" threw: %s', $name, $exception->getMessage()),
                ['exception' => $exception::class, 'trace' => $exception->getTraceAsString()]
            );

            return new StageResult(
                true,
                $name,
                [],
                [],
                [
                    $this->entryMapper->forRun(
                        JournalEntry::ACTION_FAILED,
                        sprintf('stage "%s" threw: %s', $name, $exception->getMessage()),
                        $context
                    ),
                ],
                0
            );
        }
    }

    /**
     * Recording must not be able to break a pass that has already happened.
     */
    private function writeJournal(RunReport $report): void
    {
        $entries = $report->getAllEntries();

        if ($entries === []) {
            return;
        }

        try {
            $this->journal->recordAll($entries);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin user lifecycle journal could not be written: ' . $exception->getMessage(),
                ['exception' => $exception::class, 'entries' => count($entries)]
            );
        }
    }

    private function announce(RunReport $report): void
    {
        $entries = $report->getAllEntries();
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry->getAction()] = ($counts[$entry->getAction()] ?? 0) + 1;
        }

        $this->events->announceAll($entries);
        $this->events->announceRun($report->getContext(), $counts, $report->getActiveAdminsBefore());
    }

    private function countActiveSafely(): int
    {
        try {
            return $this->finder->countActive();
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Could not count active administrators: ' . $exception->getMessage()
            );

            return 0;
        }
    }
}
