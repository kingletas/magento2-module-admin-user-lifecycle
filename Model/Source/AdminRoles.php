<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Source;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory;
use Magento\Authorization\Model\Acl\Role\Group;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The admin role groups, for the protection multiselect.
 */
class AdminRoles implements OptionSourceInterface
{
    /** @var array<int, array{value: int|string, label: string}>|null */
    private ?array $options = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string}>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('role_type', Group::ROLE_TYPE)
            ->setOrder('role_name', 'ASC');

        $options = [];

        foreach ($collection as $role) {
            $options[] = [
                'value' => (int) $role->getId(),
                'label' => (string) $role->getRoleName(),
            ];
        }

        return $this->options = $options;
    }
}
