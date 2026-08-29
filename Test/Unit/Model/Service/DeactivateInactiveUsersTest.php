<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\DeactivateInactiveUsers;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Support\RecordingNotifier;
use Commerce\AdminUserLifecycle\Test\Support\RecordingWriter;
use Commerce\AdminUserLifecycle\Test\Support\TransitionBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DeactivateInactiveUsersTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;
    private const NOW = 1_760_000_000;

    private RecordingWriter $writer;
    private RecordingNotifier $sessions;

    protected function setUp(): void
    {
        $this->writer = new RecordingWriter();
        $this->sessions = new RecordingNotifier();
    }

    public function testItDeactivatesDormantAccounts(): void
    {
        $finder = new InMemoryAdminUserFinder([
            $this->dormant(1, 200),
            $this->dormant(2, 400),
            $this->dormant(3, 10),
            $this->active(4),
            $this->active(5),
        ]);

        $result = $this->executeStage($finder);

        $this->assertSame([1, 2], $this->writer->deactivated);
        $this->assertCount(2, $result->getActed());
    }

    /**
     * The regression this stage exists to make impossible.
     */
    public function testItStopsBeforeDroppingBelowTheActiveAdministratorFloor(): void
    {
        $finder = new InMemoryAdminUserFinder([
            $this->dormant(1, 300),
            $this->dormant(2, 300),
            $this->dormant(3, 300),
        ]);

        $result = $this->executeStage($finder, ['protect/min_active_admins' => '2']);

        $this->assertSame([1], $this->writer->deactivated, 'Only one account may go before the floor bites.');
        $this->assertCount(2, $result->getSkipped());

        foreach ($result->getSkipped() as $entry) {
            $this->assertSame(ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS, $entry->getReason());
        }
    }

    /**
     * Deactivating only stops the *next* sign-in.
     */
    public function testItEndsTheLiveSessionsOfEveryAccountItDeactivates(): void
    {
        $this->sessions->sessionsPerUser = 2;
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 300), $this->active(2), $this->active(3)]);

        $result = $this->executeStage($finder);

        $this->assertSame([1], $this->sessions->terminated);
        $this->assertStringContainsString('2 live session(s) ended', $result->getActed()[0]->getReason());
    }

    /**
     * The compare-and-swap.
     */
    public function testAnAccountReactivatedMidPassIsSkippedRatherThanForced(): void
    {
        $this->writer->refuse = [1];
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 300), $this->active(2), $this->active(3)]);

        $result = $this->executeStage($finder);

        $this->assertSame([], $this->writer->deactivated);
        $this->assertCount(1, $result->getSkipped());
        $this->assertStringContainsString('reactivated', $result->getSkipped()[0]->getReason());
        $this->assertSame([], $this->sessions->terminated, 'No write happened, so no sessions should be ended.');
    }

    public function testADryRunWritesNothingButStillReportsWhatItWouldDo(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->dormant(1, 300), $this->active(2), $this->active(3)]);

        $result = $this->executeStage($finder, [], dryRun: true);

        $this->assertSame([], $this->writer->deactivated);
        $this->assertSame([], $this->sessions->terminated);
        $this->assertCount(1, $result->getActed());
        $this->assertStringContainsString('dry run', $result->getActed()[0]->getReason());
    }

    /**
     * A dry run must not consume the active-administrator budget, or the report
     * would show fewer accounts than a real pass would actually touch.
     */
    public function testADryRunDoesNotConsumeTheAdministratorFloor(): void
    {
        $finder = new InMemoryAdminUserFinder([
            $this->dormant(1, 300),
            $this->dormant(2, 300),
            $this->dormant(3, 300),
            $this->active(4),
        ]);

        $result = $this->executeStage($finder, ['protect/min_active_admins' => '2'], dryRun: true);

        $this->assertCount(3, $result->getActed());
    }

    public function testAFailingWriteIsRecordedAndTheRestOfThePassContinues(): void
    {
        $this->writer->explodeOn = [1];
        $finder = new InMemoryAdminUserFinder([
            $this->dormant(1, 300),
            $this->dormant(2, 300),
            $this->active(3),
            $this->active(4),
        ]);

        $result = $this->executeStage($finder);

        $this->assertSame([2], $this->writer->deactivated);
        $this->assertCount(1, $result->getFailed());
        $this->assertSame(JournalEntry::ACTION_FAILED, $result->getFailed()[0]->getAction());
    }

    public function testTheStageReportsItselfDisabledRatherThanEmpty(): void
    {
        $result = $this->executeStage(new InMemoryAdminUserFinder([]), ['deactivate/enabled' => '0']);

        $this->assertFalse($result->isEnabled());
        $this->assertSame('deactivate: disabled', $result->summarise());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function executeStage(
        InMemoryAdminUserFinder $finder,
        array $overrides = [],
        bool $dryRun = false
    ): StageResult {
        $config = $this->config($overrides);

        $stage = new DeactivateInactiveUsers(
            new StageContext($config, new NullLogger(), new JournalEntryMapper()),
            $finder,
            TransitionBuilder::build($config, $this->writer, $this->sessions)
        );

        return $stage->execute(
            new RunContext(JournalEntry::ACTOR_CRON, $dryRun, self::NOW)
        );
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

    private function active(int $userId): Candidate
    {
        return $this->dormant($userId, 0);
    }
}
