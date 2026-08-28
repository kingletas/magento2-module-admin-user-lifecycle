<?php
/**
 * InMemoryDirectory.php
 *
 * @package     Commerce_AdminUserLifecycle
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Support;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\AdminUserWriterInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;

/**
 * The `admin_user` table, in an array, and writeable.
 */
class InMemoryDirectory implements AdminUserFinderInterface, AdminUserWriterInterface
{
    /** @var array<int, Candidate> Keyed by user id. */
    private array $users = [];

    /** @var int[] Users deleted, in order. */
    public array $deleted = [];

    /** @var int[] Users deactivated, in order. */
    public array $deactivated = [];

    /**
     * Move every account's history further into the past.
     */
    public function shiftBack(int $seconds): void
    {
        foreach ($this->users as $userId => $candidate) {
            $this->users[$userId] = new Candidate(
                $candidate->getUserId(),
                $candidate->getUsername(),
                $candidate->getEmail(),
                $candidate->getName(),
                $candidate->isActive(),
                $candidate->getLastLoginAt() === null ? null : $candidate->getLastLoginAt() - $seconds,
                $candidate->getCreatedAt() - $seconds,
                $candidate->getRoleId()
            );
        }
    }

    public function add(Candidate $candidate): self
    {
        $this->users[$candidate->getUserId()] = $candidate;

        return $this;
    }

    /**
     * @return Candidate[]
     */
    public function findDormant(
        int $dormantSeconds,
        int $graceSeconds,
        int $now,
        int $limit,
        int $afterUserId
    ): array {
        $found = [];

        foreach ($this->users as $userId => $candidate) {
            if ($userId <= $afterUserId || !$candidate->isActive()) {
                continue;
            }

            // Never signed in: measured from creation, so a brand-new account
            // is not dormant and an abandoned one eventually is.
            $anchor = $candidate->getActivityAnchor();

            if ($now - $anchor < $dormantSeconds) {
                continue;
            }

            if ($now - $candidate->getCreatedAt() < $graceSeconds) {
                continue;
            }

            $found[] = $candidate;

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * @return Candidate[]
     */
    public function findInactive(int $limit, int $afterUserId): array
    {
        $found = [];

        foreach ($this->users as $userId => $candidate) {
            if ($userId <= $afterUserId || $candidate->isActive()) {
                continue;
            }

            $found[] = $candidate;

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function countActive(): int
    {
        return count(array_filter($this->users, static fn (Candidate $c): bool => $c->isActive()));
    }

    public function getById(int $userId): ?Candidate
    {
        return $this->users[$userId] ?? null;
    }

    public function deactivate(int $userId): bool
    {
        $candidate = $this->users[$userId] ?? null;

        if ($candidate === null || !$candidate->isActive()) {
            return false;
        }

        $this->users[$userId] = new Candidate(
            $candidate->getUserId(),
            $candidate->getUsername(),
            $candidate->getEmail(),
            $candidate->getName(),
            false,
            $candidate->getLastLoginAt(),
            $candidate->getCreatedAt(),
            $candidate->getRoleId()
        );
        $this->deactivated[] = $userId;

        return true;
    }

    public function delete(int $userId): bool
    {
        if (!isset($this->users[$userId])) {
            return false;
        }

        unset($this->users[$userId]);
        $this->deleted[] = $userId;

        return true;
    }

    public function isActive(int $userId): bool
    {
        return ($this->users[$userId] ?? null)?->isActive() ?? false;
    }

    public function exists(int $userId): bool
    {
        return isset($this->users[$userId]);
    }

    /**
     * @return int[]
     */
    public function userIds(): array
    {
        return array_keys($this->users);
    }
}
