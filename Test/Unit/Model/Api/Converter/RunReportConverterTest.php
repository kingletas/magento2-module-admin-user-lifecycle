<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api\Converter;

use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\Api\Converter\RunReportConverter;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\StageResult;
use PHPUnit\Framework\TestCase;

class RunReportConverterTest extends TestCase
{
    public function testThePassIsDescribedByItsActorClockAndStartingHeadcount(): void
    {
        $converted = $this->converter()->convert($this->report());

        $this->assertSame(JournalEntry::ACTOR_API, $converted->getActor());
        $this->assertFalse($converted->isDryRun());
        $this->assertSame('2026-08-27T14:30:00Z', $converted->getStartedAt());
        $this->assertSame(4, $converted->getActiveAdminsBefore());
    }

    public function testEachStageCarriesItsCountsAndItsEntries(): void
    {
        $stages = $this->converter()->convert($this->report())->getStages();

        $this->assertCount(2, $stages);
        $this->assertSame('deactivate', $stages[0]->getStage());
        $this->assertTrue($stages[0]->isEnabled());
        $this->assertSame(3, $stages[0]->getExamined());
        $this->assertSame(1, $stages[0]->getActed());
        $this->assertSame(1, $stages[0]->getSkipped());
        $this->assertSame(0, $stages[0]->getFailed());
        $this->assertCount(2, $stages[0]->getEntries());
    }

    /**
     * A disabled stage and a stage that found nothing both report zero, and are
     * told apart.
     */
    public function testAStageThatNeverRanIsDistinguishableFromOneThatFoundNothing(): void
    {
        $stages = $this->converter()->convert($this->report())->getStages();

        $this->assertFalse($stages[1]->isEnabled());
        $this->assertSame(0, $stages[1]->getExamined());
        $this->assertSame([], $stages[1]->getEntries());
    }

    public function testTheVerdictsAPassReachesSurviveTheConversion(): void
    {
        $converted = $this->converter()->convert($this->report());

        $this->assertTrue($converted->hasChanges());
        $this->assertFalse($converted->hasFailures());
    }

    /**
     * Rounded, because a float with fourteen decimal places in a JSON payload
     * is noise dressed as precision.
     */
    public function testTheDurationIsRoundedToSomethingWorthPrinting(): void
    {
        $report = new RunReport($this->context(), [], 4, 1.23456789);

        $this->assertSame(1.235, $this->converter()->convert($report)->getDurationSeconds());
    }

    private function converter(): RunReportConverter
    {
        $instant = new Instant();

        return new RunReportConverter($instant, new JournalEntryConverter($instant));
    }

    private function report(): RunReport
    {
        return new RunReport(
            $this->context(),
            [
                new StageResult(
                    true,
                    'deactivate',
                    [$this->entry(JournalEntry::ACTION_DEACTIVATED)],
                    [$this->entry(JournalEntry::ACTION_SKIPPED)],
                    [],
                    3
                ),
                new StageResult(false, 'delete'),
            ],
            4,
            0.5
        );
    }

    private function context(): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_API, false, 1787841000);
    }

    private function entry(string $action): JournalEntry
    {
        return new JournalEntry(
            7,
            'dormant.user',
            'dormant@example.com',
            $action,
            'a reason',
            JournalEntry::ACTOR_API,
            false,
            1787841000
        );
    }
}
