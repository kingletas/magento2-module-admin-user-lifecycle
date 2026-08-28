<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Fake;

use Commerce\AdminUserLifecycle\Api\AdminUserWriterInterface;
use RuntimeException;

/**
 * Records the writes a stage attempted, and can refuse them the way a
 * compare-and-swap against a changed row does.
 */
final class RecordingWriter implements AdminUserWriterInterface
{
    /** @var int[] */
    public array $deactivated = [];

    /** @var int[] */
    public array $deleted = [];

    /** @var int[] User ids whose write should report "no rows matched". */
    public array $refuse = [];

    /** @var int[] User ids whose write should throw. */
    public array $explodeOn = [];

    public function deactivate(int $userId): bool
    {
        if (in_array($userId, $this->explodeOn, true)) {
            throw new RuntimeException('database is unavailable');
        }

        if (in_array($userId, $this->refuse, true)) {
            return false;
        }

        $this->deactivated[] = $userId;

        return true;
    }

    public function delete(int $userId): bool
    {
        if (in_array($userId, $this->explodeOn, true)) {
            throw new RuntimeException('database is unavailable');
        }

        if (in_array($userId, $this->refuse, true)) {
            return false;
        }

        $this->deleted[] = $userId;

        return true;
    }
}
