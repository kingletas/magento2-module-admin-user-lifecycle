<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * One line of the audit journal.
 */
class JournalEntry
{
    public const ACTION_WARNED = 'warned';
    public const ACTION_DEACTIVATED = 'deactivated';
    public const ACTION_ADOPTED = 'adopted';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_FAILED = 'failed';

    public const ACTOR_CRON = 'cron';
    public const ACTOR_CLI = 'cli';
    public const ACTOR_API = 'api';

    /**
     * Matches the `reason` column.
     */
    public const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly int $userId,
        private readonly string $username,
        private readonly string $email,
        private readonly string $action,
        private readonly string $reason,
        private readonly string $actor,
        private readonly bool $dryRun,
        private readonly int $occurredAt,
        private readonly ?int $entryId = null
    ) {
    }

    /**
     * The row's own identifier, or null for an entry that has not been written
     * yet.
     */
    public function getEntryId(): ?int
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

    public function getOccurredAt(): int
    {
        return $this->occurredAt;
    }

    /**
     * The row as the journal table stores it.
     *
     * @return array<string, int|string>
     */
    public function toRow(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->clip($this->username, 40),
            'email' => $this->clip($this->email, 128),
            'action' => $this->action,
            'reason' => $this->clip($this->reason, self::MAX_REASON_LENGTH),
            'actor' => $this->actor,
            'dry_run' => $this->dryRun ? 1 : 0,
            'occurred_at' => gmdate('Y-m-d H:i:s', $this->occurredAt),
        ];
    }

    public function describe(): string
    {
        return sprintf(
            '%s%s user %d (%s): %s',
            $this->dryRun ? '[dry run] ' : '',
            $this->action,
            $this->userId,
            $this->username,
            $this->reason
        );
    }

    private function clip(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
