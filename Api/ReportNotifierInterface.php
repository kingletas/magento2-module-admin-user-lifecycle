<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\RunReport;

/**
 * Delivers the summary of one pass to whoever operates the store.
 */
interface ReportNotifierInterface
{
    /**
     * @return bool Whether anything was delivered.
     */
    public function send(RunReport $report, ?int $storeId = null): bool;
}
