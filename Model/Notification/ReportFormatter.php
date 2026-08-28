<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Notification;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Magento\Framework\Escaper;

/**
 * Renders a run report for email and for a terminal.
 */
class ReportFormatter
{
    /**
     * Inline styles only: every mail client that matters strips a <style> block.
     */
    private const TABLE_STYLE = 'border-collapse:collapse;width:100%;font-size:13px;'
        . 'font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif';
    private const HEAD_STYLE = 'padding:6px 10px;border:1px solid #e0e0e0;background:#f7f7f7;'
        . 'text-align:left;color:#555;white-space:nowrap';
    private const CELL_STYLE = 'padding:6px 10px;border:1px solid #e0e0e0;vertical-align:top';

    /**
     * Entries listed per stage before the list is summarised instead.
     */
    private const MAX_ENTRIES_PER_STAGE = 50;

    public function __construct(
        private readonly Escaper $escaper
    ) {
    }

    public function toHtml(RunReport $report): string
    {
        $sections = [];

        foreach ($report->toRows() as $row) {
            $sections[] = $this->stageHtml($row);
        }

        return implode('', $sections);
    }

    public function toPlainText(RunReport $report): string
    {
        $lines = [$report->summarise(), ''];

        foreach ($report->getStages() as $stage) {
            if (!$stage->isEnabled()) {
                continue;
            }

            foreach ($this->limit($stage->getAllEntries()) as $entry) {
                $lines[] = '  ' . $entry->describe();
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{stage: string, enabled: bool, examined: int, acted: int,
     *              skipped: int, failed: int, entries: JournalEntry[]} $row
     */
    private function stageHtml(array $row): string
    {
        $heading = sprintf(
            '<h3 style="margin:18px 0 6px;font-size:14px">%s</h3>',
            $this->escaper->escapeHtml(ucfirst($row['stage']))
        );

        if (!$row['enabled']) {
            return $heading . '<p style="margin:0;color:#777;font-size:13px">Disabled.</p>';
        }

        $summary = sprintf(
            '<p style="margin:0 0 8px;color:#555;font-size:13px">'
            . '%d examined &middot; %d acted on &middot; %d protected &middot; %d failed</p>',
            $row['examined'],
            $row['acted'],
            $row['skipped'],
            $row['failed']
        );

        if ($row['entries'] === []) {
            return $heading . $summary;
        }

        $body = '';

        foreach ($this->limit($row['entries']) as $entry) {
            $body .= sprintf(
                '<tr><td style="%s">%s</td><td style="%s">%s</td><td style="%s">%s</td></tr>',
                self::CELL_STYLE,
                $this->escaper->escapeHtml($entry->getAction()),
                self::CELL_STYLE,
                $this->escaper->escapeHtml(sprintf('%s (#%d)', $entry->getUsername(), $entry->getUserId())),
                self::CELL_STYLE,
                $this->escaper->escapeHtml($entry->getReason())
            );
        }

        $omitted = count($row['entries']) - self::MAX_ENTRIES_PER_STAGE;
        $note = $omitted > 0
            ? sprintf(
                '<p style="margin:6px 0 0;color:#777;font-size:12px">'
                . 'and %d more - the full list is in the journal table and the module log.</p>',
                $omitted
            )
            : '';

        return $heading . $summary . sprintf(
            '<table style="%s"><thead><tr>'
            . '<th style="%s">Action</th><th style="%s">Account</th><th style="%s">Reason</th>'
            . '</tr></thead><tbody>%s</tbody></table>%s',
            self::TABLE_STYLE,
            self::HEAD_STYLE,
            self::HEAD_STYLE,
            self::HEAD_STYLE,
            $body,
            $note
        );
    }

    /**
     * @param JournalEntry[] $entries
     * @return JournalEntry[]
     */
    private function limit(array $entries): array
    {
        return array_slice($entries, 0, self::MAX_ENTRIES_PER_STAGE);
    }
}
