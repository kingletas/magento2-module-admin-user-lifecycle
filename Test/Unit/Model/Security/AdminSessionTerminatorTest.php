<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Security;

use Commerce\AdminUserLifecycle\Model\Security\AdminSessionTerminator;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ResourceModel\AdminSessionInfo as AdminSessionInfoResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class AdminSessionTerminatorTest extends TestCase
{
    private AdminSessionInfoResource&MockObject $sessionResource;
    private AdminSessionTerminator $terminator;

    protected function setUp(): void
    {
        $this->sessionResource = $this->createMock(AdminSessionInfoResource::class);
        $this->terminator = new AdminSessionTerminator($this->sessionResource, new NullLogger());
    }

    /**
     * Only sessions recorded as logged in are touched, so another reason for
     * ending one survives.
     */
    public function testOnlyLiveSessionsAreEnded(): void
    {
        $this->sessionResource->expects($this->once())
            ->method('updateStatusByUserId')
            ->with(AdminSessionInfo::LOGGED_OUT_MANUALLY, 12, [AdminSessionInfo::LOGGED_IN])
            ->willReturn(3);

        $this->assertSame(3, $this->terminator->terminateFor(12));
    }

    /**
     * An exception ending a session must not stop the remaining accounts being
     * retired.
     */
    public function testAFailingSessionStoreDoesNotStopThePass(): void
    {
        $this->sessionResource->method('updateStatusByUserId')
            ->willThrowException(new RuntimeException('session table is unavailable'));

        $this->assertSame(0, $this->terminator->terminateFor(12));
    }

    public function testAnInvalidUserIdIsRefusedWithoutTouchingTheStore(): void
    {
        $this->sessionResource->expects($this->never())->method('updateStatusByUserId');

        $this->assertSame(0, $this->terminator->terminateFor(0));
    }
}
