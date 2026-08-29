<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Support;

use Commerce\AdminUserLifecycle\Api\AdminUserWriterInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\Policy\ProtectionPolicy;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;

/**
 * Wires a real `AccountTransition` the way `di.xml` does.
 */
class TransitionBuilder
{
    public static function build(
        Config $config,
        ?AdminUserWriterInterface $writer = null,
        ?RecordingNotifier $notifier = null
    ): AccountTransition {
        // One object, both roles: warning somebody and ending their sessions
        // are the two things a test usually wants to assert together.
        $notifier ??= new RecordingNotifier();

        return new AccountTransition(
            $config,
            new JournalEntryMapper(),
            $writer ?? new RecordingWriter(),
            new InactivityPolicy($config),
            new ProtectionPolicy($config),
            $notifier,
            $notifier
        );
    }
}
