<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Security;

use Commerce\AdminUserLifecycle\Api\SessionTerminatorInterface;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ResourceModel\AdminSessionInfo as AdminSessionInfoResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Marks a retired account's live admin sessions as logged out.
 */
class AdminSessionTerminator implements SessionTerminatorInterface
{
    public function __construct(
        private readonly AdminSessionInfoResource $sessionResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function terminateFor(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            return (int) $this->sessionResource->updateStatusByUserId(
                AdminSessionInfo::LOGGED_OUT_MANUALLY,
                $userId,
                [AdminSessionInfo::LOGGED_IN]
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                sprintf(
                    'Could not end admin sessions for user %d after deactivation: %s',
                    $userId,
                    $exception->getMessage()
                ),
                ['exception' => $exception::class]
            );

            return 0;
        }
    }
}
