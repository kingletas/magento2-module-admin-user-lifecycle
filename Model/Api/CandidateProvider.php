<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleCandidateInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleCandidateProviderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Data\LifecycleCandidate;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Magento\Framework\Exception\InputException;

/**
 * What each stage would act on, answered without acting.
 */
class CandidateProvider implements LifecycleCandidateProviderInterface
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly Config $config,
        private readonly AdminUserFinderInterface $finder,
        private readonly LifecycleJournalInterface $journal,
        private readonly InactivityPolicy $inactivity,
        private readonly ProtectionPolicy $protection,
        private readonly Instant $instant
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getList(
        string $stage,
        int $limit = 200,
        int $afterUserId = 0,
        ?int $storeId = null
    ): array {
        $now = time();
        $page = max(1, min($this->config->getBatchSize($storeId), $limit));
        $cursor = max(0, $afterUserId);

        return match ($stage) {
            self::STAGE_WARN => $this->warnCandidates($now, $page, $cursor, $storeId),
            self::STAGE_DEACTIVATE => $this->deactivateCandidates($now, $page, $cursor, $storeId),
            self::STAGE_DELETE => $this->deleteCandidates($now, $page, $cursor, $storeId),
            default => throw new InputException(
                __(
                    'Unknown lifecycle stage "%1". The stages are: %2.',
                    $stage,
                    implode(', ', [self::STAGE_WARN, self::STAGE_DEACTIVATE, self::STAGE_DELETE])
                )
            ),
        };
    }

    /**
     * @return LifecycleCandidateInterface[]
     */
    private function warnCandidates(int $now, int $limit, int $cursor, ?int $storeId): array
    {
        $candidates = $this->finder->findDormant(
            $this->config->getWarningLeadSeconds($storeId),
            $this->config->getNewAccountGraceSeconds($storeId),
            $now,
            $limit,
            $cursor
        );

        $rows = [];

        foreach ($candidates as $candidate) {
            $rows[] = $this->toRow(
                $candidate,
                self::STAGE_WARN,
                $now,
                $this->inactivity->isDueForWarning($candidate, $now, $storeId),
                $this->inactivity->getDeactivationDueAt($candidate, $storeId),
                // Only the list-based exclusions apply: the administrator floor
                // cannot block a warning.
                $this->protection->blockDeactivation($candidate, PHP_INT_MAX, $storeId),
                null
            );
        }

        return $rows;
    }

    /**
     * @return LifecycleCandidateInterface[]
     */
    private function deactivateCandidates(int $now, int $limit, int $cursor, ?int $storeId): array
    {
        $candidates = $this->finder->findDormant(
            $this->config->getInactiveSeconds($storeId),
            $this->config->getNewAccountGraceSeconds($storeId),
            $now,
            $limit,
            $cursor
        );

        // The floor is read once for the listing and each account is judged
        // against it independently.
        $active = $this->finder->countActive();
        $rows = [];

        foreach ($candidates as $candidate) {
            $rows[] = $this->toRow(
                $candidate,
                self::STAGE_DEACTIVATE,
                $now,
                $this->inactivity->isDueForDeactivation($candidate, $now, $storeId),
                $this->inactivity->getDeactivationDueAt($candidate, $storeId),
                $this->protection->blockDeactivation($candidate, $active, $storeId),
                null
            );
        }

        return $rows;
    }

    /**
     * @return LifecycleCandidateInterface[]
     */
    private function deleteCandidates(int $now, int $limit, int $cursor, ?int $storeId): array
    {
        $candidates = $this->finder->findInactive($limit, $cursor);

        if ($candidates === []) {
            return [];
        }

        // One journal read for the whole page.
        $ids = [];

        foreach ($candidates as $candidate) {
            $ids[] = $candidate->getUserId();
        }

        $deactivatedAt = $this->journal->getDeactivatedAt($ids);
        $window = $this->config->getDeleteAfterSeconds($storeId);
        $rows = [];

        foreach ($candidates as $candidate) {
            $recorded = $deactivatedAt[$candidate->getUserId()] ?? null;

            $rows[] = $this->toRow(
                $candidate,
                self::STAGE_DELETE,
                $now,
                $this->inactivity->isDueForDeletion($recorded, $now, $storeId),
                // No recorded deactivation means no clock.
                $recorded === null ? null : $recorded + $window,
                $recorded === null ? null : $this->protection->blockDeletion($candidate, $storeId),
                $recorded
            );
        }

        return $rows;
    }

    private function toRow(
        Candidate $candidate,
        string $stage,
        int $now,
        bool $due,
        ?int $dueAt,
        ?string $blockedReason,
        ?int $deactivatedAt
    ): LifecycleCandidateInterface {
        return new LifecycleCandidate(
            $candidate->getUserId(),
            $candidate->getUsername(),
            $candidate->getEmail(),
            $candidate->getName(),
            $candidate->isActive(),
            $this->instant->formatOrNull($candidate->getLastLoginAt()),
            $this->instant->format($candidate->getCreatedAt()),
            $candidate->getRoleId(),
            $stage,
            $due,
            $this->instant->formatOrNull($dueAt),
            $blockedReason,
            (int) floor($this->inactivity->getDormantSeconds($candidate, $now) / self::SECONDS_PER_DAY),
            $this->instant->formatOrNull($deactivatedAt)
        );
    }
}
