<?php
/**
 * @package   Commerce_AdminUserLifecycle
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

class JournalEntryMapperTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private JournalEntryMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new JournalEntryMapper();
    }

    /**
     * The identity is copied in rather than joined back to `admin_user`,
     * because the row being described may be the one that no longer exists.
     */
    public function testTheAccountsIdentityIsCopiedIntoTheEntry(): void
    {
        $entry = $this->mapper->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_DELETED,
            'deactivated 200 days ago',
            $this->context()
        );

        $this->assertSame(7, $entry->getUserId());
        $this->assertSame('adminuser', $entry->getUsername());
        $this->assertSame('ada@example.com', $entry->getEmail());
        $this->assertSame(JournalEntry::ACTION_DELETED, $entry->getAction());
        $this->assertSame('deactivated 200 days ago', $entry->getReason());
    }

    public function testThePassesIdentityIsCopiedToo(): void
    {
        $entry = $this->mapper->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_SKIPPED,
            'protected',
            new RunContext(JournalEntry::ACTOR_CLI, true, self::NOW)
        );

        $this->assertSame(JournalEntry::ACTOR_CLI, $entry->getActor());
        $this->assertTrue($entry->isDryRun());
        $this->assertSame(self::NOW, $entry->getOccurredAt());
    }

    /**
     * A stage that threw did not happen to any one account, so the entry
     * describing it belongs to the pass rather than to a user.
     */
    public function testARunLevelEntryHasNoAccount(): void
    {
        $entry = $this->mapper->forRun(
            JournalEntry::ACTION_FAILED,
            'stage "warn" threw: the mailer is down',
            $this->context()
        );

        $this->assertSame(0, $entry->getUserId());
        $this->assertSame('', $entry->getUsername());
        $this->assertStringContainsString('the mailer is down', $entry->getReason());
    }

    private function candidate(): Candidate
    {
        return new Candidate(7, 'adminuser', 'ada@example.com', 'Ada Lovelace', false, null, 1_700_000_000);
    }

    private function context(): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_CRON, false, self::NOW);
    }
}
