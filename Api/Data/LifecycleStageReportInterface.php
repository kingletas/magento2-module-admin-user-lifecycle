<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api\Data;

/**
 * What one stage of a pass did.
 */
interface LifecycleStageReportInterface
{
    public const string STAGE = 'stage';
    public const string ENABLED = 'enabled';
    public const string EXAMINED = 'examined';
    public const string ACTED = 'acted';
    public const string SKIPPED = 'skipped';
    public const string FAILED = 'failed';
    public const string ENTRIES = 'entries';

    /**
     * @return string The stage's name: warn, deactivate or delete.
     */
    public function getStage(): string;

    /**
     * @return bool Whether the stage ran at all. A disabled stage and a stage
     *              that found nothing both report zero, and they are not the
     *              same answer.
     */
    public function isEnabled(): bool;

    /**
     * @return int Accounts the stage looked at.
     */
    public function getExamined(): int;

    /**
     * @return int Accounts the stage acted on.
     */
    public function getActed(): int;

    /**
     * @return int Accounts a protection rule held back.
     */
    public function getSkipped(): int;

    /**
     * @return int Accounts the stage tried and could not finish.
     */
    public function getFailed(): int;

    /**
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface[]
     *         Every entry the stage produced, in the order it produced them.
     */
    public function getEntries(): array;
}
