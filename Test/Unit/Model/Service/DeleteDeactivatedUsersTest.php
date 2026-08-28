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
use Commerce\AdminUserLifecycle\Model\Service\DeleteDeactivatedUsers;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\InMemoryAdminUserFinder;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\InMemoryJournal;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\RecordingWriter;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\TransitionBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DeleteDeactivatedUsersTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1_760_000_000;

    private InMemoryJournal $journal;
    private RecordingWriter $writer;

    protected function setUp(): void
    {
        $this->journal = new InMemoryJournal();
        $this->writer = new RecordingWriter();
    }

    public function testItDeletesAccountsDeactivatedLongerThanTheWindow(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1), $this->inactive(2)]);
        $this->recordDeactivation(1, 200);
        $this->recordDeactivation(2, 10);

        $result = $this->executeStage($finder);

        self::assertSame([1], $this->writer->deleted);
        self::assertCount(1, $result->getActed());
    }

    /**
     * The central safety property.
     */
    public function testAnAncientSignInDateDoesNotShortenTheDeletionWindow(): void
    {
        $ancient = new Candidate(
            1,
            'user1',
            'user1@example.com',
            'User One',
            false,
            self::NOW - (2000 * self::DAY),
            self::NOW - (2100 * self::DAY)
        );

        $finder = new InMemoryAdminUserFinder([$ancient]);
        $this->recordDeactivation(1, 1);

        $result = $this->executeStage($finder);

        self::assertSame([], $this->writer->deleted);
        self::assertCount(1, $result->getSkipped());
        self::assertStringContainsString('not due until', $result->getSkipped()[0]->getReason());
    }

    /**
     * An account somebody deactivated by hand has no journal entry, so the
     * module has no evidence of when it was switched off.
     */
    public function testAnAccountWithNoRecordedDeactivationIsAdoptedNotDeleted(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);

        $result = $this->executeStage($finder);

        self::assertSame([], $this->writer->deleted);
        self::assertCount(1, $result->getSkipped());
        self::assertSame(JournalEntry::ACTION_ADOPTED, $result->getSkipped()[0]->getAction());
        self::assertStringContainsString('clock starts now', $result->getSkipped()[0]->getReason());
    }

    /**
     * Dry-run rows are not evidence of a real deactivation, so they cannot
     * authorise a deletion.
     */
    public function testADryRunDeactivationRecordDoesNotAuthoriseALaterDeletion(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);

        $this->journal->recordAll([
            (new JournalEntryMapper())->fromCandidate(
                $this->inactive(1),
                JournalEntry::ACTION_DEACTIVATED,
                'simulated',
                new RunContext(JournalEntry::ACTOR_CLI, true, self::NOW - (900 * self::DAY))
            ),
        ]);

        $result = $this->executeStage($finder);

        self::assertSame([], $this->writer->deleted);
        self::assertSame(JournalEntry::ACTION_ADOPTED, $result->getSkipped()[0]->getAction());
    }

    public function testProtectedAccountsSurviveEvenWhenDue(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);
        $this->recordDeactivation(1, 400);

        $result = $this->executeStage($finder, ['protect/usernames' => 'user1']);

        self::assertSame([], $this->writer->deleted);
        self::assertSame(
            ProtectionPolicy::REASON_PROTECTED_USERNAME,
            $result->getSkipped()[0]->getReason()
        );
    }

    /**
     * Somebody re-enabling an account is the clearest possible statement that
     * it is wanted, and it can happen between the query and the write.
     */
    public function testAnAccountReactivatedMidPassIsNotDeleted(): void
    {
        $this->writer->refuse = [1];
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);
        $this->recordDeactivation(1, 400);

        $result = $this->executeStage($finder);

        self::assertSame([], $this->writer->deleted);
        self::assertStringContainsString('reactivated', $result->getSkipped()[0]->getReason());
    }

    public function testADryRunDeletesNothing(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);
        $this->recordDeactivation(1, 400);

        $result = $this->executeStage($finder, [], dryRun: true);

        self::assertSame([], $this->writer->deleted);
        self::assertCount(1, $result->getActed());
        self::assertStringContainsString('dry run', $result->getActed()[0]->getReason());
    }

    public function testAFailingDeleteIsContainedToTheAccountItAffected(): void
    {
        $this->writer->explodeOn = [1];
        $finder = new InMemoryAdminUserFinder([$this->inactive(1), $this->inactive(2)]);
        $this->recordDeactivation(1, 400);
        $this->recordDeactivation(2, 400);

        $result = $this->executeStage($finder);

        self::assertSame([2], $this->writer->deleted);
        self::assertCount(1, $result->getFailed());
    }

    /**
     * Deletion is irreversible, so an install that has never made a decision
     * about it must not perform one.
     */
    public function testDeletionIsOffUnlessExplicitlyEnabled(): void
    {
        $finder = new InMemoryAdminUserFinder([$this->inactive(1)]);
        $this->recordDeactivation(1, 4000);

        $result = $this->executeStage($finder, ['delete/enabled' => '0']);

        self::assertFalse($result->isEnabled());
        self::assertSame([], $this->writer->deleted);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function executeStage(
        InMemoryAdminUserFinder $finder,
        array $overrides = [],
        bool $dryRun = false
    ): StageResult {
        $config = ConfigBuilder::build(array_merge(['delete/deactivated_days' => '180'], $overrides));

        $stage = new DeleteDeactivatedUsers(
            new StageContext($config, new NullLogger(), new JournalEntryMapper()),
            $finder,
            $this->journal,
            TransitionBuilder::build($config, $this->writer)
        );

        return $stage->execute(new RunContext(JournalEntry::ACTOR_CRON, $dryRun, self::NOW));
    }

    private function recordDeactivation(int $userId, int $daysAgo): void
    {
        $this->journal->recordAll([
            (new JournalEntryMapper())->fromCandidate(
                $this->inactive($userId),
                JournalEntry::ACTION_DEACTIVATED,
                'dormant',
                new RunContext(JournalEntry::ACTOR_CRON, false, self::NOW - ($daysAgo * self::DAY))
            ),
        ]);
    }

    private function inactive(int $userId): Candidate
    {
        return new Candidate(
            $userId,
            'user' . $userId,
            sprintf('user%d@example.com', $userId),
            'User ' . $userId,
            false,
            self::NOW - (400 * self::DAY),
            self::NOW - (900 * self::DAY),
            3
        );
    }
}
