<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Security;

use Commerce\AdminUserLifecycle\Model\Security\NullSessionTerminator;
use PHPUnit\Framework\TestCase;

class NullSessionTerminatorTest extends TestCase
{
    /**
     * A deployment without Magento_Security loses the capability rather than
     * fataling.
     */
    public function testItReportsHavingEndedNothing(): void
    {
        $this->assertSame(0, (new NullSessionTerminator())->terminateFor(12));
    }
}
