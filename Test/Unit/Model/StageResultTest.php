<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\StageResult;
use PHPUnit\Framework\TestCase;

class StageResultTest extends TestCase
{
    /**
     * Nothing due and everything due but protected are different answers, not
     * one empty list.
     */
    public function testSkipsAreCarriedAlongsideActions(): void
    {
        $result = new StageResult(
            true,
            'deactivate',
            [],
            [$this->entry(JournalEntry::ACTION_SKIPPED), $this->entry(JournalEntry::ACTION_SKIPPED)],
            [],
            11
        );

        $this->assertFalse($result->hasChanges());
        $this->assertCount(2, $result->getSkipped());
        $this->assertSame(11, $result->getExamined());
        $this->assertSame('deactivate: 11 examined, 0 acted on, 2 protected, 0 failed', $result->summarise());
    }

    /**
     * A quiet deletion stage and a switched-off one look identical in a bare
     * count, and only one of them is worth investigating.
     */
    public function testADisabledStageIsDistinctFromOneThatFoundNothing(): void
    {
        $disabled = new StageResult(false, 'delete');
        $quiet = new StageResult(
            true,
            'delete',
            [],
            [],
            [],
            0
        );

        $this->assertFalse($disabled->isEnabled());
        $this->assertTrue($quiet->isEnabled());
        $this->assertSame('delete: disabled', $disabled->summarise());
        $this->assertSame('delete: 0 examined, 0 acted on, 0 protected, 0 failed', $quiet->summarise());
    }

    public function testAllEntriesCoversEveryOutcome(): void
    {
        $result = new StageResult(
            true,
            'warn',
            [$this->entry(JournalEntry::ACTION_WARNED)],
            [$this->entry(JournalEntry::ACTION_SKIPPED)],
            [$this->entry(JournalEntry::ACTION_FAILED)],
            3
        );

        $this->assertCount(3, $result->getAllEntries());
        $this->assertTrue($result->hasChanges());
        $this->assertTrue($result->hasFailures());
    }

    private function entry(string $action): JournalEntry
    {
        return new JournalEntry(1, 'user1', 'u@example.test', $action, 'because', 'cron', false, 1_000);
    }
}
