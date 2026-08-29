<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Event;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Magento\Framework\Event\ManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announces what the module did, for anything listening from outside it.
 */
class LifecycleEventDispatcher
{
    public const string EVENT_WARNED = 'commerce_adminusers_user_warned';
    public const string EVENT_DEACTIVATED = 'commerce_adminusers_user_deactivated';
    public const string EVENT_DELETED = 'commerce_adminusers_user_deleted';
    public const string EVENT_RUN_COMPLETED = 'commerce_adminusers_run_completed';

    /**
     * Which journal actions are worth announcing, and under what name.
     */
    private const ANNOUNCED = [
        JournalEntry::ACTION_WARNED => self::EVENT_WARNED,
        JournalEntry::ACTION_DEACTIVATED => self::EVENT_DEACTIVATED,
        JournalEntry::ACTION_DELETED => self::EVENT_DELETED,
    ];

    public function __construct(
        private readonly ManagerInterface $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param JournalEntry[] $entries
     */
    public function announceAll(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->announce($entry);
        }
    }

    public function announce(JournalEntry $entry): void
    {
        if ($entry->isDryRun()) {
            return;
        }

        $name = self::ANNOUNCED[$entry->getAction()] ?? null;

        if ($name === null) {
            return;
        }

        $this->dispatch($name, [
            'user_id' => $entry->getUserId(),
            'username' => $entry->getUsername(),
            'email' => $entry->getEmail(),
            'action' => $entry->getAction(),
            'reason' => $entry->getReason(),
            'actor' => $entry->getActor(),
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $entry->getOccurredAt()),
        ]);
    }

    /**
     * One event for the whole pass, whether or not it changed anything.
     *
     * @param array<string, int> $counts Entries per action.
     */
    public function announceRun(RunContext $context, array $counts, int $activeAdminsBefore): void
    {
        if ($context->isDryRun()) {
            return;
        }

        $this->dispatch(self::EVENT_RUN_COMPLETED, [
            'actor' => $context->getActor(),
            'store_id' => $context->getStoreId(),
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $context->getNow()),
            'active_admins_before' => $activeAdminsBefore,
            'warned' => $counts[JournalEntry::ACTION_WARNED] ?? 0,
            'deactivated' => $counts[JournalEntry::ACTION_DEACTIVATED] ?? 0,
            'deleted' => $counts[JournalEntry::ACTION_DELETED] ?? 0,
            'skipped' => $counts[JournalEntry::ACTION_SKIPPED] ?? 0,
            'failed' => $counts[JournalEntry::ACTION_FAILED] ?? 0,
        ]);
    }

    /**
     * @param array<string, int|string|null> $payload
     */
    private function dispatch(string $name, array $payload): void
    {
        try {
            $this->eventManager->dispatch($name, $payload);
        } catch (Throwable $exception) {
            // An observer cannot undo a retirement that has already happened.
            $this->logger->error(
                sprintf('Admin user lifecycle event "%s" could not be dispatched: %s', $name, $exception->getMessage()),
                ['exception' => $exception::class]
            );
        }
    }
}
