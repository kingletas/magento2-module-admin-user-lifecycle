<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Security;

use Commerce\AdminUserLifecycle\Model\Security\NullSessionTerminator;
use PHPUnit\Framework\TestCase;

final class NullSessionTerminatorTest extends TestCase
{
    /**
     * A deployment without Magento_Security loses the capability rather than
     * fataling.
     */
    public function testItReportsHavingEndedNothing(): void
    {
        self::assertSame(0, (new NullSessionTerminator())->terminateFor(12));
    }
}
