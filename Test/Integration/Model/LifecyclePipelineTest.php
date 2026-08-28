<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Integration\Model;

use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\ResourceModel\LifecycleJournal;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Magento\Authorization\Model\Acl\Role\User as UserRole;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\ResourceModel\User as UserResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The whole pipeline against a real database.
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea adminhtml
 */
#[Group('integration')]
class LifecyclePipelineTest extends TestCase
{
    private const DAY = 86400;
    private const SECTION = 'commerce_adminusers';

    private ResourceConnection $resource;
    private LifecycleRunner $runner;
    private LifecycleJournalInterface $journal;
    private string $userTable;
    private string $roleTable;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->runner = $objectManager->get(LifecycleRunner::class);
        $this->journal = $objectManager->get(LifecycleJournalInterface::class);
        $this->userTable = $objectManager->get(UserResource::class)->getMainTable();
        $this->roleTable = $objectManager->get(RoleResource::class)->getMainTable();

        $this->configure([
            'general/enabled' => '1',
            'general/dry_run' => '0',
            'warn/enabled' => '0',
            'deactivate/enabled' => '1',
            'deactivate/inactive_days' => '90',
            'deactivate/new_account_grace_days' => '30',
            'delete/enabled' => '1',
            'delete/deactivated_days' => '180',
            'protect/min_active_admins' => '1',
            'report/enabled' => '0',
        ]);
    }

    public function testADormantAccountIsDeactivatedAndJournalled(): void
    {
        $userId = $this->insertUser('commerce_pipeline_dormant', time() - (400 * self::DAY));

        $report = $this->runner->run(JournalEntry::ACTOR_CLI, false);

        $this->assertSame(0, $this->isActive($userId), 'The account should be switched off.');
        $this->assertTrue($report->hasChanges());
        $this->assertNotEmpty($this->journal->getDeactivatedAt([$userId]));
    }

    /**
     * A dry run reports like a live one and changes nothing.
     */
    public function testADryRunLeavesTheDatabaseUntouched(): void
    {
        $userId = $this->insertUser('commerce_pipeline_dry', time() - (400 * self::DAY));

        $report = $this->runner->run(JournalEntry::ACTOR_CLI, true);

        $this->assertSame(1, $this->isActive($userId), 'A dry run must not write to admin_user.');
        $this->assertTrue($report->hasChanges(), 'It must still report what it would have done.');
        $this->assertSame(
            [],
            $this->journal->getDeactivatedAt([$userId]),
            'A simulated deactivation must not later authorise a real deletion.'
        );
    }

    /**
     * The account is only removed once its recorded deactivation is old enough,
     * and its `authorization_role` row goes with it.
     */
    public function testAnAccountDeactivatedLongEnoughAgoIsDeletedWithItsRole(): void
    {
        $userId = $this->insertUser('commerce_pipeline_delete', time() - (900 * self::DAY), 0);
        $this->insertRole($userId);
        $this->recordDeactivation($userId, 'commerce_pipeline_delete', time() - (400 * self::DAY));

        $this->runner->run(JournalEntry::ACTOR_CLI, false);

        $this->assertNull($this->isActive($userId), 'The account row should be gone.');
        $this->assertSame(0, $this->countRoleRows($userId), 'The permission row must go with the account.');
    }

    /**
     * An account switched off by hand has no journal entry, so the module has
     * no evidence of when it happened.
     */
    public function testAnUnrecordedInactiveAccountIsAdoptedRatherThanDeleted(): void
    {
        $userId = $this->insertUser('commerce_pipeline_adopt', time() - (2000 * self::DAY), 0);

        $this->runner->run(JournalEntry::ACTOR_CLI, false);

        $this->assertSame(0, $this->isActive($userId), 'The account must survive its first sighting.');
        $this->assertNotEmpty(
            $this->journal->getDeactivatedAt([$userId]),
            'The adoption must start a clock the next pass can measure.'
        );
    }

    /**
     * The rule that keeps this module from locking everybody out.
     */
    public function testItRefusesToDeactivateTheLastActiveAdministrator(): void
    {
        $this->configure(['protect/min_active_admins' => '999', 'delete/enabled' => '0']);

        $userId = $this->insertUser('commerce_pipeline_last', time() - (400 * self::DAY));

        $this->runner->run(JournalEntry::ACTOR_CLI, false);

        $this->assertSame(1, $this->isActive($userId));
    }

    /**
     * @param array<string, string> $values
     */
    private function configure(array $values): void
    {
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);

        foreach ($values as $path => $value) {
            $config->setValue(self::SECTION . '/' . $path, $value);
        }
    }

    private function insertUser(string $username, int $logdate, int $isActive = 1): int
    {
        $connection = $this->resource->getConnection();

        $connection->insert($this->userTable, [
            'firstname' => 'Pipeline',
            'lastname' => 'Account',
            'email' => $username . '@example.test',
            'username' => $username,
            // Not a usable credential: these rows exist to be selected, never
            // to be authenticated against.
            'password' => str_repeat('x', 60),
            'created' => gmdate('Y-m-d H:i:s', time() - (1000 * self::DAY)),
            'modified' => gmdate('Y-m-d H:i:s'),
            'logdate' => gmdate('Y-m-d H:i:s', $logdate),
            'is_active' => $isActive,
        ]);

        return (int) $connection->lastInsertId($this->userTable);
    }

    private function insertRole(int $userId): void
    {
        $this->resource->getConnection()->insert($this->roleTable, [
            'parent_id' => 1,
            'tree_level' => 2,
            'sort_order' => 0,
            'role_type' => UserRole::ROLE_TYPE,
            'user_id' => $userId,
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
            'role_name' => 'Pipeline Account',
        ]);
    }

    private function recordDeactivation(int $userId, string $username, int $occurredAt): void
    {
        $this->journal->recordAll([
            new JournalEntry(
                $userId,
                $username,
                $username . '@example.test',
                JournalEntry::ACTION_DEACTIVATED,
                'dormant',
                JournalEntry::ACTOR_CLI,
                false,
                $occurredAt
            ),
        ]);
    }

    private function isActive(int $userId): ?int
    {
        $connection = $this->resource->getConnection();

        $value = $connection->fetchOne(
            $connection->select()->from($this->userTable, ['is_active'])->where('user_id = ?', $userId)
        );

        return $value === false || $value === null ? null : (int) $value;
    }

    private function countRoleRows(int $userId): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->roleTable, ['count' => 'COUNT(*)'])
                ->where('user_id = ?', $userId)
                ->where('user_type = ?', UserContextInterface::USER_TYPE_ADMIN)
        );
    }
}
