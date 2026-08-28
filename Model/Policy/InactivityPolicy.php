<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Policy;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;

/**
 * When an account is due for each stage.
 */
class InactivityPolicy
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getDormantSeconds(Candidate $candidate, int $now): int
    {
        return max(0, $now - $candidate->getActivityAnchor());
    }

    /**
     * Whether a never-used account is still inside its creation grace period.
     */
    public function isInCreationGrace(Candidate $candidate, int $now, ?int $storeId = null): bool
    {
        if ($candidate->hasEverSignedIn()) {
            return false;
        }

        $grace = $this->config->getNewAccountGraceSeconds($storeId);

        return ($now - $candidate->getCreatedAt()) < $grace;
    }

    public function getDeactivationDueAt(Candidate $candidate, ?int $storeId = null): int
    {
        return $candidate->getActivityAnchor() + $this->config->getInactiveSeconds($storeId);
    }

    public function isDueForDeactivation(Candidate $candidate, int $now, ?int $storeId = null): bool
    {
        if ($this->isInCreationGrace($candidate, $now, $storeId)) {
            return false;
        }

        return $now >= $this->getDeactivationDueAt($candidate, $storeId);
    }

    /**
     * Whether the account is close enough to deactivation to be warned.
     */
    public function isDueForWarning(Candidate $candidate, int $now, ?int $storeId = null): bool
    {
        if (!$this->config->isDeactivationEnabled($storeId)) {
            // Nothing is going to happen to this account, so there is nothing
            // to warn about.
            return false;
        }

        if ($this->isInCreationGrace($candidate, $now, $storeId)) {
            return false;
        }

        $dueAt = $this->getDeactivationDueAt($candidate, $storeId);
        $noticeStartsAt = $dueAt - $this->config->getWarningSeconds($storeId);

        return $now >= $noticeStartsAt && $now < $dueAt;
    }

    /**
     * Whether a warning issued at $warnedAt is still the current one.
     */
    public function isWarningStillValid(
        Candidate $candidate,
        int $warnedAt,
        ?int $storeId = null
    ): bool {
        return $warnedAt >= $candidate->getActivityAnchor()
            && $warnedAt >= $this->getDeactivationDueAt($candidate, $storeId)
                - $this->config->getWarningSeconds($storeId);
    }

    /**
     * Whether an account deactivated at $deactivatedAt may now be deleted.
     */
    public function isDueForDeletion(?int $deactivatedAt, int $now, ?int $storeId = null): bool
    {
        if ($deactivatedAt === null) {
            return false;
        }

        return $now >= $deactivatedAt + $this->config->getDeleteAfterSeconds($storeId);
    }
}
