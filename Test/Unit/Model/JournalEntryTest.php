<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\RunContext;
use PHPUnit\Framework\TestCase;

class JournalEntryTest extends TestCase
{
    private const NOW = 1_760_000_000;

    public function testItCopiesTheIdentityOfTheAccountItDescribes(): void
    {
        $entry = (new JournalEntryMapper())->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_DELETED,
            'deactivated 200 days ago',
            $this->context()
        );

        $row = $entry->toRow();

        $this->assertSame(7, $row['user_id']);
        $this->assertSame('adminuser', $row['username']);
        $this->assertSame('ada@example.com', $row['email']);
        $this->assertSame(JournalEntry::ACTION_DELETED, $row['action']);
        $this->assertSame('cli', $row['actor']);
        $this->assertSame(0, $row['dry_run']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::NOW), $row['occurred_at']);
    }

    /**
     * A journal row is written by a pass that has just deleted the account it
     * describes.
     */
    public function testAnOverLongReasonIsClippedToTheColumnWidth(): void
    {
        $entry = (new JournalEntryMapper())->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_FAILED,
            str_repeat('x', 4000),
            $this->context()
        );

        $this->assertSame(JournalEntry::MAX_REASON_LENGTH, mb_strlen((string) $entry->toRow()['reason']));
    }

    public function testAnOverLongUsernameIsClippedToTheColumnWidth(): void
    {
        $candidate = new Candidate(7, str_repeat('u', 200), 'ada@example.com', 'Ada', false, null, 0);
        $entry = (new JournalEntryMapper())
            ->fromCandidate($candidate, JournalEntry::ACTION_SKIPPED, 'x', $this->context());

        $this->assertSame(40, mb_strlen((string) $entry->toRow()['username']));
    }

    public function testADryRunEntryLabelsItselfInTheDescription(): void
    {
        $entry = (new JournalEntryMapper())->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_DEACTIVATED,
            'no sign-in for 200 days',
            new RunContext(JournalEntry::ACTOR_CRON, true, self::NOW)
        );

        $this->assertStringStartsWith('[dry run] ', $entry->describe());
        $this->assertSame(1, $entry->toRow()['dry_run']);
    }

    private function candidate(): Candidate
    {
        return new Candidate(7, 'adminuser', 'ada@example.com', 'Ada Lovelace', false, null, 1_700_000_000);
    }

    private function context(): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_CLI, false, self::NOW);
    }
}
