<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Cron;

use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Trims the audit journal.
 */
class PruneJournal
{
    public function __construct(
        private readonly Config $config,
        private readonly LifecycleJournalInterface $journal,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $retention = $this->config->getJournalRetentionSeconds();
        $cutoff = time() - $retention;

        try {
            $deleted = $this->journal->prune($cutoff);

            $this->logger->info(sprintf(
                'Admin user lifecycle journal pruned: %d entries removed, history now starts at %s UTC '
                . '(%d day retention).',
                $deleted,
                gmdate('Y-m-d H:i:s', $cutoff),
                (int) round($retention / 86400)
            ));
        } catch (Throwable $exception) {
            // A journal that grows is a far smaller problem than a failing
            // cron, and nothing reads the old rows.
            $this->logger->error(
                'Admin user lifecycle journal pruning failed: ' . $exception->getMessage(),
                ['exception' => $exception::class]
            );
        }
    }
}
