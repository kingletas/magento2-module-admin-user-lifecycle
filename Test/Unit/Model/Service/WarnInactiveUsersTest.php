<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\Service\WarnInactiveUsers;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\InMemoryJournal;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingNotifier;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\TransitionBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WarnInactiveUsersTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1_760_000_000;

    private InMemoryJournal $journal;
    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournal();
        $this->notifier = new RecordingNotifier();
    }

    public function testItWarnsOnlyAccountsInsideTheNoticeWindow(): void
    {
        $finder = new InMemoryAdminUserFinder([
            $this->dormant(1, 85),
            $this->dormant(2, 60),
            $this->dormant(3, 95),
        ]);

        $result = $this->executeStage($finder);

        $this->assertSame([1], array_column($this->notifier->warned, 0));
        $this->assertCount(1, $result->getActed());
    }

    public function testTheWarningNamesTheDateTheAccountWillBeDeactivated(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 85)]);

        $result = $this->executeStage($finder);

        $expectedDueAt = self::NOW + (5 * self::DAY);
        $this->assertSame($expectedDueAt, $this->notifier->warned[0][1]);
        $this->assertStringContainsString(gmdate('Y-m-d', $expectedDueAt), $result->getActed()[0]->getReason());
    }

    public function testAnAccountAlreadyWarnedAboutThisDeactivationIsNotWarnedAgain(): void
    {
        $candidate = $this->dormant(1, 85);
        $finder = new InMemoryAdminUserFinder([$candidate]);

        $this->journal->recordAll([
            (new JournalEntryMapper())->fromCandidate(
                $candidate,
                JournalEntry::ACTION_WARNED,
                'previously warned',
                new RunContext(JournalEntry::ACTOR_CRON, false, self::NOW - self::DAY)
            ),
        ]);

        $result = $this->executeStage($finder);

        $this->assertSame([], $this->notifier->warned);
        $this->assertCount(1, $result->getSkipped());
        $this->assertStringContainsString('already warned', $result->getSkipped()[0]->getReason());
    }

    /**
     * Signing in moves the deactivation date, so a fresh warning is owed nearer
     * the new one.
     */
    public function testSigningInAfterAWarningEarnsAFreshWarningLater(): void
    {
        $candidate = $this->dormant(1, 85);
        $finder = new InMemoryAdminUserFinder([$candidate]);

        $this->journal->recordAll([
            (new JournalEntryMapper())->fromCandidate(
                $candidate,
                JournalEntry::ACTION_WARNED,
                'warned about a deactivation that no longer applies',
                new RunContext(JournalEntry::ACTOR_CRON, false, self::NOW - (200 * self::DAY))
            ),
        ]);

        $result = $this->executeStage($finder);

        $this->assertSame([1], array_column($this->notifier->warned, 0));
        $this->assertCount(1, $result->getActed());
    }

    /**
     * Telling somebody their permanently protected service account is about to
     * be retired is worse than telling them nothing.
     */
    public function testProtectedAccountsAreNeverWarned(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 85)]);

        $result = $this->executeStage($finder, ['protect/usernames' => 'user1']);

        $this->assertSame([], $this->notifier->warned);
        $this->assertSame(
            ProtectionPolicy::REASON_PROTECTED_USERNAME,
            $result->getSkipped()[0]->getReason()
        );
    }

    /**
     * An undelivered warning is not journalled as delivered, which would
     * consume the only notice.
     */
    public function testAnUndeliveredWarningIsRecordedAsAFailureNotAsAWarning(): void
    {
        $this->notifier->undeliverable = [1];
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 85)]);

        $result = $this->executeStage($finder);

        $this->assertCount(0, $result->getActed());
        $this->assertCount(1, $result->getFailed());
        $this->assertSame(JournalEntry::ACTION_FAILED, $result->getFailed()[0]->getAction());
    }

    public function testAThrowingMailerIsContainedToTheAccountItAffected(): void
    {
        $this->notifier->explodeOn = [1];
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 85), $this->dormant(2, 86)]);

        $result = $this->executeStage($finder);

        $this->assertSame([2], array_column($this->notifier->warned, 0));
        $this->assertCount(1, $result->getFailed());
        $this->assertStringContainsString('mail transport is down', $result->getFailed()[0]->getReason());
    }

    public function testAnAccountWithNoEmailAddressIsReportedRatherThanIgnored(): void
    {
        $candidate = new Candidate(
            1,
            'user1',
            '',
            'User One',
            true,
            self::NOW - (85 * self::DAY),
            self::NOW - (900 * self::DAY)
        );

        $result = $this->executeStage(new InMemoryAdminUserFinder([$candidate]));

        $this->assertCount(1, $result->getFailed());
        $this->assertStringContainsString('no email address', $result->getFailed()[0]->getReason());
    }

    public function testADryRunSendsNoMail(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 85)]);

        $result = $this->executeStage($finder, [], dryRun: true);

        $this->assertSame([], $this->notifier->warned);
        $this->assertCount(1, $result->getActed());
        $this->assertStringContainsString('dry run', $result->getActed()[0]->getReason());
    }

    public function testWarningIsDisabledWhenDeactivationIsOff(): void
    {
        $result = $this->executeStage(new InMemoryAdminUserFinder([]), ['deactivate/enabled' => '0']);

        $this->assertFalse($result->isEnabled());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function executeStage(
        InMemoryAdminUserFinder $finder,
        array $overrides = [],
        bool $dryRun = false
    ): StageResult {
        $config = ConfigBuilder::build(
            array_merge(['deactivate/inactive_days' => '90', 'warn/days_before' => '7'], $overrides)
        );

        $stage = new WarnInactiveUsers(
            new StageContext($config, new NullLogger(), new JournalEntryMapper()),
            $finder,
            $this->journal,
            new InactivityPolicy($config),
            TransitionBuilder::build($config, null, $this->notifier)
        );

        return $stage->execute(new RunContext(JournalEntry::ACTOR_CRON, $dryRun, self::NOW));
    }

    private function dormant(int $userId, int $daysAgo): Candidate
    {
        return new Candidate(
            $userId,
            'user' . $userId,
            sprintf('user%d@example.com', $userId),
            'User ' . $userId,
            true,
            self::NOW - ($daysAgo * self::DAY),
            self::NOW - (900 * self::DAY),
            3
        );
    }
}
