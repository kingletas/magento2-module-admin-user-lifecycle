<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Notification;

use Commerce\AdminUserLifecycle\Api\UserNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Emails a user that their admin account is about to be deactivated.
 */
class UserEmailNotifier implements UserNotifierInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function warn(Candidate $candidate, int $deactivateAt, ?int $storeId = null): bool
    {
        $email = $candidate->getEmail();

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->getWarningTemplate($storeId))
                ->setTemplateOptions([
                    'area' => Area::AREA_ADMINHTML,
                    'store' => Store::DEFAULT_STORE_ID,
                ])
                ->setTemplateVars([
                    'name' => $candidate->getName(),
                    'username' => $candidate->getUsername(),
                    'deactivate_at' => gmdate('Y-m-d', $deactivateAt),
                    'days_left' => max(0, (int) ceil(($deactivateAt - time()) / 86400)),
                ])
                ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
                ->addTo($email, $candidate->getName())
                ->getTransport();

            $transport->sendMessage();

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    'Could not warn admin user %d about deactivation: %s',
                    $candidate->getUserId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception::class]
            );

            return false;
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
