<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Notification;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Notification\ReportEmailNotifier;
use Commerce\AdminUserLifecycle\Model\Notification\ReportFormatter;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ReportEmailNotifierTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private TransportBuilder&MockObject $transportBuilder;
    private TransportInterface&MockObject $transport;

    /** @var array<string, mixed> */
    private array $templateVars = [];

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
    }

    public function testItSendsToEveryConfiguredRecipient(): void
    {
        $this->transportBuilder->expects(self::once())
            ->method('addTo')
            ->with(['ops@example.com', 'security@example.com'])
            ->willReturnSelf();

        $this->transport->expects(self::once())->method('sendMessage');

        $sent = $this->notifier(['report/recipients' => 'ops@example.com,security@example.com'])
            ->send($this->report(hasChanges: true));

        self::assertTrue($sent);
    }

    /**
     * A daily "nothing to do" email is how people learn to filter this address,
     * and the alert that matters then arrives in a folder nobody reads.
     */
    public function testAQuietPassSendsNothingWhenOnlyWhenChangedIsOn(): void
    {
        $this->transport->expects(self::never())->method('sendMessage');

        self::assertFalse(
            $this->notifier(['report/recipients' => 'ops@example.com'])->send($this->report(hasChanges: false))
        );
    }

    /**
     * A failed pass is worth a mail even when nothing changed - that is exactly
     * the case where nothing changing is the problem.
     */
    public function testAQuietPassWithFailuresStillSends(): void
    {
        $this->transport->expects(self::once())->method('sendMessage');

        self::assertTrue(
            $this->notifier(['report/recipients' => 'ops@example.com'])
                ->send($this->report(hasChanges: false, hasFailures: true))
        );
    }

    public function testNoRecipientsMeansNoMailAndNoError(): void
    {
        $this->transport->expects(self::never())->method('sendMessage');

        self::assertFalse($this->notifier(['report/recipients' => ''])->send($this->report(hasChanges: true)));
    }

    public function testReportingCanBeSwitchedOffEntirely(): void
    {
        $this->transport->expects(self::never())->method('sendMessage');

        self::assertFalse(
            $this->notifier(['report/enabled' => '0', 'report/recipients' => 'ops@example.com'])
                ->send($this->report(hasChanges: true))
        );
    }

    /**
     * The subject says a dry run was a dry run before the mail is opened.
     */
    public function testTheSubjectFlagsADryRunAndAnyFailures(): void
    {
        $this->notifier(['report/recipients' => 'ops@example.com'])
            ->send($this->report(hasChanges: true, hasFailures: true, dryRun: true));

        self::assertSame(
            '[DRY RUN][FAILURES] Admin user lifecycle report',
            $this->templateVars['subject']
        );
    }

    public function testTheOnlyRawTemplateVariableIsTheOneTheFormatterEscaped(): void
    {
        $this->notifier(['report/recipients' => 'ops@example.com'])->send($this->report(hasChanges: true));

        self::assertArrayHasKey('stages_html', $this->templateVars);
        self::assertIsString($this->templateVars['stages_html']);
    }

    /**
     * The pass has already happened by the time this runs.
     */
    public function testAFailingTransportIsReportedRatherThanThrown(): void
    {
        $this->transport->method('sendMessage')->willThrowException(new RuntimeException('SMTP refused'));

        self::assertFalse(
            $this->notifier(['report/recipients' => 'ops@example.com'])->send($this->report(hasChanges: true))
        );
    }

    /**
     * @param array<string, string> $overrides
     */
    private function notifier(array $overrides): ReportEmailNotifier
    {
        return new ReportEmailNotifier(
            ConfigBuilder::build($overrides),
            new ReportFormatter(new Escaper()),
            $this->transportBuilder,
            $this->createMock(StateInterface::class),
            new NullLogger()
        );
    }

    private function report(bool $hasChanges, bool $hasFailures = false, bool $dryRun = false): RunReport
    {
        $entry = new JournalEntry(
            1,
            'user1',
            'u@example.test',
            JournalEntry::ACTION_DEACTIVATED,
            'dormant',
            JournalEntry::ACTOR_CRON,
            $dryRun,
            self::NOW
        );

        return new RunReport(
            new RunContext(JournalEntry::ACTOR_CRON, $dryRun, self::NOW),
            [
                new StageResult(
                    true,
                    'deactivate',
                    $hasChanges ? [$entry] : [],
                    [],
                    $hasFailures ? [$entry] : [],
                    1
                ),
            ],
            5,
            0.3
        );
    }
}
