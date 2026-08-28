<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Cron;

use Commerce\AdminUserLifecycle\Api\ReportNotifierInterface;
use Commerce\AdminUserLifecycle\Cron\RunLifecycle;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\RunReport;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Commerce\AdminUserLifecycle\Test\Unit\Fake\ConfigBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class RunLifecycleTest extends TestCase
{
    private LifecycleRunner&MockObject $runner;
    private ReportNotifierInterface&MockObject $reporter;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(LifecycleRunner::class);
        $this->reporter = $this->createMock(ReportNotifierInterface::class);
    }

    public function testAPassRunsAndIsReported(): void
    {
        $report = $this->report();

        $this->runner->expects(self::once())
            ->method('run')
            ->with(JournalEntry::ACTOR_CRON)
            ->willReturn($report);

        $this->reporter->expects(self::once())->method('send')->with($report);

        $this->cron()->execute();
    }

    /**
     * Installing this module must not start retiring accounts on somebody's
     * next cron tick.
     */
    public function testNothingRunsWhileTheModuleIsDisabled(): void
    {
        $this->runner->expects(self::never())->method('run');
        $this->reporter->expects(self::never())->method('send');

        $this->cron(['general/enabled' => '0'])->execute();
    }

    /**
     * A cron job that throws is marked `error` in `cron_schedule` and can hold
     * up the jobs queued behind it.
     */
    public function testAThrowingPassIsContainedRatherThanFailingTheCronJob(): void
    {
        $this->runner->method('run')->willThrowException(new RuntimeException('the database is unavailable'));

        $this->expectNotToPerformAssertions();

        $this->cron()->execute();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function cron(array $overrides = []): RunLifecycle
    {
        return new RunLifecycle(
            ConfigBuilder::build($overrides),
            $this->runner,
            $this->reporter,
            new NullLogger()
        );
    }

    private function report(): RunReport
    {
        return new RunReport(
            new RunContext(JournalEntry::ACTOR_CRON, false, 1_760_000_000),
            [],
            3,
            0.1
        );
    }
}
