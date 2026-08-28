<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Psr\Log\LoggerInterface;

/**
 * The three things every stage needs and none of them is about.
 */
class StageContext
{
    public function __construct(
        public readonly Config $config,
        public readonly LoggerInterface $logger,
        public readonly JournalEntryMapper $entryMapper
    ) {
    }
}
