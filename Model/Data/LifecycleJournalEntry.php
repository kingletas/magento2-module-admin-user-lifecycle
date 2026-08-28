<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface;

/**
 * A journal row on its way out over REST.
 */
class LifecycleJournalEntry implements LifecycleJournalEntryInterface
{
    public function __construct(
        private readonly int $entryId,
        private readonly int $userId,
        private readonly string $username,
        private readonly string $email,
        private readonly string $action,
        private readonly string $reason,
        private readonly string $actor,
        private readonly bool $dryRun,
        private readonly string $occurredAt
    ) {
    }

    public function getEntryId(): int
    {
        return $this->entryId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt;
    }
}
