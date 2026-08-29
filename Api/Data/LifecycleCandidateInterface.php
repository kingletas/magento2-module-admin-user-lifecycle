<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api\Data;

/**
 * One admin account and the decision this module has already reached about it.
 */
interface LifecycleCandidateInterface
{
    public const string USER_ID = 'user_id';
    public const string USERNAME = 'username';
    public const string EMAIL = 'email';
    public const string NAME = 'name';
    public const string ACTIVE = 'active';
    public const string LAST_LOGIN_AT = 'last_login_at';
    public const string CREATED_AT = 'created_at';
    public const string ROLE_ID = 'role_id';
    public const string STAGE = 'stage';
    public const string DUE = 'due';
    public const string DUE_AT = 'due_at';
    public const string BLOCKED_REASON = 'blocked_reason';
    public const string DORMANT_DAYS = 'dormant_days';
    public const string DEACTIVATED_AT = 'deactivated_at';

    /**
     * @return int The `admin_user.user_id` this row describes.
     */
    public function getUserId(): int;

    /**
     * @return string The account's username.
     */
    public function getUsername(): string;

    /**
     * @return string The address a warning would be sent to.
     */
    public function getEmail(): string;

    /**
     * @return string The person's name, falling back to the username.
     */
    public function getName(): string;

    /**
     * @return bool Whether the account can currently sign in.
     */
    public function isActive(): bool;

    /**
     * @return string|null Last sign-in as ISO-8601 UTC, null if never used.
     */
    public function getLastLoginAt(): ?string;

    /**
     * @return string When the account was created, ISO-8601 UTC.
     */
    public function getCreatedAt(): string;

    /**
     * @return int|null The role granting this account its permissions.
     */
    public function getRoleId(): ?int;

    /**
     * @return string The stage this listing was asked for: warn, deactivate or delete.
     */
    public function getStage(): string;

    /**
     * @return bool Whether the stage would act on this account right now.
     */
    public function isDue(): bool;

    /**
     * @return string|null When the stage falls due, ISO-8601 UTC. Null when no
     *                     date can be computed - a deletion candidate with no
     *                     recorded deactivation has no clock to read.
     */
    public function getDueAt(): ?string;

    /**
     * @return string|null The protection rule that would stop the action, null
     *                     when none applies. A candidate can be due and
     *                     blocked at once, and both facts matter.
     */
    public function getBlockedReason(): ?string;

    /**
     * @return int Whole days since the last sign-in, or since creation for an
     *             account that has never been used.
     */
    public function getDormantDays(): int;

    /**
     * @return string|null When this module recorded the account as deactivated
     *                     or adopted, ISO-8601 UTC. This is the clock deletion
     *                     is measured from, and dry-run passes never set it.
     */
    public function getDeactivatedAt(): ?string;
}
