<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface;

/**
 * The outcome of one account-level request.
 */
class LifecycleActionResult implements LifecycleActionResultInterface
{
    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly bool $applied,
        private readonly bool $dryRun,
        private readonly string $reason,
        private readonly string $occurredAt
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }
}
