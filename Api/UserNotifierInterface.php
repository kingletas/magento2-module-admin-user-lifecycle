<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\Candidate;

/**
 * Tells a user their account is about to be deactivated.
 */
interface UserNotifierInterface
{
    /**
     * @param int $deactivateAt UTC timestamp the account is due to be deactivated.
     * @return bool Whether the warning was delivered. False means the user was
     *              not warned, so the stage must not record that they were.
     */
    public function warn(Candidate $candidate, int $deactivateAt, ?int $storeId = null): bool;
}
