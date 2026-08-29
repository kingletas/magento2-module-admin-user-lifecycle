<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * What one stage did.
 */
class StageResult
{
    /**
     * @param bool $enabled Whether the stage ran at all. Distinct from "ran and
     *        found nothing" on purpose: a report showing a quiet deletion stage
     *        should say whether deletion is even turned on.
     * @param JournalEntry[] $acted
     * @param JournalEntry[] $skipped
     * @param JournalEntry[] $failed
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $stage,
        private readonly array $acted = [],
        private readonly array $skipped = [],
        private readonly array $failed = [],
        private readonly int $examined = 0
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

    /**
     * @return JournalEntry[]
     */
    public function getActed(): array
    {
        return $this->acted;
    }

    /**
     * @return JournalEntry[]
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return JournalEntry[]
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return JournalEntry[]
     */
    public function getAllEntries(): array
    {
        return array_merge($this->acted, $this->skipped, $this->failed);
    }

    public function getExamined(): int
    {
        return $this->examined;
    }

    public function hasChanges(): bool
    {
        return $this->acted !== [];
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    public function summarise(): string
    {
        if (!$this->enabled) {
            return sprintf('%s: disabled', $this->stage);
        }

        return sprintf(
            '%s: %d examined, %d acted on, %d protected, %d failed',
            $this->stage,
            $this->examined,
            count($this->acted),
            count($this->skipped),
            count($this->failed)
        );
    }
}
