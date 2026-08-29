<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

/**
 * The audit journal, read from outside the store.
 */
interface LifecycleJournalReaderInterface
{
    /**
     * Journal entries, oldest first, paged by entry id.
     *
     * @param int|null $userId Only entries about this account.
     * @param string|null $action Only entries with this action.
     * @param string|null $since Only entries at or after this ISO-8601 UTC
     *                           instant.
     * @param bool $includeSimulated Whether dry-run entries are returned.
     *                               They are excluded by default: a simulated
     *                               pass is not a record of anything having
     *                               happened, and a report that mixes the two
     *                               overstates what the store did.
     * @param int $limit Rows per call.
     * @param int $afterEntryId Cursor: the last entry id of the previous call.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface[]
     *         Matching entries in entry-id order.
     * @throws \Magento\Framework\Exception\InputException When `since` is not a
     *         date this module can read, or the action is not one it records.
     */
    public function getEntries(
        ?int $userId = null,
        ?string $action = null,
        ?string $since = null,
        bool $includeSimulated = false,
        int $limit = 200,
        int $afterEntryId = 0
    ): array;
}
