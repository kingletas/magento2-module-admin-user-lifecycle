<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

/**
 * Everything one pass needs to know about itself.
 */
class RunContext
{
    /**
     * @param string $actor What ran the pass: cron or cli.
     * @param bool $dryRun The effective value, after the configured default and
     *                     any caller override have been resolved.
     * @param int $now The instant every threshold in this pass is measured from.
     */
    public function __construct(
        private readonly string $actor,
        private readonly bool $dryRun,
        private readonly int $now,
        private readonly ?int $storeId = null
    ) {
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getNow(): int
    {
        return $this->now;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }
}
