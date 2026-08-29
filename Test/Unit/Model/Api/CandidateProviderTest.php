<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api;

use Commerce\AdminUserLifecycle\Api\LifecycleCandidateProviderInterface;
use Commerce\AdminUserLifecycle\Model\Api\CandidateProvider;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Support\InMemoryJournal;
use Magento\Framework\Exception\InputException;
use PHPUnit\Framework\TestCase;

class CandidateProviderTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;

    private InMemoryAdminUserFinder $finder;

    private InMemoryJournal $journal;

    private int $now;

    protected function setUp(): void
    {
        $this->finder = new InMemoryAdminUserFinder([]);
        $this->journal = new InMemoryJournal();
        $this->now = time();
    }

    /**
     * Administrators who signed in this morning, so the floor is satisfied and
     * the test is measuring the rule it says it is measuring.
     */
    private function withActiveAdministrators(int $count = 3): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $this->finder->replace($this->candidate(900 + $index, lastLoginDaysAgo: 0));
        }
    }

    public function testAStageThisModuleDoesNotHaveIsRefusedRatherThanReturningNothing(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessageMatches('/Unknown lifecycle stage "archive"/');

        $this->provider()->getList('archive');
    }

    public function testADormantAccountIsListedWithTheDecisionAlreadyMade(): void
    {
        $this->withActiveAdministrators();
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->getUserId());
        $this->assertSame('deactivate', $rows[0]->getStage());
        $this->assertTrue($rows[0]->isDue());
        $this->assertNull($rows[0]->getBlockedReason());
        $this->assertSame(200, $rows[0]->getDormantDays());
        $this->assertNotNull($rows[0]->getDueAt());
    }

    /**
     * Both facts travel, and they are not the same fact.
     */
    public function testAProtectedAccountIsStillListedAndCarriesTheRuleThatStopsIt(): void
    {
        $this->withActiveAdministrators();
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200, username: 'break-glass'));

        $rows = $this->provider(['protect/usernames' => 'break-glass'])
            ->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertTrue($rows[0]->isDue());
        $this->assertSame(ProtectionPolicy::REASON_PROTECTED_USERNAME, $rows[0]->getBlockedReason());
    }

    public function testTheAdministratorFloorShowsUpAsAReasonBeforeAnybodyActsOnIt(): void
    {
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 200));
        $this->finder->replace($this->candidate(2, lastLoginDaysAgo: 200));

        $rows = $this->provider(['protect/min_active_admins' => '2'])
            ->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertCount(2, $rows);
        $this->assertSame(ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS, $rows[0]->getBlockedReason());
        $this->assertSame(ProtectionPolicy::REASON_MIN_ACTIVE_ADMINS, $rows[1]->getBlockedReason());
    }

    /**
     * The warning stage's own window: inside the notice period and not yet past
     * the deactivation date.
     */
    public function testTheWarnListingIsTheNoticeWindowAndNotEverythingDormant(): void
    {
        $this->withActiveAdministrators();
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: 85));
        $this->finder->replace($this->candidate(2, lastLoginDaysAgo: 200));

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_WARN);

        $due = [];

        foreach ($rows as $row) {
            $due[$row->getUserId()] = $row->isDue();
        }

        $this->assertTrue($due[1], 'Five days from deactivation, inside a seven-day notice period.');
        $this->assertFalse($due[2], 'Overdue for deactivation, so there is nothing left to warn about.');
    }

    /**
     * The clock deletion is measured from lives in this module's journal, and
     * an account with no entry there has no clock at all.
     */
    public function testAnInactiveAccountWithNoRecordedDeactivationHasNoDeletionClock(): void
    {
        $this->finder->replace($this->candidate(1, active: false, lastLoginDaysAgo: 900));

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertFalse($rows[0]->isDue());
        $this->assertNull($rows[0]->getDueAt());
        $this->assertNull($rows[0]->getDeactivatedAt());
    }

    public function testARecordedDeactivationOldEnoughMakesAnAccountDueForDeletion(): void
    {
        $this->finder->replace($this->candidate(1, active: false, lastLoginDaysAgo: 900));
        $this->recordDeactivation(1, daysAgo: 200);

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertTrue($rows[0]->isDue());
        $this->assertNotNull($rows[0]->getDeactivatedAt());
        $this->assertNotNull($rows[0]->getDueAt());
    }

    public function testADeactivationInsideTheDeletionWindowIsListedWithTheDateItFallsDue(): void
    {
        $this->finder->replace($this->candidate(1, active: false, lastLoginDaysAgo: 900));
        $this->recordDeactivation(1, daysAgo: 10);

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertFalse($rows[0]->isDue());
        $this->assertSame(
            gmdate('Y-m-d\TH:i:s\Z', $this->now - (10 * self::DAY) + (180 * self::DAY)),
            $rows[0]->getDueAt()
        );
    }

    /**
     * A simulated pass records what it *would* have done.
     */
    public function testASimulatedDeactivationDoesNotStartTheDeletionClock(): void
    {
        $this->finder->replace($this->candidate(1, active: false, lastLoginDaysAgo: 900));
        $this->recordDeactivation(1, daysAgo: 200, dryRun: true);

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DELETE);

        $this->assertFalse($rows[0]->isDue());
        $this->assertNull($rows[0]->getDeactivatedAt());
    }

    public function testAPageIsCappedAtTheConfiguredBatchSizeHoweverMuchIsAskedFor(): void
    {
        $this->withActiveAdministrators();
        for ($id = 1; $id <= 5; $id++) {
            $this->finder->replace($this->candidate($id, lastLoginDaysAgo: 200));
        }

        $rows = $this->provider(['general/batch_size' => '2'])
            ->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE, 100);

        $this->assertCount(2, $rows);
    }

    public function testTheCursorSkipsWhatTheCallerHasAlreadySeen(): void
    {
        $this->withActiveAdministrators();
        for ($id = 1; $id <= 4; $id++) {
            $this->finder->replace($this->candidate($id, lastLoginDaysAgo: 200));
        }

        $rows = $this->provider()->getList(
            LifecycleCandidateProviderInterface::STAGE_DEACTIVATE,
            10,
            2
        );

        $this->assertSame([3, 4], array_map(static fn ($row): int => $row->getUserId(), $rows));
    }

    public function testAnAccountThatHasNeverSignedInIsAgedFromWhenItWasCreated(): void
    {
        $this->withActiveAdministrators();
        $this->finder->replace($this->candidate(1, lastLoginDaysAgo: null, createdDaysAgo: 400));

        $rows = $this->provider()->getList(LifecycleCandidateProviderInterface::STAGE_DEACTIVATE);

        $this->assertTrue($rows[0]->isDue());
        $this->assertNull($rows[0]->getLastLoginAt());
        $this->assertSame(400, $rows[0]->getDormantDays());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function provider(array $overrides = []): CandidateProvider
    {
        $config = $this->config($overrides);

        return new CandidateProvider(
            $config,
            $this->finder,
            $this->journal,
            new InactivityPolicy($config),
            new ProtectionPolicy($config),
            new Instant()
        );
    }

    private function candidate(
        int $userId,
        ?int $lastLoginDaysAgo = 200,
        int $createdDaysAgo = 900,
        bool $active = true,
        ?string $username = null
    ): Candidate {
        $username ??= 'user' . $userId;

        return new Candidate(
            $userId,
            $username,
            $username . '@example.com',
            'A Person',
            $active,
            $lastLoginDaysAgo === null ? null : $this->now - ($lastLoginDaysAgo * self::DAY),
            $this->now - ($createdDaysAgo * self::DAY),
            3
        );
    }

    private function recordDeactivation(int $userId, int $daysAgo, bool $dryRun = false): void
    {
        $this->journal->recordAll([
            new JournalEntry(
                $userId,
                'user' . $userId,
                'user@example.com',
                JournalEntry::ACTION_DEACTIVATED,
                'switched off',
                JournalEntry::ACTOR_CRON,
                $dryRun,
                $this->now - ($daysAgo * self::DAY)
            ),
        ]);
    }
}
