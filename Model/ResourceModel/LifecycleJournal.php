<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Api\JournalQueryInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * The journal table, read and appended.
 */
class LifecycleJournal implements LifecycleJournalInterface, JournalQueryInterface
{
    public const TABLE = 'commerce_adminuser_lifecycle';

    /**
     * Rows deleted per statement while pruning.
     */
    private const PRUNE_BATCH_SIZE = 5000;

    /**
     * A backstop on `getEntries`, not the page size anyone should ask for.
     */
    private const MAX_PAGE_SIZE = 1000;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function recordAll(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = $entry->toRow();
        }

        // One statement per pass, so a failure cannot leave a partial audit
        // trail.
        $this->resource->getConnection()->insertMultiple($this->tableName(), $rows);
    }

    /**
     * @inheritDoc
     */
    public function getDeactivatedAt(array $userIds): array
    {
        return $this->latestByAction(
            $userIds,
            [JournalEntry::ACTION_DEACTIVATED, JournalEntry::ACTION_ADOPTED]
        );
    }

    /**
     * @inheritDoc
     */
    public function getWarnedAt(array $userIds): array
    {
        return $this->latestByAction($userIds, [JournalEntry::ACTION_WARNED]);
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
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->tableName())
            // Keyset paging on the primary key.
            ->where('entry_id > ?', max(0, $afterEntryId))
            ->order('entry_id ' . Select::SQL_ASC)
            ->limit(max(1, min(self::MAX_PAGE_SIZE, $limit)));

        if ($userId !== null) {
            $select->where('user_id = ?', $userId);
        }

        if ($action !== null && $action !== '') {
            $select->where('action = ?', $action);
        }

        if ($since !== null) {
            $select->where('occurred_at >= ?', gmdate('Y-m-d H:i:s', $since));
        }

        if (!$includeSimulated) {
            $select->where('dry_run = ?', 0);
        }

        $entries = [];

        foreach ($connection->fetchAll($select) as $row) {
            $entries[] = $this->toEntry($row);
        }

        return $entries;
    }

    /**
     * @inheritDoc
     */
    public function prune(int $olderThanTimestamp): int
    {
        $connection = $this->resource->getConnection();

        // Zend_Db's delete() cannot express a LIMIT, so this is hand-written.
        $sql = sprintf(
            'DELETE FROM %s WHERE occurred_at < ? LIMIT %d',
            $connection->quoteIdentifier($this->tableName()),
            self::PRUNE_BATCH_SIZE
        );

        $cutoff = gmdate('Y-m-d H:i:s', $olderThanTimestamp);
        $deleted = 0;

        do {
            $batch = (int) $connection->query($sql, [$cutoff])->rowCount();
            $deleted += $batch;
        } while ($batch === self::PRUNE_BATCH_SIZE);

        return $deleted;
    }

    /**
     * Most recent occurrence of any of $actions, per user.
     *
     * @param int[] $userIds
     * @param string[] $actions
     * @return array<int, int>
     */
    private function latestByAction(array $userIds, array $actions): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($ids === [] || $actions === []) {
            return [];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->tableName(), ['user_id', 'latest' => 'MAX(occurred_at)'])
            ->where('user_id IN (?)', $ids)
            ->where('action IN (?)', $actions)
            // Dry-run rows are excluded, so a simulated pass cannot authorise a
            // later real deletion.
            ->where('dry_run = ?', 0)
            ->group('user_id');

        $latest = [];

        foreach ($connection->fetchAll($select) as $row) {
            $timestamp = strtotime((string) $row['latest'] . ' UTC');

            if ($timestamp !== false) {
                $latest[(int) $row['user_id']] = $timestamp;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, string|int|null> $row
     */
    private function toEntry(array $row): JournalEntry
    {
        $occurredAt = strtotime((string) $row['occurred_at'] . ' UTC');

        return new JournalEntry(
            (int) $row['user_id'],
            (string) ($row['username'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) $row['action'],
            (string) ($row['reason'] ?? ''),
            (string) $row['actor'],
            (bool) $row['dry_run'],
            $occurredAt === false ? 0 : $occurredAt,
            (int) $row['entry_id']
        );
    }

    private function tableName(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
