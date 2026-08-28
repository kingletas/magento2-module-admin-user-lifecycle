<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\JournalEntry;

/**
 * Append-only record of what the module did, and would have done.
 */
interface LifecycleJournalInterface
{
    /**
     * @param JournalEntry[] $entries
     */
    public function recordAll(array $entries): void;

    /**
     * When each of these users was most recently deactivated by, or adopted
     * into, this module.
     *
     * @param int[] $userIds
     * @return array<int, int> user id => UTC timestamp. Users with no such
     *                         entry are absent rather than zero, because
     *                         "never recorded" must not read as "long ago".
     */
    public function getDeactivatedAt(array $userIds): array;

    /**
     * When each of these users was most recently warned.
     *
     * @param int[] $userIds
     * @return array<int, int> user id => UTC timestamp.
     */
    public function getWarnedAt(array $userIds): array;

    /**
     * Drop entries older than the cutoff.
     *
     * @return int Rows removed.
     */
    public function prune(int $olderThanTimestamp): int;
}
