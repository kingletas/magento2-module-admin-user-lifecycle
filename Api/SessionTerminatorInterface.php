<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

/**
 * Ends whatever admin sessions a retired account still holds.
 */
interface SessionTerminatorInterface
{
    /**
     * @return int Sessions ended. Zero is a normal answer: most retired
     *             accounts have none.
     */
    public function terminateFor(int $userId): int;
}
