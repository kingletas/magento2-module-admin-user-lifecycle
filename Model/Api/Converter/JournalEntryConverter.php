<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api\Converter;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface;
use Commerce\AdminUserLifecycle\Model\Data\LifecycleJournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntry;

/**
 * Journal rows, as the API returns them.
 */
class JournalEntryConverter
{
    public function __construct(
        private readonly Instant $instant
    ) {
    }

    public function convert(JournalEntry $entry): LifecycleJournalEntryInterface
    {
        return new LifecycleJournalEntry(
            // Zero for an entry that has not been read back from the table.
            $entry->getEntryId() ?? 0,
            $entry->getUserId(),
            $entry->getUsername(),
            $entry->getEmail(),
            $entry->getAction(),
            $entry->getReason(),
            $entry->getActor(),
            $entry->isDryRun(),
            $this->instant->format($entry->getOccurredAt())
        );
    }

    /**
     * @param JournalEntry[] $entries
     * @return LifecycleJournalEntryInterface[]
     */
    public function convertAll(array $entries): array
    {
        $converted = [];

        foreach ($entries as $entry) {
            $converted[] = $this->convert($entry);
        }

        return $converted;
    }
}
