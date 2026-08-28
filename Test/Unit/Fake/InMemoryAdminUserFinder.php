<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Fake;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;

/**
 * An admin user table in an array.
 */
final class InMemoryAdminUserFinder implements AdminUserFinderInterface
{
    /** @var Candidate[] */
    private array $candidates;

    /** @var array<int, array{0: int, 1: int}> */
    public array $dormantCalls = [];

    /**
     * @param Candidate[] $candidates
     */
    public function __construct(array $candidates = [])
    {
        $this->candidates = $candidates;
    }

    public function replace(Candidate $candidate): void
    {
        foreach ($this->candidates as $index => $existing) {
            if ($existing->getUserId() === $candidate->getUserId()) {
                $this->candidates[$index] = $candidate;

                return;
            }
        }

        $this->candidates[] = $candidate;
    }

    public function remove(int $userId): void
    {
        $this->candidates = array_values(array_filter(
            $this->candidates,
            static fn (Candidate $candidate): bool => $candidate->getUserId() !== $userId
        ));
    }

    /**
     * @inheritDoc
     */
    public function findDormant(
        int $dormantSeconds,
        int $graceSeconds,
        int $now,
        int $limit,
        int $afterUserId
    ): array {
        $this->dormantCalls[] = [$dormantSeconds, $afterUserId];

        $activeCutoff = $now - max(0, $dormantSeconds);
        $neverUsedCutoff = $now - max(0, $dormantSeconds, $graceSeconds);

        $matching = array_filter(
            $this->candidates,
            static function (Candidate $candidate) use ($afterUserId, $activeCutoff, $neverUsedCutoff): bool {
                if (!$candidate->isActive() || $candidate->getUserId() <= $afterUserId) {
                    return false;
                }

                return $candidate->hasEverSignedIn()
                    ? (int) $candidate->getLastLoginAt() <= $activeCutoff
                    : $candidate->getCreatedAt() <= $neverUsedCutoff;
            }
        );

        return $this->page($matching, $limit);
    }

    /**
     * @inheritDoc
     */
    public function findInactive(int $limit, int $afterUserId): array
    {
        $matching = array_filter(
            $this->candidates,
            static fn (Candidate $candidate): bool =>
                !$candidate->isActive() && $candidate->getUserId() > $afterUserId
        );

        return $this->page($matching, $limit);
    }

    /**
     * @inheritDoc
     */
    public function countActive(): int
    {
        return count(array_filter(
            $this->candidates,
            static fn (Candidate $candidate): bool => $candidate->isActive()
        ));
    }

    /**
     * @inheritDoc
     */
    public function getById(int $userId): ?Candidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->getUserId() === $userId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param Candidate[] $matching
     * @return Candidate[]
     */
    private function page(array $matching, int $limit): array
    {
        usort(
            $matching,
            static fn (Candidate $one, Candidate $two): int => $one->getUserId() <=> $two->getUserId()
        );

        return array_slice(array_values($matching), 0, max(1, $limit));
    }
}
