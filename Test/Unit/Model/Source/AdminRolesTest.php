<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Source;

use Commerce\AdminUserLifecycle\Model\Source\AdminRoles;
use Magento\Authorization\Model\Acl\Role\Group;
use Magento\Authorization\Model\ResourceModel\Role\Collection;
use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory;
use Magento\Framework\DataObject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminRolesTest extends TestCase
{
    private Collection&MockObject $collection;
    private AdminRoles $source;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->source = new AdminRoles($factory);
    }

    public function testItOffersRoleGroupsAsIntegerValues(): void
    {
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->role(3, 'Administrators'),
            $this->role(7, 'Content Editors'),
        ]));

        $this->assertSame(
            [
                ['value' => 3, 'label' => 'Administrators'],
                ['value' => 7, 'label' => 'Content Editors'],
            ],
            $this->source->toOptionArray()
        );
    }

    /**
     * Only the group rows.
     */
    public function testOnlyRoleGroupsAreQueried(): void
    {
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('role_type', Group::ROLE_TYPE)
            ->willReturnSelf();

        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->source->toOptionArray();
    }

    /**
     * The multiselect is rendered more than once per config page, and each
     * render would otherwise be another query.
     */
    public function testTheCollectionIsOnlyLoadedOnce(): void
    {
        $this->collection->expects($this->once())
            ->method('getIterator')
            ->willReturn(new \ArrayIterator([$this->role(3, 'Administrators')]));

        $this->source->toOptionArray();
        $this->source->toOptionArray();
    }

    /**
     * A plain DataObject rather than a mocked Role, because `getRoleName()` is
     * a magic getter.
     */
    private function role(int $roleId, string $name): DataObject
    {
        return new DataObject(['id' => $roleId, 'role_id' => $roleId, 'role_name' => $name]);
    }
}
