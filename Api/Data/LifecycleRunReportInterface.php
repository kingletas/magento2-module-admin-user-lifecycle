<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api\Data;

/**
 * One whole pass, as the API returns it.
 */
interface LifecycleRunReportInterface
{
    public const string ACTOR = 'actor';
    public const string DRY_RUN = 'dry_run';
    public const string STARTED_AT = 'started_at';
    public const string DURATION_SECONDS = 'duration_seconds';
    public const string ACTIVE_ADMINS_BEFORE = 'active_admins_before';
    public const string CHANGES = 'changes';
    public const string FAILURES = 'failures';
    public const string STAGES = 'stages';

    /**
     * @return string What ran the pass. Always "api" for a pass the REST layer started.
     */
    public function getActor(): string;

    /**
     * @return bool Whether the pass simulated its writes.
     */
    public function isDryRun(): bool;

    /**
     * @return string The instant every threshold in the pass was measured from,
     *                ISO-8601 UTC.
     */
    public function getStartedAt(): string;

    /**
     * @return float How long the pass took, in seconds.
     */
    public function getDurationSeconds(): float;

    /**
     * @return int Active administrators before the pass, so a caller can see
     *             what the floor was counted against.
     */
    public function getActiveAdminsBefore(): int;

    /**
     * @return bool Whether anything in the store actually changed.
     */
    public function hasChanges(): bool;

    /**
     * @return bool Whether any stage failed. A pass reports both: stages after
     *              a failing one still run.
     */
    public function hasFailures(): bool;

    /**
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleStageReportInterface[]
     *         Each stage in the order it ran.
     */
    public function getStages(): array;
}
