<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

/**
 * What each stage would act on, without acting on it.
 */
interface LifecycleCandidateProviderInterface
{
    public const string STAGE_WARN = 'warn';
    public const string STAGE_DEACTIVATE = 'deactivate';
    public const string STAGE_DELETE = 'delete';

    /**
     * The accounts a stage is looking at, in user-id order.
     *
     * @param string $stage One of the STAGE_* constants.
     * @param int $limit Rows per call, capped at the configured batch size.
     * @param int $afterUserId Cursor: the last user id of the previous call.
     * @param int|null $storeId Store scope the settings are read for.
     * @return \Commerce\AdminUserLifecycle\Api\Data\LifecycleCandidateInterface[]
     *         Candidates, each carrying the decision already made about it.
     * @throws \Magento\Framework\Exception\InputException When the stage is not one this module has.
     */
    public function getList(
        string $stage,
        int $limit = 200,
        int $afterUserId = 0,
        ?int $storeId = null
    ): array;
}
