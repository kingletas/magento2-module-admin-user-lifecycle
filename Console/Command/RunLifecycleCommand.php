<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Console\Command;

use Commerce\AdminUserLifecycle\Api\ReportNotifierInterface;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Notification\ReportFormatter;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs a pass by hand and prints what it did.
 */
class RunLifecycleCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_LIVE = 'live';
    private const OPTION_FORCE = 'force';
    private const OPTION_NO_EMAIL = 'no-email';

    public function __construct(
        private readonly Config $config,
        private readonly LifecycleRunner $runner,
        private readonly ReportNotifierInterface $reporter,
        private readonly ReportFormatter $formatter,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Run the admin account retirement pass and report what it did')
            ->addOption(
                self::OPTION_DRY_RUN,
                'd',
                InputOption::VALUE_NONE,
                'Report what would happen without writing anything (overrides the configured setting)'
            )
            ->addOption(
                self::OPTION_LIVE,
                null,
                InputOption::VALUE_NONE,
                'Apply the changes even when the configured default is a dry run'
            )
            ->addOption(
                self::OPTION_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Run even when the module is disabled'
            )
            ->addOption(
                self::OPTION_NO_EMAIL,
                null,
                InputOption::VALUE_NONE,
                'Print the report instead of emailing it'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (Throwable) {
            // Already set - which is normal when another command bootstrapped
            // the area first.
        }

        if ($input->getOption(self::OPTION_DRY_RUN) && $input->getOption(self::OPTION_LIVE)) {
            $output->writeln('<error>--dry-run and --live contradict each other.</error>');

            return Command::INVALID;
        }

        if (!$this->config->isEnabled() && !$input->getOption(self::OPTION_FORCE)) {
            $output->writeln(
                '<comment>The module is disabled. Enable it under Stores > Configuration > Commerce >'
                . ' Admin User Lifecycle, or pass --force to evaluate it anyway.</comment>'
            );

            return Command::SUCCESS;
        }

        $dryRun = $this->resolveDryRun($input);

        try {
            $report = $this->runner->run(JournalEntry::ACTOR_CLI, $dryRun);
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln($this->formatter->toPlainText($report));

        if (!$input->getOption(self::OPTION_NO_EMAIL)) {
            $this->reporter->send($report);
        }

        // Non-zero on failures so a deployment pipeline can gate on this
        // without parsing the output.
        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The flags win over the configuration; with neither, the configuration
     * decides.
     */
    private function resolveDryRun(InputInterface $input): ?bool
    {
        if ($input->getOption(self::OPTION_DRY_RUN)) {
            return true;
        }

        if ($input->getOption(self::OPTION_LIVE)) {
            return false;
        }

        return null;
    }
}
