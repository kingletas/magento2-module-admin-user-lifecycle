<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Console\Command;

use Commerce\AdminUserLifecycle\Api\ReportNotifierInterface;
use Commerce\AdminUserLifecycle\Console\Command\RunLifecycleCommand;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Notification\ReportFormatter;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use RuntimeException;

final class RunLifecycleCommandTest extends TestCase
{
    private const NOW = 1_760_000_000;

    private LifecycleRunner&MockObject $runner;
    private ReportNotifierInterface&MockObject $reporter;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(LifecycleRunner::class);
        $this->reporter = $this->createMock(ReportNotifierInterface::class);
    }

    public function testItRunsAndPrintsTheReport(): void
    {
        $this->runner->method('run')->willReturn($this->report());

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Admin user lifecycle run', $tester->getDisplay());
    }

    /**
     * `--dry-run` overrides a live configured default rather than merely
     * agreeing with it.
     */
    public function testDryRunOverridesTheConfiguredDefault(): void
    {
        $this->runner->expects(self::once())
            ->method('run')
            ->with(JournalEntry::ACTOR_CLI, true)
            ->willReturn($this->report(dryRun: true));

        $this->tester(['general/dry_run' => '0'])->execute(['--dry-run' => true]);
    }

    /**
     * `--live` is a separate word from `--force`, which only evaluates a
     * disabled module.
     */
    public function testLiveOverridesADryRunDefault(): void
    {
        $this->runner->expects(self::once())
            ->method('run')
            ->with(JournalEntry::ACTOR_CLI, false)
            ->willReturn($this->report());

        $this->tester(['general/dry_run' => '1'])->execute(['--live' => true]);
    }

    public function testContradictoryFlagsAreRefused(): void
    {
        $this->runner->expects(self::never())->method('run');

        $tester = $this->tester();
        $status = $tester->execute(['--dry-run' => true, '--live' => true]);

        self::assertSame(Command::INVALID, $status);
    }

    public function testADisabledModuleIsReportedRatherThanRun(): void
    {
        $this->runner->expects(self::never())->method('run');

        $tester = $this->tester(['general/enabled' => '0']);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('module is disabled', $tester->getDisplay());
    }

    public function testForceEvaluatesADisabledModule(): void
    {
        $this->runner->expects(self::once())->method('run')->willReturn($this->report());

        $this->tester(['general/enabled' => '0'])->execute(['--force' => true]);
    }

    /**
     * Non-zero on failures so a deployment pipeline can gate on this without
     * parsing the output.
     */
    public function testFailuresProduceANonZeroExitStatus(): void
    {
        $this->runner->method('run')->willReturn($this->report(hasFailures: true));

        self::assertSame(Command::FAILURE, $this->tester()->execute([]));
    }

    public function testNoEmailSuppressesTheReportMail(): void
    {
        $this->runner->method('run')->willReturn($this->report());
        $this->reporter->expects(self::never())->method('send');

        $this->tester()->execute(['--no-email' => true]);
    }

    public function testAThrowingRunnerIsReportedAsAFailedCommand(): void
    {
        $this->runner->method('run')->willThrowException(new RuntimeException('the database is unavailable'));

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('the database is unavailable', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function tester(array $overrides = []): CommandTester
    {
        $state = $this->createMock(State::class);

        $command = new RunLifecycleCommand(
            ConfigBuilder::build($overrides),
            $this->runner,
            $this->reporter,
            new ReportFormatter(new Escaper()),
            $state
        );

        $command->setName('commerce:admin-users:lifecycle');

        return new CommandTester($command);
    }

    private function report(bool $dryRun = false, bool $hasFailures = false): RunReport
    {
        $entry = new JournalEntry(
            1,
            'user1',
            'u@example.test',
            JournalEntry::ACTION_FAILED,
            'write refused',
            JournalEntry::ACTOR_CLI,
            $dryRun,
            self::NOW
        );

        return new RunReport(
            new RunContext(JournalEntry::ACTOR_CLI, $dryRun, self::NOW),
            $hasFailures ? [new StageResult(
                true,
                'deactivate',
                [],
                [],
                [$entry],
                1
            )] : [],
            4,
            0.2
        );
    }
}
