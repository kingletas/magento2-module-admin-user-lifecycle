<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Security;

use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;

/**
 * Does nothing, for deployments without Magento_Security's session registry.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class NullSessionTerminator implements SessionTerminatorInterface
{
    /**
     * @inheritDoc
     */
    public function terminateFor(int $userId): int
    {
        return 0;
    }
}
