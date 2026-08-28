<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface;
use Commerce\AdminUserLifecycle\Api\JournalQueryInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalReaderInterface;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\JournalEntryConverter;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Magento\Framework\Exception\InputException;

/**
 * The audit journal, over REST.
 */
class JournalReader implements LifecycleJournalReaderInterface
{
    /**
     * Everything the journal is allowed to contain.
     */
    private const ACTIONS = [
        JournalEntry::ACTION_WARNED,
        JournalEntry::ACTION_DEACTIVATED,
        JournalEntry::ACTION_ADOPTED,
        JournalEntry::ACTION_DELETED,
        JournalEntry::ACTION_SKIPPED,
        JournalEntry::ACTION_FAILED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly JournalQueryInterface $entries,
        private readonly JournalEntryConverter $converter,
        private readonly Instant $instant
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getEntries(
        ?int $userId = null,
        ?string $action = null,
        ?string $since = null,
        bool $includeSimulated = false,
        int $limit = 200,
        int $afterEntryId = 0
    ): array {
        return $this->converter->convertAll(
            $this->entries->getEntries(
                $userId,
                $this->validateAction($action),
                $this->parseSince($since),
                $includeSimulated,
                max(1, min($this->config->getBatchSize(), $limit)),
                max(0, $afterEntryId)
            )
        );
    }

    private function validateAction(?string $action): ?string
    {
        if ($action === null || $action === '') {
            return null;
        }

        if (!in_array($action, self::ACTIONS, true)) {
            throw new InputException(
                __('Unknown action "%1". The journal records: %2.', $action, implode(', ', self::ACTIONS))
            );
        }

        return $action;
    }

    private function parseSince(?string $since): ?int
    {
        if ($since === null || trim($since) === '') {
            return null;
        }

        $parsed = $this->instant->parse($since);

        if ($parsed === null) {
            throw new InputException(
                __('Could not read "%1" as a date. Use an ISO-8601 instant, e.g. 2026-08-27T00:00:00Z.', $since)
            );
        }

        return $parsed;
    }
}
