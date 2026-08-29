<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Model\ResourceModel\AdminUserFinder;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\User\Model\ResourceModel\User as UserResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminUserFinderTest extends TestCase
{
    private const DAY = 86400;
    private const NOW = 1_760_000_000;

    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private AdminUserFinder $finder;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $conditions = [];

    protected function setUp(): void
    {
        $this->conditions = [];
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);

        $this->select->method('from')->willReturnSelf();
        $this->select->method('joinLeft')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->select->method('limit')->willReturnSelf();
        $this->select->method('where')
            ->willReturnCallback(function (string $condition, $value = null): Select {
                $this->conditions[] = [$condition, $value];

                return $this->select;
            });

        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quote')
            ->willReturnCallback(static fn ($value): string => "'" . $value . "'");
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn (string $value): string => '`' . $value . '`');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);

        $userResource = $this->createMock(UserResource::class);
        $userResource->method('getMainTable')->willReturn('admin_user');

        $roleResource = $this->createMock(RoleResource::class);
        $roleResource->method('getMainTable')->willReturn('authorization_role');

        $this->finder = new AdminUserFinder($resource, $userResource, $roleResource);
    }

    /**
     * The regression that decides whether new accounts survive their first day.
     */
    public function testTheNeverSignedInBranchUsesTheLaterOfTheTwoCutoffs(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->finder->findDormant(30 * self::DAY, 90 * self::DAY, self::NOW, 100, 0);

        $sql = $this->dormancyCondition();

        $this->assertStringContainsString('logdate IS NOT NULL', $sql);
        $this->assertStringContainsString('logdate IS NULL', $sql);
        $this->assertStringContainsString(gmdate('Y-m-d H:i:s', self::NOW - (30 * self::DAY)), $sql);
        $this->assertStringContainsString(
            gmdate('Y-m-d H:i:s', self::NOW - (90 * self::DAY)),
            $sql,
            'The creation grace is longer than the dormancy window here, so it is the one that must apply.'
        );
    }

    public function testTheDormancyCutoffAppliesWhenItIsTheLongerOfTheTwo(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);

        $this->finder->findDormant(200 * self::DAY, 30 * self::DAY, self::NOW, 100, 0);

        $sql = $this->dormancyCondition();
        $expected = gmdate('Y-m-d H:i:s', self::NOW - (200 * self::DAY));

        $this->assertSame(2, substr_count($sql, $expected), 'Both branches should use the 200-day cutoff.');
    }

    public function testPagingIsAKeysetCursorNotAnOffset(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->select->expects($this->never())->method('limitPage');

        $this->finder->findDormant(self::DAY, 0, self::NOW, 50, 417);

        $this->assertContains(['main.user_id > ?', 417], $this->conditions);
    }

    public function testAMissingSignInDateHydratesToNullNotToZero(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            [
                'user_id' => '7',
                'username' => 'newuser',
                'email' => 'new@example.test',
                'firstname' => 'New',
                'lastname' => 'User',
                'is_active' => '1',
                'logdate' => null,
                'created' => '2026-01-01 09:00:00',
                'role_id' => '3',
            ],
        ]);

        $candidates = $this->finder->findDormant(self::DAY, 0, self::NOW, 10, 0);

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]->getLastLoginAt());
        $this->assertFalse($candidates[0]->hasEverSignedIn());
        $this->assertSame(strtotime('2026-01-01 09:00:00 UTC'), $candidates[0]->getActivityAnchor());
        $this->assertSame('New User', $candidates[0]->getName());
        $this->assertSame(3, $candidates[0]->getRoleId());
    }

    /**
     * MySQL's zero date is not a date.
     */
    public function testAZeroDateIsTreatedAsAbsent(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            [
                'user_id' => '7',
                'username' => 'zero',
                'email' => '',
                'firstname' => '',
                'lastname' => '',
                'is_active' => '0',
                'logdate' => '0000-00-00 00:00:00',
                'created' => '0000-00-00 00:00:00',
                'role_id' => null,
            ],
        ]);

        $candidates = $this->finder->findInactive(10, 0);

        $this->assertNull($candidates[0]->getLastLoginAt());
        $this->assertSame(0, $candidates[0]->getCreatedAt());
        $this->assertNull($candidates[0]->getRoleId());
        $this->assertSame('zero', $candidates[0]->getName());
    }

    public function testCountActiveOnlyCountsEnabledAccounts(): void
    {
        $this->connection->method('fetchOne')->willReturn('4');

        $this->assertSame(4, $this->finder->countActive());
        $this->assertContains(['main.is_active = ?', 1], $this->conditions);
    }

    public function testGetByIdReturnsNullWhenTheAccountIsGone(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);

        $this->assertNull($this->finder->getById(99));
    }

    private function dormancyCondition(): string
    {
        foreach ($this->conditions as [$condition, $value]) {
            if (str_contains($condition, 'logdate')) {
                return $condition;
            }
        }

        $this->fail('No dormancy condition was applied to the query.');
    }
}
