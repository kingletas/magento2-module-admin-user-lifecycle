<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Notification;

use Commerce\AdminUserLifecycle\Api\ReportNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the pass summary to whoever operates the store.
 */
class ReportEmailNotifier implements ReportNotifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ReportFormatter $formatter,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function send(RunReport $report, ?int $storeId = null): bool
    {
        if (!$this->config->isReportEnabled($storeId)) {
            return false;
        }

        if ($this->config->isReportOnlyWhenChanged($storeId)
            && !$report->hasChanges()
            && !$report->hasFailures()
        ) {
            return false;
        }

        $recipients = $this->config->getReportRecipients($storeId);

        if ($recipients === []) {
            // Not an error.
            $this->logger->debug('Admin user lifecycle report not sent: no recipients configured.');

            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getReportTemplate($storeId))
                ->setTemplateOptions([
                    'area' => Area::AREA_ADMINHTML,
                    'store' => Store::DEFAULT_STORE_ID,
                ])
                ->setTemplateVars([
                    'subject' => $this->buildSubject($report),
                    'summary' => $report->summarise(),
                    'dry_run' => $report->isDryRun(),
                    'has_failures' => $report->hasFailures(),
                    'active_admins' => $report->getActiveAdminsBefore(),
                    // Pre-escaped by the formatter.
                    'stages_html' => $this->formatter->toHtml($report),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($recipients)
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin user lifecycle report could not be sent: ' . $exception->getMessage(),
                ['exception' => $exception::class]
            );

            return false;
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    private function buildSubject(RunReport $report): string
    {
        $tags = [];

        if ($report->isDryRun()) {
            $tags[] = 'DRY RUN';
        }

        if ($report->hasFailures()) {
            $tags[] = 'FAILURES';
        }

        $prefix = $tags === [] ? '' : '[' . implode('][', $tags) . '] ';

        return $prefix . 'Admin user lifecycle report';
    }
}
