<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Notification;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Notification\ReportFormatter;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

final class ReportFormatterTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private ReportFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ReportFormatter(new Escaper());
    }

    /**
     * The security property of this class, and the reason the template's one
     * raw variable is safe.
     */
    public function testEveryValueFromTheDatabaseIsEscapedBeforeItReachesTheTemplate(): void
    {
        $entry = new JournalEntry(
            42,
            '<script>alert(1)</script>',
            'x@example.test',
            JournalEntry::ACTION_DELETED,
            'reason with "quotes" & <img src=x onerror=alert(2)>',
            'cron',
            false,
            self::NOW
        );

        $html = $this->formatter->toHtml($this->report([new StageResult(
            true,
            'delete',
            [$entry],
            [],
            [],
            1
        )]));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
    }

    public function testTheStageNameIsEscapedToo(): void
    {
        $html = $this->formatter->toHtml(
            $this->report([new StageResult(
                true,
                '<b>evil</b>',
                [],
                [],
                [],
                0
            )])
        );

        self::assertStringNotContainsString('<b>evil</b>', $html);
    }

    /**
     * A report that lists nine hundred protected accounts is a report nobody
     * reads, and the counts above it already carry the shape of the pass.
     */
    public function testALongEntryListIsTruncatedWithACountOfWhatWasOmitted(): void
    {
        $entries = [];

        for ($index = 0; $index < 60; $index++) {
            $entries[] = new JournalEntry(
                $index,
                'user' . $index,
                'u@example.test',
                JournalEntry::ACTION_SKIPPED,
                'protected',
                'cron',
                false,
                self::NOW
            );
        }

        $html = $this->formatter->toHtml(
            $this->report([new StageResult(
                true,
                'deactivate',
                [],
                $entries,
                [],
                60
            )])
        );

        self::assertStringContainsString('and 10 more', $html);
        self::assertSame(50, substr_count($html, '<tr><td'));
    }

    public function testADisabledStageSaysSoRatherThanRenderingAnEmptyTable(): void
    {
        $html = $this->formatter->toHtml($this->report([new StageResult(false, 'delete')]));

        self::assertStringContainsString('Disabled.', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testPlainTextCarriesEveryEntryDescription(): void
    {
        $entry = new JournalEntry(
            7,
            'dormant.user',
            'u@example.test',
            JournalEntry::ACTION_DEACTIVATED,
            'no sign-in for 120 days',
            'cli',
            false,
            self::NOW
        );

        $text = $this->formatter->toPlainText(
            $this->report([new StageResult(
                true,
                'deactivate',
                [$entry],
                [],
                [],
                1
            )])
        );

        self::assertStringContainsString('deactivated user 7 (dormant.user): no sign-in for 120 days', $text);
    }

    /**
     * @param StageResult[] $stages
     */
    private function report(array $stages): RunReport
    {
        return new RunReport(
            new RunContext(JournalEntry::ACTOR_CRON, false, self::NOW),
            $stages,
            5,
            0.42
        );
    }
}
