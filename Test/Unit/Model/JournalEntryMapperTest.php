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

final class JournalEntryMapperTest extends TestCase
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

        self::assertSame(7, $entry->getUserId());
        self::assertSame('adminuser', $entry->getUsername());
        self::assertSame('ada@example.com', $entry->getEmail());
        self::assertSame(JournalEntry::ACTION_DELETED, $entry->getAction());
        self::assertSame('deactivated 200 days ago', $entry->getReason());
    }

    public function testThePassesIdentityIsCopiedToo(): void
    {
        $entry = $this->mapper->fromCandidate(
            $this->candidate(),
            JournalEntry::ACTION_SKIPPED,
            'protected',
            new RunContext(JournalEntry::ACTOR_CLI, true, self::NOW)
        );

        self::assertSame(JournalEntry::ACTOR_CLI, $entry->getActor());
        self::assertTrue($entry->isDryRun());
        self::assertSame(self::NOW, $entry->getOccurredAt());
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

        self::assertSame(0, $entry->getUserId());
        self::assertSame('', $entry->getUsername());
        self::assertStringContainsString('the mailer is down', $entry->getReason());
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
