<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api\Data;

/**
 * One line of the audit journal, as the API returns it.
 */
interface LifecycleJournalEntryInterface
{
    public const string ENTRY_ID = 'entry_id';
    public const string USER_ID = 'user_id';
    public const string USERNAME = 'username';
    public const string EMAIL = 'email';
    public const string ACTION = 'action';
    public const string REASON = 'reason';
    public const string ACTOR = 'actor';
    public const string DRY_RUN = 'dry_run';
    public const string OCCURRED_AT = 'occurred_at';

    /**
     * @return int The journal row's own identifier, and the paging cursor.
     */
    public function getEntryId(): int;

    /**
     * @return int The account acted on. Zero for an entry about the pass itself.
     */
    public function getUserId(): int;

    /**
     * @return string The username as it was at the time, copied so the record
     *                outlives the account.
     */
    public function getUsername(): string;

    /**
     * @return string The address as it was at the time.
     */
    public function getEmail(): string;

    /**
     * @return string warned, deactivated, adopted, deleted, skipped or failed.
     */
    public function getAction(): string;

    /**
     * @return string Why the action was taken, or which rule stopped it.
     */
    public function getReason(): string;

    /**
     * @return string What ran the pass: cron, cli or api.
     */
    public function getActor(): string;

    /**
     * @return bool Whether the action was simulated rather than applied.
     */
    public function isDryRun(): bool;

    /**
     * @return string When it happened, ISO-8601 UTC.
     */
    public function getOccurredAt(): string;
}
