<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Notification;

use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Notification\UserEmailNotifier;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class UserEmailNotifierTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private TransportBuilder&MockObject $transportBuilder;
    private TransportInterface&MockObject $transport;

    /** @var array<string, mixed> */
    private array $templateVars = [];

    private UserEmailNotifier $notifier;

    protected function setUp(): void
    {
        $this->templateVars = [];
        $this->transport = $this->createMock(TransportInterface::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();
        $this->transportBuilder->method('getTransport')->willReturn($this->transport);
        $this->transportBuilder->method('setTemplateVars')
            ->willReturnCallback(function (array $vars): TransportBuilder {
                $this->templateVars = $vars;

                return $this->transportBuilder;
            });

        $this->notifier = new UserEmailNotifier(
            ConfigBuilder::build(),
            $this->transportBuilder,
            $this->createMock(StateInterface::class),
            new NullLogger()
        );
    }

    public function testTheWarningNamesTheAccountAndTheDate(): void
    {
        $dueAt = self::NOW + (5 * 86400);

        self::assertTrue($this->notifier->warn($this->candidate('ada@example.com'), $dueAt));
        self::assertSame('dormant.user', $this->templateVars['username']);
        self::assertSame('Ada Lovelace', $this->templateVars['name']);
        self::assertSame(gmdate('Y-m-d', $dueAt), $this->templateVars['deactivate_at']);
    }

    /**
     * The stage relies on a false return to record the user as *not* warned, so
     * it tries again next pass.
     */
    #[DataProvider('unusableAddressProvider')]
    public function testAnUnusableAddressIsRefusedWithoutBuildingATransport(string $email): void
    {
        $this->transportBuilder->expects(self::never())->method('getTransport');

        self::assertFalse($this->notifier->warn($this->candidate($email), self::NOW + 86400));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableAddressProvider(): array
    {
        return [
            'empty' => [''],
            'not an address' => ['nobody'],
            'header injection attempt' => ["ada@example.com\nBcc: attacker@example.test"],
        ];
    }

    public function testAFailingTransportIsReportedAsUndeliveredRatherThanThrown(): void
    {
        $this->transport->method('sendMessage')->willThrowException(new RuntimeException('SMTP refused'));

        self::assertFalse($this->notifier->warn($this->candidate('ada@example.com'), self::NOW + 86400));
    }

    public function testDaysRemainingNeverGoNegative(): void
    {
        $this->notifier->warn($this->candidate('ada@example.com'), self::NOW - 100000);

        self::assertSame(0, $this->templateVars['days_left']);
    }

    private function candidate(string $email): Candidate
    {
        return new Candidate(
            7,
            'dormant.user',
            $email,
            'Ada Lovelace',
            true,
            self::NOW - (85 * 86400),
            self::NOW - (900 * 86400)
        );
    }
}
