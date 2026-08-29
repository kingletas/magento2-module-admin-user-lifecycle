<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Support;

use Commerce\AdminUserLifecycle\Api\JournalQueryInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;

/**
 * The journal in an array, including the rule that dry-run entries do not count
 * as evidence of a real deactivation.
 */
class InMemoryJournal implements LifecycleJournalInterface, JournalQueryInterface
{
    private int $nextEntryId = 1;

    /** @var JournalEntry[] */
    public array $entries = [];

    public int $pruned = 0;

    public bool $throwOnWrite = false;

    /**
     * @inheritDoc
     */
    public function recordAll(array $entries): void
    {
        if ($this->throwOnWrite) {
            throw new \RuntimeException('journal is unavailable');
        }

        foreach ($entries as $entry) {
            $this->entries[] = new JournalEntry(
                $entry->getUserId(),
                $entry->getUsername(),
                $entry->getEmail(),
                $entry->getAction(),
                $entry->getReason(),
                $entry->getActor(),
                $entry->isDryRun(),
                $entry->getOccurredAt(),
                $entry->getEntryId() ?? $this->nextEntryId++
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getEntries(
        ?int $userId = null,
        ?string $action = null,
        ?int $since = null,
        bool $includeSimulated = false,
        int $limit = 200,
        int $afterEntryId = 0
    ): array {
        $matching = [];

        foreach ($this->entries as $entry) {
            if (($entry->getEntryId() ?? 0) <= $afterEntryId
                || ($userId !== null && $entry->getUserId() !== $userId)
                || ($action !== null && $action !== '' && $entry->getAction() !== $action)
                || ($since !== null && $entry->getOccurredAt() < $since)
                || (!$includeSimulated && $entry->isDryRun())
            ) {
                continue;
            }

            $matching[] = $entry;
        }

        usort(
            $matching,
            static fn (JournalEntry $one, JournalEntry $two): int
                => ($one->getEntryId() ?? 0) <=> ($two->getEntryId() ?? 0)
        );

        return array_slice($matching, 0, max(1, $limit));
    }

    /**
     * @inheritDoc
     */
    public function getDeactivatedAt(array $userIds): array
    {
        return $this->latest(
            $userIds,
            [JournalEntry::ACTION_DEACTIVATED, JournalEntry::ACTION_ADOPTED]
        );
    }

    /**
     * @inheritDoc
     */
    public function getWarnedAt(array $userIds): array
    {
        return $this->latest($userIds, [JournalEntry::ACTION_WARNED]);
    }

    /**
     * @inheritDoc
     */
    public function prune(int $olderThanTimestamp): int
    {
        $before = count($this->entries);

        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (JournalEntry $entry): bool => $entry->getOccurredAt() >= $olderThanTimestamp
        ));

        $this->pruned = $before - count($this->entries);

        return $this->pruned;
    }

    /**
     * @param int[] $userIds
     * @param string[] $actions
     * @return array<int, int>
     */
    private function latest(array $userIds, array $actions): array
    {
        $latest = [];

        foreach ($this->entries as $entry) {
            if ($entry->isDryRun()
                || !in_array($entry->getUserId(), $userIds, true)
                || !in_array($entry->getAction(), $actions, true)
            ) {
                continue;
            }

            $current = $latest[$entry->getUserId()] ?? 0;
            $latest[$entry->getUserId()] = max($current, $entry->getOccurredAt());
        }

        return $latest;
    }
}
