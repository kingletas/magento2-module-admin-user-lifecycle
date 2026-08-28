<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\Candidate;

/**
 * Reads admin accounts in the shape this module reasons about.
 */
interface AdminUserFinderInterface
{
    /**
     * Active accounts dormant for at least $dormantSeconds.
     *
     * @param int $now UTC timestamp the cutoffs are computed from, so a pass
     *                 that straddles midnight judges every account against the
     *                 same instant.
     * @return Candidate[] Ordered by user id, at most $limit rows.
     */
    public function findDormant(
        int $dormantSeconds,
        int $graceSeconds,
        int $now,
        int $limit,
        int $afterUserId
    ): array;

    /**
     * Inactive accounts, oldest id first.
     *
     * @return Candidate[] Ordered by user id, at most $limit rows.
     */
    public function findInactive(int $limit, int $afterUserId): array;

    public function countActive(): int;

    public function getById(int $userId): ?Candidate;
}
