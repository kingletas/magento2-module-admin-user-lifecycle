<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

/**
 * The only two writes this module performs on an admin account.
 */
interface AdminUserWriterInterface
{
    /**
     * Switch an account off, only if it is still on.
     *
     * @return bool False means the row was not in the expected state, so no
     *              deactivation happened and none must be recorded.
     */
    public function deactivate(int $userId): bool;

    /**
     * Remove an account, only if it is still inactive.
     *
     * @return bool False means the account was reactivated or already gone.
     */
    public function delete(int $userId): bool;
}
