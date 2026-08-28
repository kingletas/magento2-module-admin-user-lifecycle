<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Integration\Model;

use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The journal table, against a real schema.
 *
 * @magentoDbIsolation enabled
 */
#[Group('integration')]
class JournalPersistenceTest extends TestCase
{
    private LifecycleJournalInterface $journal;

    protected function setUp(): void
    {
        $this->journal = Bootstrap::getObjectManager()->get(LifecycleJournalInterface::class);
    }

    public function testATimestampSurvivesTheRoundTripAsUtc(): void
    {
        $occurredAt = time() - 12345;

        $this->journal->recordAll([$this->entry(4242, JournalEntry::ACTION_DEACTIVATED, false, $occurredAt)]);

        self::assertSame($occurredAt, $this->journal->getDeactivatedAt([4242])[4242] ?? null);
    }

    /**
     * The audit record survives an oversized reason.
     */
    public function testAnOversizedReasonDoesNotFailTheInsert(): void
    {
        $entry = new JournalEntry(
            4243,
            str_repeat('u', 200),
            str_repeat('e', 400) . '@example.test',
            JournalEntry::ACTION_FAILED,
            str_repeat('r', 5000),
            JournalEntry::ACTOR_CRON,
            false,
            time()
        );

        $this->journal->recordAll([$entry]);

        $this->expectNotToPerformAssertions();
    }

    public function testADryRunEntryIsNotReadBackAsEvidence(): void
    {
        $this->journal->recordAll([$this->entry(4244, JournalEntry::ACTION_DEACTIVATED, true, time() - 1000)]);

        self::assertSame([], $this->journal->getDeactivatedAt([4244]));
    }

    public function testAdoptionCountsAsADeactivationForTheDeletionClock(): void
    {
        $occurredAt = time() - 500;

        $this->journal->recordAll([$this->entry(4245, JournalEntry::ACTION_ADOPTED, false, $occurredAt)]);

        self::assertSame($occurredAt, $this->journal->getDeactivatedAt([4245])[4245] ?? null);
    }

    public function testPruningRemovesOnlyEntriesPastTheCutoff(): void
    {
        $this->journal->recordAll([
            $this->entry(4246, JournalEntry::ACTION_DEACTIVATED, false, time() - 100000),
            $this->entry(4247, JournalEntry::ACTION_DEACTIVATED, false, time() - 10),
        ]);

        $this->journal->prune(time() - 50000);

        self::assertSame([], $this->journal->getDeactivatedAt([4246]));
        self::assertNotEmpty($this->journal->getDeactivatedAt([4247]));
    }

    private function entry(int $userId, string $action, bool $dryRun, int $occurredAt): JournalEntry
    {
        return new JournalEntry(
            $userId,
            'user' . $userId,
            sprintf('user%d@example.test', $userId),
            $action,
            'integration fixture',
            JournalEntry::ACTOR_CRON,
            $dryRun,
            $occurredAt
        );
    }
}
