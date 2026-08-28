<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\RecordingNotifier;
use Commerce\AdminUserLifecycle\Test\Support\RecordingWriter;
use Commerce\AdminUserLifecycle\Test\Support\TransitionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The class both the scheduled pass and the REST API act through.
 */
class AccountTransitionTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;

    private RecordingWriter $writer;

    private RecordingNotifier $notifier;

    private int $now;

    protected function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->notifier = new RecordingNotifier();
        $this->now = time();
    }

    public function testEveryOutcomeIsAJournalEntryRatherThanAnExceptionOrANull(): void
    {
        $transition = $this->transition();

        $this->assertSame(
            JournalEntry::ACTION_DEACTIVATED,
            $transition->deactivate($this->candidate(), 10, $this->context())->getAction()
        );
        $this->assertSame(
            JournalEntry::ACTION_SKIPPED,
            $transition->deactivate($this->candidate(lastLoginDaysAgo: 2), 10, $this->context())->getAction()
        );
        $this->assertSame(
            JournalEntry::ACTION_ADOPTED,
            $transition->delete($this->candidate(active: false), null, $this->context())->getAction()
        );
        $this->assertSame(
            JournalEntry::ACTION_WARNED,
            $transition->warn($this->candidate(lastLoginDaysAgo: 85), null, $this->context())->getAction()
        );
    }

    /**
     * A write that throws is a failure of one account, not of the pass.
     */
    public function testAWriteThatThrowsBecomesAFailedEntryAndNotAnException(): void
    {
        $this->writer->explodeOn = [1];

        $entry = $this->transition()->deactivate($this->candidate(), 10, $this->context());

        $this->assertSame(JournalEntry::ACTION_FAILED, $entry->getAction());
        $this->assertStringContainsString('deactivation failed', $entry->getReason());
    }

    /**
     * Somebody signing in between the decision and the write has made a newer
     * decision than this one, and theirs wins.
     */
    public function testARowThatChangedUnderneathTheDecisionIsLeftAlone(): void
    {
        $this->writer->refuse = [1];

        $entry = $this->transition()->deactivate($this->candidate(), 10, $this->context());

        $this->assertSame(JournalEntry::ACTION_SKIPPED, $entry->getAction());
        $this->assertSame('reactivated while the pass was running', $entry->getReason());
    }

    public function testADryRunDecidesEverythingAndWritesNothing(): void
    {
        $transition = $this->transition();
        $context = $this->context(dryRun: true);

        $deactivated = $transition->deactivate($this->candidate(), 10, $context);
        $warned = $transition->warn($this->candidate(lastLoginDaysAgo: 85), null, $context);

        $this->assertSame(JournalEntry::ACTION_DEACTIVATED, $deactivated->getAction());
        $this->assertStringContainsString('not applied: dry run', $deactivated->getReason());
        $this->assertStringContainsString('no mail sent: dry run', $warned->getReason());
        $this->assertSame([], $this->writer->deactivated);
        $this->assertSame([], $this->notifier->warned);
    }

    /**
     * Live sessions are ended with the account.
     */
    public function testDeactivationEndsWhateverSessionsTheAccountStillHolds(): void
    {
        $this->notifier->sessionsPerUser = 2;

        $entry = $this->transition()->deactivate($this->candidate(), 10, $this->context());

        $this->assertSame([1], $this->notifier->terminated);
        $this->assertStringContainsString('2 live session(s) ended', $entry->getReason());
    }

    /**
     * An undelivered warning is not a warning.
     */
    public function testAWarningThatWasNotDeliveredIsNotRecordedAsOne(): void
    {
        $this->notifier->undeliverable = [1];

        $entry = $this->transition()->warn($this->candidate(lastLoginDaysAgo: 85), null, $this->context());

        $this->assertSame(JournalEntry::ACTION_FAILED, $entry->getAction());
        $this->assertSame('warning was not delivered', $entry->getReason());
    }

    public function testAnAccountWithNoAddressIsAFailureRatherThanASkip(): void
    {
        $entry = $this->transition()->warn(
            $this->candidate(lastLoginDaysAgo: 85, email: ''),
            null,
            $this->context()
        );

        $this->assertSame(JournalEntry::ACTION_FAILED, $entry->getAction());
        $this->assertSame('no email address on the account, cannot warn', $entry->getReason());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function transition(array $overrides = []): AccountTransition
    {
        return TransitionBuilder::build($this->config($overrides), $this->writer, $this->notifier);
    }

    private function context(bool $dryRun = false): RunContext
    {
        return new RunContext(JournalEntry::ACTOR_API, $dryRun, $this->now);
    }

    private function candidate(
        ?int $lastLoginDaysAgo = 200,
        bool $active = true,
        string $email = 'user1@example.com'
    ): Candidate {
        return new Candidate(
            1,
            'user1',
            $email,
            'A Person',
            $active,
            $lastLoginDaysAgo === null ? null : $this->now - ($lastLoginDaysAgo * self::DAY),
            $this->now - (900 * self::DAY),
            3
        );
    }
}
