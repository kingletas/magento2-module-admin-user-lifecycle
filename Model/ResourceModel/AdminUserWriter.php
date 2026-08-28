<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Api\AdminUserWriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;

/**
 * Writes to `admin_user`, conditionally.
 */
class AdminUserWriter implements AdminUserWriterInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UserResource $userResource,
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deactivate(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $connection = $this->resource->getConnection();

        $affected = $connection->update(
            $this->userResource->getMainTable(),
            ['is_active' => 0],
            [
                'user_id = ?' => $userId,
                // The compare half of the swap.
                'is_active = ?' => 1,
            ]
        );

        return $affected > 0;
    }

    /**
     * @inheritDoc
     */
    public function delete(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = $this->userFactory->create();
        $this->userResource->load($user, $userId);

        // A fresh model per call.
        if ((int) $user->getId() !== $userId) {
            return false;
        }

        if ((int) $user->getIsActive() === 1) {
            return false;
        }

        $this->userResource->delete($user);

        return true;
    }
}
