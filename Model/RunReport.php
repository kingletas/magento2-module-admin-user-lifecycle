<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * The whole pass, ready to be mailed, logged or printed.
 */
class RunReport
{
    /**
     * @param StageResult[] $stages
     */
    public function __construct(
        private readonly RunContext $context,
        private readonly array $stages,
        private readonly int $activeAdminsBefore,
        private readonly float $durationSeconds
    ) {
    }

    public function getContext(): RunContext
    {
        return $this->context;
    }

    /**
     * @return StageResult[]
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    public function isDryRun(): bool
    {
        return $this->context->isDryRun();
    }

    public function getActiveAdminsBefore(): int
    {
        return $this->activeAdminsBefore;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function hasChanges(): bool
    {
        foreach ($this->stages as $stage) {
            if ($stage->hasChanges()) {
                return true;
            }
        }

        return false;
    }

    public function hasFailures(): bool
    {
        foreach ($this->stages as $stage) {
            if ($stage->hasFailures()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return JournalEntry[]
     */
    public function getAllEntries(): array
    {
        $entries = [];

        foreach ($this->stages as $stage) {
            $entries[] = $stage->getAllEntries();
        }

        return array_merge(...$entries);
    }

    /**
     * @return array<int, array{stage: string, enabled: bool, examined: int, acted: int,
     *                          skipped: int, failed: int, entries: JournalEntry[]}>
     */
    public function toRows(): array
    {
        $rows = [];

        foreach ($this->stages as $stage) {
            $rows[] = [
                'stage' => $stage->getStage(),
                'enabled' => $stage->isEnabled(),
                'examined' => $stage->getExamined(),
                'acted' => count($stage->getActed()),
                'skipped' => count($stage->getSkipped()),
                'failed' => count($stage->getFailed()),
                'entries' => $stage->getAllEntries(),
            ];
        }

        return $rows;
    }

    public function summarise(): string
    {
        $lines = [
            sprintf(
                'Admin user lifecycle run (%s%s): %d active administrators before the pass, %.2fs',
                $this->context->getActor(),
                $this->isDryRun() ? ', dry run' : '',
                $this->activeAdminsBefore,
                $this->durationSeconds
            ),
        ];

        foreach ($this->stages as $stage) {
            $lines[] = '  ' . $stage->summarise();
        }

        return implode("\n", $lines);
    }
}
