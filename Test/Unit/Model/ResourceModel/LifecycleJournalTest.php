<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\ResourceModel\LifecycleJournal;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LifecycleJournalTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private LifecycleJournal $journal;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        $this->journal = new LifecycleJournal($resource);
    }

    /**
     * One statement per pass, not per entry.
     */
    public function testAWholePassIsWrittenInOneStatement(): void
    {
        $this->connection->expects(self::once())
            ->method('insertMultiple')
            ->with(
                'pfx_' . LifecycleJournal::TABLE,
                self::callback(static fn (array $rows): bool => count($rows) === 3)
            );

        $this->journal->recordAll([$this->entry(1), $this->entry(2), $this->entry(3)]);
    }

    public function testAnEmptyPassWritesNothing(): void
    {
        $this->connection->expects(self::never())->method('insertMultiple');

        $this->journal->recordAll([]);
    }

    /**
     * A dry run records what it *would* have done.
     */
    public function testTheDeactivationLookupIgnoresDryRunRows(): void
    {
        $select = $this->expectSelect();
        $conditions = [];

        $select->method('where')
            ->willReturnCallback(function (string $condition, $value = null) use ($select, &$conditions): Select {
                $conditions[$condition] = $value;

                return $select;
            });

        $this->connection->method('fetchAll')->willReturn([]);

        $this->journal->getDeactivatedAt([1, 2]);

        self::assertArrayHasKey('dry_run = ?', $conditions);
        self::assertSame(0, $conditions['dry_run = ?']);
        self::assertSame(
            [JournalEntry::ACTION_DEACTIVATED, JournalEntry::ACTION_ADOPTED],
            $conditions['action IN (?)']
        );
    }

    /**
     * "Never recorded" and "recorded at the epoch" lead to opposite deletion
     * decisions, so a user with no entry must be absent rather than zero.
     */
    public function testUsersWithNoEntryAreAbsentRatherThanZero(): void
    {
        $this->expectSelect();
        $this->connection->method('fetchAll')->willReturn([
            ['user_id' => '2', 'latest' => '2026-01-01 00:00:00'],
        ]);

        $result = $this->journal->getDeactivatedAt([1, 2]);

        self::assertArrayNotHasKey(1, $result);
        self::assertSame(strtotime('2026-01-01 00:00:00 UTC'), $result[2]);
    }

    public function testNoQueryRunsForAnEmptyUserList(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertSame([], $this->journal->getDeactivatedAt([]));
        self::assertSame([], $this->journal->getWarnedAt([0, null ?? 0]));
    }

    /**
     * The delete is batched rather than one unbounded statement holding the
     * table.
     */
    public function testPruningLoopsUntilABatchComesBackShort(): void
    {
        $this->connection->method('quoteIdentifier')->willReturn('`pfx_journal`');

        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturnOnConsecutiveCalls(5000, 5000, 120);

        $this->connection->expects(self::exactly(3))->method('query')->willReturn($statement);

        self::assertSame(10120, $this->journal->prune(1_700_000_000));
    }

    private function expectSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);

        return $select;
    }

    private function entry(int $userId): JournalEntry
    {
        return new JournalEntry(
            $userId,
            'user' . $userId,
            'u@example.test',
            JournalEntry::ACTION_DEACTIVATED,
            'dormant',
            JournalEntry::ACTOR_CRON,
            false,
            1_760_000_000
        );
    }
}
