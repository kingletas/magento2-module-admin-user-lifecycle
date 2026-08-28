<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Api\AdminUserWriterInterface;
use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;
use Commerce\AdminUserLifecycle\Api\UserNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Throwable;

/**
 * What happens to one account, and the only place it is decided.
 */
class AccountTransition
{
    public function __construct(
        private readonly Config $config,
        private readonly JournalEntryMapper $entryMapper,
        private readonly AdminUserWriterInterface $writer,
        private readonly InactivityPolicy $inactivity,
        private readonly ProtectionPolicy $protection,
        private readonly SessionTerminatorInterface $sessions,
        private readonly UserNotifierInterface $notifier
    ) {
    }

    /**
     * Tell an account's owner it is about to be switched off.
     *
     * @param int|null $warnedAt When this account was last warned, from the
     *                           journal, or null if it never has been.
     */
    public function warn(Candidate $candidate, ?int $warnedAt, RunContext $context): JournalEntry
    {
        $storeId = $context->getStoreId();

        if (!$this->inactivity->isDueForWarning($candidate, $context->getNow(), $storeId)) {
            return $this->skip($candidate, 'not inside the notice window', $context);
        }

        // The active-administrator floor is deliberately passed as PHP_INT_MAX
        // here.
        $blocked = $this->protection->blockDeactivation($candidate, PHP_INT_MAX, $storeId);

        if ($blocked !== null) {
            return $this->skip($candidate, $blocked, $context);
        }

        if ($warnedAt !== null && $this->inactivity->isWarningStillValid($candidate, $warnedAt, $storeId)) {
            return $this->skip($candidate, 'already warned about this deactivation', $context);
        }

        return $this->sendWarning($candidate, $context);
    }

    /**
     * Switch one account off.
     *
     * @param int $remainingActive Active administrators left, counting this
     *                             one, at this point in the pass. Counted down
     *                             rather than checked once, because each
     *                             deactivation moves the number.
     */
    public function deactivate(Candidate $candidate, int $remainingActive, RunContext $context): JournalEntry
    {
        $storeId = $context->getStoreId();

        // Re-checked here even though the query filtered for it.
        if (!$this->inactivity->isDueForDeactivation($candidate, $context->getNow(), $storeId)) {
            return $this->skip($candidate, 'not dormant long enough', $context);
        }

        $blocked = $this->protection->blockDeactivation($candidate, $remainingActive, $storeId);

        if ($blocked !== null) {
            return $this->skip($candidate, $blocked, $context);
        }

        $dormantDays = (int) floor(
            $this->inactivity->getDormantSeconds($candidate, $context->getNow()) / 86400
        );
        $reason = $candidate->hasEverSignedIn()
            ? sprintf('no sign-in for %d days', $dormantDays)
            : sprintf('never signed in, created %d days ago', $dormantDays);

        if ($context->isDryRun()) {
            return $this->record(
                $candidate,
                JournalEntry::ACTION_DEACTIVATED,
                $reason . ' (not applied: dry run)',
                $context
            );
        }

        try {
            $changed = $this->writer->deactivate($candidate->getUserId());
        } catch (Throwable $exception) {
            return $this->fail($candidate, 'deactivation failed: ' . $exception->getMessage(), $context);
        }

        if (!$changed) {
            // Somebody reactivated the account between the query and the write.
            return $this->skip($candidate, 'reactivated while the pass was running', $context);
        }

        $ended = $this->sessions->terminateFor($candidate->getUserId());

        return $this->record(
            $candidate,
            JournalEntry::ACTION_DEACTIVATED,
            $ended > 0 ? sprintf('%s; %d live session(s) ended', $reason, $ended) : $reason,
            $context
        );
    }

    /**
     * Remove one account, permanently.
     *
     * @param int|null $deactivatedAt When this module recorded the account as
     *                                deactivated, from the journal. Null means
     *                                there is no such record, and the account
     *                                is adopted rather than deleted: nothing
     *                                here can tell "switched off two years ago"
     *                                from "switched off this morning", and only
     *                                one of those is safe to act on.
     */
    public function delete(Candidate $candidate, ?int $deactivatedAt, RunContext $context): JournalEntry
    {
        $storeId = $context->getStoreId();

        if ($deactivatedAt === null) {
            return $this->record(
                $candidate,
                JournalEntry::ACTION_ADOPTED,
                'inactive with no recorded deactivation; the deletion clock starts now',
                $context
            );
        }

        if (!$this->inactivity->isDueForDeletion($deactivatedAt, $context->getNow(), $storeId)) {
            $dueAt = $deactivatedAt + $this->config->getDeleteAfterSeconds($storeId);

            return $this->skip(
                $candidate,
                sprintf(
                    'deactivated on %s UTC, not due until %s',
                    gmdate('Y-m-d', $deactivatedAt),
                    gmdate('Y-m-d', $dueAt)
                ),
                $context
            );
        }

        $blocked = $this->protection->blockDeletion($candidate, $storeId);

        if ($blocked !== null) {
            return $this->skip($candidate, $blocked, $context);
        }

        $reason = sprintf(
            'deactivated on %s UTC, %d days ago',
            gmdate('Y-m-d', $deactivatedAt),
            (int) floor(($context->getNow() - $deactivatedAt) / 86400)
        );

        if ($context->isDryRun()) {
            return $this->record(
                $candidate,
                JournalEntry::ACTION_DELETED,
                $reason . ' (not applied: dry run)',
                $context
            );
        }

        try {
            $deleted = $this->writer->delete($candidate->getUserId());
        } catch (Throwable $exception) {
            return $this->fail($candidate, 'deletion failed: ' . $exception->getMessage(), $context);
        }

        if (!$deleted) {
            return $this->skip($candidate, 'reactivated or already removed while the pass was running', $context);
        }

        return $this->record($candidate, JournalEntry::ACTION_DELETED, $reason, $context);
    }

    private function sendWarning(Candidate $candidate, RunContext $context): JournalEntry
    {
        $storeId = $context->getStoreId();
        $dueAt = $this->inactivity->getDeactivationDueAt($candidate, $storeId);
        $reason = sprintf('due for deactivation on %s UTC', gmdate('Y-m-d H:i', $dueAt));

        if ($context->isDryRun()) {
            return $this->record(
                $candidate,
                JournalEntry::ACTION_WARNED,
                $reason . ' (no mail sent: dry run)',
                $context
            );
        }

        if ($candidate->getEmail() === '') {
            // Recorded as a failure rather than a skip.
            return $this->fail($candidate, 'no email address on the account, cannot warn', $context);
        }

        try {
            $sent = $this->notifier->warn($candidate, $dueAt, $storeId);
        } catch (Throwable $exception) {
            return $this->fail($candidate, 'warning failed: ' . $exception->getMessage(), $context);
        }

        if (!$sent) {
            // Not journalled as warned, so the next pass tries again.
            return $this->fail($candidate, 'warning was not delivered', $context);
        }

        return $this->record($candidate, JournalEntry::ACTION_WARNED, $reason, $context);
    }

    private function record(Candidate $candidate, string $action, string $reason, RunContext $context): JournalEntry
    {
        return $this->entryMapper->fromCandidate($candidate, $action, $reason, $context);
    }

    private function skip(Candidate $candidate, string $reason, RunContext $context): JournalEntry
    {
        return $this->record($candidate, JournalEntry::ACTION_SKIPPED, $reason, $context);
    }

    private function fail(Candidate $candidate, string $reason, RunContext $context): JournalEntry
    {
        return $this->record($candidate, JournalEntry::ACTION_FAILED, $reason, $context);
    }
}
