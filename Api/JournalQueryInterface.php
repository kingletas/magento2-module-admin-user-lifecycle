<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\JournalEntry;

/**
 * Reading the journal as rows.
 */
interface JournalQueryInterface
{
    /**
     * @param int|null $userId Only entries about this account.
     * @param string|null $action Only entries with this action.
     * @param int|null $since Only entries at or after this UTC timestamp.
     * @param bool $includeSimulated Whether dry-run rows are returned.
     * @param int $limit Rows per call.
     * @param int $afterEntryId Cursor: the last entry id of the previous call.
     * @return JournalEntry[] Entries in entry-id order, each carrying its id.
     */
    public function getEntries(
        ?int $userId = null,
        ?string $action = null,
        ?int $since = null,
        bool $includeSimulated = false,
        int $limit = 200,
        int $afterEntryId = 0
    ): array;
}
