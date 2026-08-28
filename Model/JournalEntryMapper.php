<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * Turns an account and a pass into an audit entry.
 */
class JournalEntryMapper
{
    public function fromCandidate(
        Candidate $candidate,
        string $action,
        string $reason,
        RunContext $context
    ): JournalEntry {
        return new JournalEntry(
            $candidate->getUserId(),
            $candidate->getUsername(),
            $candidate->getEmail(),
            $action,
            $reason,
            $context->getActor(),
            $context->isDryRun(),
            $context->getNow()
        );
    }

    /**
     * An entry describing something that happened to the pass itself rather
     * than to any one account - a stage that threw, for instance.
     */
    public function forRun(string $action, string $reason, RunContext $context): JournalEntry
    {
        return new JournalEntry(
            0,
            '',
            '',
            $action,
            $reason,
            $context->getActor(),
            $context->isDryRun(),
            $context->getNow()
        );
    }
}
