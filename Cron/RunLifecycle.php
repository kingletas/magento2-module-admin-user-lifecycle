<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Cron;

use Commerce\AdminUserLifecycle\Api\ReportNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The scheduled pass.
 */
class RunLifecycle
{
    public function __construct(
        private readonly Config $config,
        private readonly LifecycleRunner $runner,
        private readonly ReportNotifierInterface $reporter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->debug('Admin user lifecycle pass skipped: the module is disabled.');

            return;
        }

        if (!$this->config->isCronEnabled()) {
            // The module is on and something else owns the schedule.
            $this->logger->debug('Admin user lifecycle pass skipped: the schedule is turned off.');

            return;
        }

        try {
            $report = $this->runner->run(JournalEntry::ACTOR_CRON);
            $this->reporter->send($report);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin user lifecycle pass failed: ' . $exception->getMessage(),
                ['exception' => $exception::class, 'trace' => $exception->getTraceAsString()]
            );
        }
    }
}
