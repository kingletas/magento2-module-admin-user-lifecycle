<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Support;

use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;
use Commerce\AdminUserLifecycle\Api\UserNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use RuntimeException;

/**
 * A user notifier and a session terminator in one, so a test that needs both
 * can assert on one object.
 */
class RecordingNotifier implements UserNotifierInterface, SessionTerminatorInterface
{
    /** @var array<int, array{0: int, 1: int}> user id, due date */
    public array $warned = [];

    /** @var int[] */
    public array $terminated = [];

    /** @var int[] User ids whose warning should report a delivery failure. */
    public array $undeliverable = [];

    /** @var int[] User ids whose warning should throw. */
    public array $explodeOn = [];

    public int $sessionsPerUser = 0;

    public function warn(Candidate $candidate, int $deactivateAt, ?int $storeId = null): bool
    {
        if (in_array($candidate->getUserId(), $this->explodeOn, true)) {
            throw new RuntimeException('mail transport is down');
        }

        if (in_array($candidate->getUserId(), $this->undeliverable, true)) {
            return false;
        }

        $this->warned[] = [$candidate->getUserId(), $deactivateAt];

        return true;
    }

    public function terminateFor(int $userId): int
    {
        $this->terminated[] = $userId;

        return $this->sessionsPerUser;
    }
}
