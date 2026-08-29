<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleStageReportInterface;

/**
 * One stage of a pass, on its way out over REST.
 */
class LifecycleStageReport implements LifecycleStageReportInterface
{
    /**
     * @param LifecycleJournalEntryInterface[] $entries
     */
    public function __construct(
        private readonly string $stage,
        private readonly bool $enabled,
        private readonly int $examined,
        private readonly int $acted,
        private readonly int $skipped,
        private readonly int $failed,
        private readonly array $entries
    ) {
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getExamined(): int
    {
        return $this->examined;
    }

    public function getActed(): int
    {
        return $this->acted;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * @return LifecycleJournalEntryInterface[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
