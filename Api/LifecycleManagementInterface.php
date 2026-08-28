<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;

/**
 * The write half of the out-of-process contract.
 */
interface LifecycleManagementInterface
{
    /**
     * What the journal records as having done the work.
     */
    public const string ACTOR = JournalEntry::ACTOR_API;

    /**
     * Run the whole pipeline once - warn, then deactivate, then delete.
     *
     * @param bool $dryRun Simulate the writes and report what would happen.
     * @param int|null $storeId Store scope the settings are read for.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface
     *         What each stage examined, acted on, held back and failed.
     * @throws \Magento\Framework\Exception\LocalizedException When a live pass
     *         is asked for while the module is switched off. The refusal is an
     *         error rather than a silent downgrade to a dry run: a caller that
     *         believes it retired an account has to be told it did not.
     */
    public function run(bool $dryRun = true, ?int $storeId = null): LifecycleRunReportInterface;

    /**
     * Warn one account's owner that it is about to be switched off.
     *
     * @param int $userId The account whose owner to warn.
     * @param bool $dryRun Decide and report without sending anything.
     * @param int|null $storeId Store scope the settings and the email template
     *                          are read for.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface
     *         Whether the warning went out, and why not if it did not.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is
     * no such admin account.
     * @throws \Magento\Framework\Exception\LocalizedException When a live
     *         action is asked for while the module, or the stage, is switched
     *         off.
     */
    public function warn(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface;

    /**
     * Deactivate one account, if the policy agrees it is due.
     *
     * @param int $userId The account to switch off.
     * @param bool $dryRun Decide and report without writing.
     * @param int|null $storeId Store scope the settings are read for.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface
     *         Whether the store changed, and why.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is
     *         no such admin account.
     * @throws \Magento\Framework\Exception\LocalizedException When a live
     *         action is asked for while the module is switched off.
     */
    public function deactivate(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface;

    /**
     * Delete one account, if the policy agrees it is due.
     *
     * @param int $userId The account to remove.
     * @param bool $dryRun Decide and report without writing.
     * @param int|null $storeId Store scope the settings are read for.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface
     * Whether the store changed, and why.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When there is
     * no such admin account.
     * @throws \Magento\Framework\Exception\LocalizedException When a live
     *         action is asked for while the module, or the deletion stage, is
     *         switched off.
     */
    public function delete(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface;
}
