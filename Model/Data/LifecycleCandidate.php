<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleCandidateInterface;

/**
 * A candidate on its way out of the store.
 */
class LifecycleCandidate implements LifecycleCandidateInterface
{
    public function __construct(
        private readonly int $userId,
        private readonly string $username,
        private readonly string $email,
        private readonly string $name,
        private readonly bool $active,
        private readonly ?string $lastLoginAt,
        private readonly string $createdAt,
        private readonly ?int $roleId,
        private readonly string $stage,
        private readonly bool $due,
        private readonly ?string $dueAt,
        private readonly ?string $blockedReason,
        private readonly int $dormantDays,
        private readonly ?string $deactivatedAt
    ) {
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

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getRoleId(): ?int
    {
        return $this->roleId;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function isDue(): bool
    {
        return $this->due;
    }

    public function getDueAt(): ?string
    {
        return $this->dueAt;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function getDormantDays(): int
    {
        return $this->dormantDays;
    }

    public function getDeactivatedAt(): ?string
    {
        return $this->deactivatedAt;
    }
}
