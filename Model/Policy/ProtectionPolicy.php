<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Policy;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;

/**
 * The accounts this module refuses to touch, and why.
 */
class ProtectionPolicy
{
    public const REASON_PROTECTED_USERNAME = 'username is on the protected list';
    public const REASON_PROTECTED_ROLE = 'belongs to a protected role';
    public const REASON_MIN_ACTIVE_ADMINS = 'would drop active administrators below the configured minimum';
    public const REASON_ALREADY_INACTIVE = 'already inactive';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Why this account must not be deactivated, or null when it may be.
     *
     * @param int $remainingActive How many active administrators are left,
     *                             counting this one, at this point in the pass.
     */
    public function blockDeactivation(
        Candidate $candidate,
        int $remainingActive,
        ?int $storeId = null
    ): ?string {
        if (!$candidate->isActive()) {
            return self::REASON_ALREADY_INACTIVE;
        }

        $listed = $this->blockByList($candidate, $storeId);

        if ($listed !== null) {
            return $listed;
        }

        // Evaluated per candidate rather than once per pass, because each
        // deactivation moves the count.
        if ($remainingActive <= $this->config->getMinActiveAdmins($storeId)) {
            return self::REASON_MIN_ACTIVE_ADMINS;
        }

        return null;
    }

    /**
     * Why this account must not be deleted, or null when it may be.
     */
    public function blockDeletion(Candidate $candidate, ?int $storeId = null): ?string
    {
        if ($candidate->isActive()) {
            // Belt and braces against a candidate reactivated between the query
            // and the write.
            return 'account was reactivated since it was selected';
        }

        return $this->blockByList($candidate, $storeId);
    }

    private function blockByList(Candidate $candidate, ?int $storeId): ?string
    {
        $username = mb_strtolower($candidate->getUsername());

        if (in_array($username, $this->config->getProtectedUsernames($storeId), true)) {
            return self::REASON_PROTECTED_USERNAME;
        }

        $roleId = $candidate->getRoleId();

        if ($roleId !== null && in_array($roleId, $this->config->getProtectedRoleIds($storeId), true)) {
            return self::REASON_PROTECTED_ROLE;
        }

        return null;
    }
}
