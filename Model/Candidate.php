<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * One admin account, as much of it as this module has any business knowing.
 */
class Candidate
{
    public function __construct(
        private readonly int $userId,
        private readonly string $username,
        private readonly string $email,
        private readonly string $name,
        private readonly bool $active,
        private readonly ?int $lastLoginAt,
        private readonly int $createdAt,
        private readonly ?int $roleId = null
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
        return $this->name !== '' ? $this->name : $this->username;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Last sign-in, or null for an account that has never been used.
     */
    public function getLastLoginAt(): ?int
    {
        return $this->lastLoginAt;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getRoleId(): ?int
    {
        return $this->roleId;
    }

    /**
     * The instant this account's dormancy is measured from.
     */
    public function getActivityAnchor(): int
    {
        return $this->lastLoginAt ?? $this->createdAt;
    }

    public function hasEverSignedIn(): bool
    {
        return $this->lastLoginAt !== null;
    }
}
