<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api\Converter;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface;
use Commerce\AdminUserLifecycle\Model\Data\LifecycleRunReport;
use Commerce\AdminUserLifecycle\Model\Data\LifecycleStageReport;
use Commerce\AdminUserLifecycle\Model\RunReport;

/**
 * A pass, as the API returns it.
 */
class RunReportConverter
{
    public function __construct(
        private readonly Instant $instant,
        private readonly JournalEntryConverter $entries
    ) {
    }

    public function convert(RunReport $report): LifecycleRunReportInterface
    {
        $stages = [];

        foreach ($report->toRows() as $row) {
            $stages[] = new LifecycleStageReport(
                $row['stage'],
                $row['enabled'],
                $row['examined'],
                $row['acted'],
                $row['skipped'],
                $row['failed'],
                $this->entries->convertAll($row['entries'])
            );
        }

        return new LifecycleRunReport(
            $report->getContext()->getActor(),
            $report->isDryRun(),
            $this->instant->format($report->getContext()->getNow()),
            round($report->getDurationSeconds(), 3),
            $report->getActiveAdminsBefore(),
            $report->hasChanges(),
            $report->hasFailures(),
            $stages
        );
    }
}
