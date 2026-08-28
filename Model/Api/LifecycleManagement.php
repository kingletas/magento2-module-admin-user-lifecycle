<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleManagementInterface;
use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use Commerce\AdminUserLifecycle\Model\Api\Converter\RunReportConverter;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\Data\LifecycleActionResult;
use Commerce\AdminUserLifecycle\Model\Event\LifecycleEventDispatcher;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\AccountTransition;
use Commerce\AdminUserLifecycle\Model\Service\LifecycleRunner;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Driving the lifecycle from outside the store.
 */
class LifecycleManagement implements LifecycleManagementInterface
{
    /**
     * Actions that mean the store is different afterwards.
     */
    private const APPLIED = [
        JournalEntry::ACTION_WARNED,
        JournalEntry::ACTION_DEACTIVATED,
        JournalEntry::ACTION_DELETED,
        JournalEntry::ACTION_ADOPTED,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly AdminUserFinderInterface $finder,
        private readonly LifecycleJournalInterface $journal,
        private readonly AccountTransition $transition,
        private readonly LifecycleRunner $runner,
        private readonly LifecycleEventDispatcher $events,
        private readonly RunReportConverter $reports,
        private readonly Instant $instant,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(bool $dryRun = true, ?int $storeId = null): LifecycleRunReportInterface
    {
        $this->requireModuleEnabled($dryRun, $storeId);

        return $this->reports->convert($this->runner->run(self::ACTOR, $dryRun, $storeId));
    }

    /**
     * @inheritDoc
     */
    public function warn(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface {
        $this->requireModuleEnabled($dryRun, $storeId);
        $this->requireStageEnabled(
            $dryRun,
            'warn',
            $this->config->isWarningEnabled($storeId) && $this->config->isDeactivationEnabled($storeId)
        );

        $candidate = $this->load($userId);
        $context = $this->context($dryRun, $storeId);
        $warnedAt = $this->journal->getWarnedAt([$userId])[$userId] ?? null;

        return $this->apply($this->transition->warn($candidate, $warnedAt, $context), $context);
    }

    /**
     * @inheritDoc
     */
    public function deactivate(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface {
        $this->requireModuleEnabled($dryRun, $storeId);
        $this->requireStageEnabled($dryRun, 'deactivate', $this->config->isDeactivationEnabled($storeId));

        $candidate = $this->load($userId);
        $context = $this->context($dryRun, $storeId);

        // Counted afresh for this one account.
        $entry = $this->transition->deactivate($candidate, $this->finder->countActive(), $context);

        return $this->apply($entry, $context);
    }

    /**
     * @inheritDoc
     */
    public function delete(
        int $userId,
        bool $dryRun = true,
        ?int $storeId = null
    ): LifecycleActionResultInterface {
        $this->requireModuleEnabled($dryRun, $storeId);
        $this->requireStageEnabled($dryRun, 'delete', $this->config->isDeletionEnabled($storeId));

        $candidate = $this->load($userId);
        $context = $this->context($dryRun, $storeId);
        $deactivatedAt = $this->journal->getDeactivatedAt([$userId])[$userId] ?? null;

        return $this->apply($this->transition->delete($candidate, $deactivatedAt, $context), $context);
    }

    /**
     * Record it, announce it, and answer.
     */
    private function apply(JournalEntry $entry, RunContext $context): LifecycleActionResultInterface
    {
        $this->record($entry);
        // Dispatched after the journal, so no event describes something the
        // store has no record of.
        $this->events->announce($entry);

        return new LifecycleActionResult(
            $entry->getUserId(),
            $entry->getAction(),
            !$context->isDryRun() && in_array($entry->getAction(), self::APPLIED, true),
            $context->isDryRun(),
            $entry->getReason(),
            $this->instant->format($entry->getOccurredAt())
        );
    }

    /**
     * Recording must not be able to break something that has already happened.
     */
    private function record(JournalEntry $entry): void
    {
        try {
            $this->journal->recordAll([$entry]);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Admin user lifecycle journal could not be written: ' . $exception->getMessage(),
                ['exception' => $exception::class, 'entry' => $entry->describe()]
            );
        }
    }

    private function context(bool $dryRun, ?int $storeId): RunContext
    {
        return new RunContext(self::ACTOR, $dryRun, time(), $storeId);
    }

    private function load(int $userId): Candidate
    {
        $candidate = $this->finder->getById($userId);

        if ($candidate === null) {
            throw new NoSuchEntityException(__('No admin account with id %1.', $userId));
        }

        return $candidate;
    }

    /**
     * A dry run is a question and is always allowed to be asked.
     */
    private function requireModuleEnabled(bool $dryRun, ?int $storeId): void
    {
        if (!$dryRun && !$this->config->isEnabled($storeId)) {
            throw new LocalizedException(
                __(
                    'The admin user lifecycle module is switched off. '
                    . 'Enable it, or ask for a dry run to see what it would do.'
                )
            );
        }
    }

    /**
     * The API can do what the configuration already permits, and nothing more.
     */
    private function requireStageEnabled(bool $dryRun, string $stage, bool $enabled): void
    {
        if (!$dryRun && !$enabled) {
            throw new LocalizedException(
                __('The "%1" stage is switched off in this store\'s configuration.', $stage)
            );
        }
    }
}
