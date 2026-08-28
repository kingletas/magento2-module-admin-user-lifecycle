<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api\Converter;

use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use PHPUnit\Framework\TestCase;

final class JournalEntryConverterTest extends TestCase
{
    public function testARowKeepsEveryFieldAndGainsAReadableDate(): void
    {
        $converted = $this->converter()->convert($this->entry(entryId: 41));

        self::assertSame(41, $converted->getEntryId());
        self::assertSame(7, $converted->getUserId());
        self::assertSame('dormant.user', $converted->getUsername());
        self::assertSame('dormant@example.com', $converted->getEmail());
        self::assertSame(JournalEntry::ACTION_DEACTIVATED, $converted->getAction());
        self::assertSame('no sign-in for 200 days', $converted->getReason());
        self::assertSame(JournalEntry::ACTOR_CRON, $converted->getActor());
        self::assertFalse($converted->isDryRun());
        self::assertSame('2026-08-27T14:30:00Z', $converted->getOccurredAt());
    }

    /**
     * A run report is assembled from entries the pass has only just decided on,
     * and a bulk insert does not hand their ids back.
     */
    public function testAnEntryThatHasNotBeenWrittenYetReportsNoId(): void
    {
        self::assertSame(0, $this->converter()->convert($this->entry())->getEntryId());
    }

    public function testASimulatedEntrySaysSo(): void
    {
        self::assertTrue($this->converter()->convert($this->entry(dryRun: true))->isDryRun());
    }

    public function testConvertingNothingIsNotAnError(): void
    {
        self::assertSame([], $this->converter()->convertAll([]));
    }

    public function testAPageKeepsItsOrder(): void
    {
        $converted = $this->converter()->convertAll([
            $this->entry(entryId: 1),
            $this->entry(entryId: 2),
            $this->entry(entryId: 3),
        ]);

        self::assertSame([1, 2, 3], array_map(static fn ($row): int => $row->getEntryId(), $converted));
    }

    private function converter(): JournalEntryConverter
    {
        return new JournalEntryConverter(new Instant());
    }

    private function entry(?int $entryId = null, bool $dryRun = false): JournalEntry
    {
        return new JournalEntry(
            7,
            'dormant.user',
            'dormant@example.com',
            JournalEntry::ACTION_DEACTIVATED,
            'no sign-in for 200 days',
            JournalEntry::ACTOR_CRON,
            $dryRun,
            1787841000,
            $entryId
        );
    }
}
