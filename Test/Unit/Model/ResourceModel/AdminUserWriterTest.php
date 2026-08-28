<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Model\ResourceModel\AdminUserWriter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminUserWriterTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private UserResource&MockObject $userResource;
    private UserFactory&MockObject $userFactory;
    private AdminUserWriter $writer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->userResource = $this->createMock(UserResource::class);
        $this->userFactory = $this->createMock(UserFactory::class);

        $this->userResource->method('getMainTable')->willReturn('admin_user');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);

        $this->writer = new AdminUserWriter($resource, $this->userResource, $this->userFactory);
    }

    /**
     * The compare half of the compare-and-swap.
     */
    public function testDeactivationIsConditionalOnTheAccountStillBeingActive(): void
    {
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'admin_user',
                ['is_active' => 0],
                ['user_id = ?' => 12, 'is_active = ?' => 1]
            )
            ->willReturn(1);

        self::assertTrue($this->writer->deactivate(12));
    }

    public function testDeactivationReportsFailureWhenNoRowMatched(): void
    {
        $this->connection->method('update')->willReturn(0);

        self::assertFalse($this->writer->deactivate(12));
    }

    public function testAnInvalidUserIdIsRefusedWithoutTouchingTheDatabase(): void
    {
        $this->connection->expects(self::never())->method('update');

        self::assertFalse($this->writer->deactivate(0));
        self::assertFalse($this->writer->delete(-1));
    }

    /**
     * Deletion goes through the core resource model, which also removes the
     * authorization_role row.
     */
    public function testDeletionGoesThroughTheResourceModelSoRolesGoWithIt(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(12);
        $user->method('getIsActive')->willReturn(0);

        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->expects(self::once())->method('load')->with($user, 12);
        $this->userResource->expects(self::once())->method('delete')->with($user);

        self::assertTrue($this->writer->delete(12));
    }

    /**
     * The last guard before an irreversible action.
     */
    public function testAReactivatedAccountIsNotDeleted(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(12);
        $user->method('getIsActive')->willReturn(1);

        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->expects(self::never())->method('delete');

        self::assertFalse($this->writer->delete(12));
    }

    public function testAnAccountThatNoLongerExistsIsNotDeleted(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);

        $this->userFactory->method('create')->willReturn($user);
        $this->userResource->expects(self::never())->method('delete');

        self::assertFalse($this->writer->delete(12));
    }

    /**
     * `AbstractDb::load()` overlays rather than clears, so a reused model
     * carries the previous row.
     */
    public function testEachDeletionUsesItsOwnModelInstance(): void
    {
        $first = $this->createMock(User::class);
        $first->method('getId')->willReturn(1);
        $first->method('getIsActive')->willReturn(0);

        $second = $this->createMock(User::class);
        $second->method('getId')->willReturn(2);
        $second->method('getIsActive')->willReturn(0);

        $this->userFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($first, $second);

        $this->writer->delete(1);
        $this->writer->delete(2);
    }
}
