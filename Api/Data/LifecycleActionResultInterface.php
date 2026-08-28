<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api\Data;

/**
 * What happened when the API was asked to act on one account.
 */
interface LifecycleActionResultInterface
{
    public const string USER_ID = 'user_id';
    public const string ACTION = 'action';
    public const string APPLIED = 'applied';
    public const string DRY_RUN = 'dry_run';
    public const string REASON = 'reason';
    public const string OCCURRED_AT = 'occurred_at';

    /**
     * @return int The account the request was about.
     */
    public function getUserId(): int;

    /**
     * @return string What was recorded: deactivated, deleted, skipped or failed.
     */
    public function getAction(): string;

    /**
     * @return bool Whether the store actually changed. False for a refusal, a
     *              failure, and every dry run.
     */
    public function isApplied(): bool;

    /**
     * @return bool Whether the request was a simulation.
     */
    public function isDryRun(): bool;

    /**
     * @return string Why it was applied, or which rule refused it.
     */
    public function getReason(): string;

    /**
     * @return string When the decision was taken, ISO-8601 UTC.
     */
    public function getOccurredAt(): string;
}
