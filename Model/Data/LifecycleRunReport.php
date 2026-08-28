<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleStageReportInterface;

/**
 * A whole pass, on its way out over REST.
 */
class LifecycleRunReport implements LifecycleRunReportInterface
{
    /**
     * @param LifecycleStageReportInterface[] $stages
     */
    public function __construct(
        private readonly string $actor,
        private readonly bool $dryRun,
        private readonly string $startedAt,
        private readonly float $durationSeconds,
        private readonly int $activeAdminsBefore,
        private readonly bool $changes,
        private readonly bool $failures,
        private readonly array $stages
    ) {
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    public function getActiveAdminsBefore(): int
    {
        return $this->activeAdminsBefore;
    }

    public function hasChanges(): bool
    {
        return $this->changes;
    }

    public function hasFailures(): bool
    {
        return $this->failures;
    }

    /**
     * @return LifecycleStageReportInterface[]
     */
    public function getStages(): array
    {
        return $this->stages;
    }
}
