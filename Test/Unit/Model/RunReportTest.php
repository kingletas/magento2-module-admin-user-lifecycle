<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\StageResult;
use PHPUnit\Framework\TestCase;

class RunReportTest extends TestCase
{
    public function testItAggregatesChangesAndFailuresAcrossStages(): void
    {
        $report = new RunReport(
            $this->context(false),
            [
                new StageResult(false, 'warn'),
                new StageResult(
                    true,
                    'deactivate',
                    [$this->entry()],
                    [],
                    [],
                    4
                ),
                new StageResult(
                    true,
                    'delete',
                    [],
                    [],
                    [$this->entry()],
                    2
                ),
            ],
            9,
            1.5
        );

        $this->assertTrue($report->hasChanges());
        $this->assertTrue($report->hasFailures());
        $this->assertCount(2, $report->getAllEntries());
        $this->assertSame(9, $report->getActiveAdminsBefore());
    }

    /**
     * A report of a dry run that reads like a report of a live one is how
     * somebody concludes eleven accounts were deleted when none were.
     */
    public function testTheSummaryStatesWhetherThePassWasSimulated(): void
    {
        $dry = new RunReport($this->context(true), [], 3, 0.1);
        $live = new RunReport($this->context(false), [], 3, 0.1);

        $this->assertStringContainsString('dry run', $dry->summarise());
        $this->assertStringNotContainsString('dry run', $live->summarise());
    }

    public function testRowsCarryTheCountsAndTheEntriesTogether(): void
    {
        $report = new RunReport(
            $this->context(false),
            [new StageResult(
                true,
                'deactivate',
                [$this->entry()],
                [$this->entry()],
                [],
                7
            )],
            5,
            0.2
        );

        $rows = $report->toRows();

        $this->assertSame('deactivate', $rows[0]['stage']);
        $this->assertTrue($rows[0]['enabled']);
        $this->assertSame(7, $rows[0]['examined']);
        $this->assertSame(1, $rows[0]['acted']);
        $this->assertSame(1, $rows[0]['skipped']);
        $this->assertCount(2, $rows[0]['entries']);
    }

    public function testAnEmptyReportProducesNoEntries(): void
    {
        $this->assertSame([], (new RunReport($this->context(false), [], 0, 0.0))->getAllEntries());
    }

    private function context(bool $dryRun): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_CRON, $dryRun, 1_760_000_000);
    }

    private function entry(): JournalEntry
    {
        return new JournalEntry(
            1,
            'user1',
            'u@example.test',
            JournalEntry::ACTION_DEACTIVATED,
            'dormant',
            'cron',
            false,
            1_760_000_000
        );
    }
}
