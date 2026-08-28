<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Cron;

use Commerce\AdminUserLifecycle\Cron\PruneJournal;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\InMemoryJournal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PruneJournalTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;

    private InMemoryJournal $journal;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournal();
    }

    public function testEntriesPastTheRetentionWindowAreRemoved(): void
    {
        $this->record(time() - (900 * self::DAY));
        $this->record(time() - (10 * self::DAY));

        $this->cron(['report/journal_retention_days' => '730'])->execute();

        $this->assertSame(1, $this->journal->pruned);
        $this->assertCount(1, $this->journal->entries);
    }

    /**
     * The journal is the evidence a pending deletion rests on.
     */
    public function testRetentionCannotBeShortenedInsideTheDeletionWindow(): void
    {
        $this->record(time() - (200 * self::DAY));

        $this->cron([
            'delete/deactivated_days' => '365',
            'report/journal_retention_days' => '30',
        ])->execute();

        $this->assertSame(0, $this->journal->pruned, 'A 200-day-old deactivation still authorises a deletion.');
    }

    public function testAFailingPruneDoesNotFailTheCronJob(): void
    {
        $journal = new class () implements \Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface {
            public function recordAll(array $entries): void
            {
            }

            public function getDeactivatedAt(array $userIds): array
            {
                return [];
            }

            public function getWarnedAt(array $userIds): array
            {
                return [];
            }

            public function prune(int $olderThanTimestamp): int
            {
                throw new \RuntimeException('the table is locked');
            }
        };

        $cron = new PruneJournal($this->config(), $journal, new NullLogger());

        $this->expectNotToPerformAssertions();

        $cron->execute();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function cron(array $overrides = []): PruneJournal
    {
        return new PruneJournal($this->config($overrides), $this->journal, new NullLogger());
    }

    private function record(int $occurredAt): void
    {
        $this->journal->recordAll([
            new JournalEntry(
                1,
                'user1',
                'u@example.test',
                JournalEntry::ACTION_DEACTIVATED,
                'dormant',
                JournalEntry::ACTOR_CRON,
                false,
                $occurredAt
            ),
        ]);
    }
}
