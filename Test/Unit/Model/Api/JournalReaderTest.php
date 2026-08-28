<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api;

use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\Api\JournalReader;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryJournal;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;

class JournalReaderTest extends TestCase
{
    use ShippedConfig;

    private InMemoryJournal $journal;

    private int $now;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournal();
        $this->now = time();
    }

    public function testTheJournalComesBackOldestFirstWithEveryFieldIntact(): void
    {
        $this->record(1, JournalEntry::ACTION_WARNED);
        $this->record(1, JournalEntry::ACTION_DEACTIVATED);

        $entries = $this->reader()->getEntries();

        $this->assertCount(2, $entries);
        $this->assertSame(JournalEntry::ACTION_WARNED, $entries[0]->getAction());
        $this->assertSame(JournalEntry::ACTION_DEACTIVATED, $entries[1]->getAction());
        $this->assertSame(1, $entries[0]->getUserId());
        $this->assertSame('user1', $entries[0]->getUsername());
    }

    /**
     * A dry run recorded what it *would* have done.
     */
    public function testSimulatedEntriesAreLeftOutUnlessAskedFor(): void
    {
        $this->record(1, JournalEntry::ACTION_DEACTIVATED);
        $this->record(2, JournalEntry::ACTION_DEACTIVATED, dryRun: true);

        $this->assertCount(1, $this->reader()->getEntries());
        $this->assertCount(2, $this->reader()->getEntries(includeSimulated: true));
    }

    public function testEntriesCanBeNarrowedToOneAccount(): void
    {
        $this->record(1, JournalEntry::ACTION_DEACTIVATED);
        $this->record(2, JournalEntry::ACTION_DEACTIVATED);

        $entries = $this->reader()->getEntries(userId: 2);

        $this->assertCount(1, $entries);
        $this->assertSame(2, $entries[0]->getUserId());
    }

    public function testEntriesCanBeNarrowedToOneAction(): void
    {
        $this->record(1, JournalEntry::ACTION_WARNED);
        $this->record(1, JournalEntry::ACTION_SKIPPED);

        $entries = $this->reader()->getEntries(action: JournalEntry::ACTION_SKIPPED);

        $this->assertCount(1, $entries);
        $this->assertSame(JournalEntry::ACTION_SKIPPED, $entries[0]->getAction());
    }

    /**
     * A filter on an action the journal cannot contain is refused rather than
     * answered empty.
     */
    public function testAnActionTheJournalNeverRecordsIsRefusedRatherThanReturningNothing(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessageMatches('/Unknown action "archived"/');

        $this->reader()->getEntries(action: 'archived');
    }

    public function testEntriesCanBeNarrowedToWhatHappenedSinceAnInstant(): void
    {
        $this->record(1, JournalEntry::ACTION_WARNED, daysAgo: 10);
        $this->record(2, JournalEntry::ACTION_WARNED, daysAgo: 1);

        $entries = $this->reader()->getEntries(
            since: gmdate('Y-m-d\TH:i:s\Z', $this->now - (3 * 86400))
        );

        $this->assertCount(1, $entries);
        $this->assertSame(2, $entries[0]->getUserId());
    }

    public function testADateThisModuleCannotReadIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessageMatches('/Could not read "last tuesday-ish" as a date/');

        $this->reader()->getEntries(since: 'last tuesday-ish');
    }

    public function testAnEmptyFilterIsNotAFilter(): void
    {
        $this->record(1, JournalEntry::ACTION_WARNED);

        $this->assertCount(1, $this->reader()->getEntries(action: '', since: '  '));
    }

    public function testAPageIsCappedAtTheConfiguredBatchSize(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->record($id, JournalEntry::ACTION_WARNED);
        }

        $this->assertCount(2, $this->reader(['general/batch_size' => '2'])->getEntries(limit: 100));
    }

    /**
     * The cursor is the entry id, not an offset.
     */
    public function testTheCursorPagesByEntryIdRatherThanByOffset(): void
    {
        for ($id = 1; $id <= 4; $id++) {
            $this->record($id, JournalEntry::ACTION_WARNED);
        }

        $first = $this->reader()->getEntries(limit: 2);
        $second = $this->reader()->getEntries(limit: 2, afterEntryId: $first[1]->getEntryId());

        $this->assertSame([1, 2], array_map(static fn ($row): int => $row->getUserId(), $first));
        $this->assertSame([3, 4], array_map(static fn ($row): int => $row->getUserId(), $second));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function reader(array $overrides = []): JournalReader
    {
        $instant = new Instant();

        return new JournalReader(
            $this->config($overrides),
            $this->journal,
            new JournalEntryConverter($instant),
            $instant
        );
    }

    private function record(int $userId, string $action, bool $dryRun = false, int $daysAgo = 0): void
    {
        $this->journal->recordAll([
            new JournalEntry(
                $userId,
                'user' . $userId,
                sprintf('user%d@example.com', $userId),
                $action,
                'a reason',
                JournalEntry::ACTOR_CRON,
                $dryRun,
                $this->now - ($daysAgo * 86400)
            ),
        ]);
    }
}
