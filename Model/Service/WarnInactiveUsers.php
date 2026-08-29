<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Api\LifecycleJournalInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\Policy\InactivityPolicy;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\StageResult;

/**
 * Stage 1: tell people before their account is switched off.
 */
class WarnInactiveUsers extends AbstractStage
{
    public const NAME = 'warn';

    public function __construct(
        StageContext $context,
        private readonly AdminUserFinderInterface $finder,
        private readonly LifecycleJournalInterface $journal,
        private readonly InactivityPolicy $inactivity,
        private readonly AccountTransition $transition
    ) {
        parent::__construct($context);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     */
    public function execute(RunContext $context): StageResult
    {
        $storeId = $context->getStoreId();

        if (!$this->config->isWarningEnabled($storeId) || !$this->config->isDeactivationEnabled($storeId)) {
            return new StageResult(false, self::NAME);
        }

        $acted = [];
        $skipped = [];
        $failed = [];

        // Everything already inside the notice window, which starts one notice
        // period before the deactivation date.
        $lead = $this->config->getWarningLeadSeconds($storeId);

        $examined = $this->eachPage(
            fn (int $limit, int $after): array => $this->finder->findDormant(
                $lead,
                $this->config->getNewAccountGraceSeconds($storeId),
                $context->getNow(),
                $limit,
                $after
            ),
            function (array $page) use ($context, &$acted, &$skipped, &$failed): void {
                $this->handlePage($page, $context, $acted, $skipped, $failed);
            },
            $storeId
        );

        return new StageResult(
            true,
            self::NAME,
            $acted,
            $skipped,
            $failed,
            $examined
        );
    }

    /**
     * @param \Commerce\AdminUserLifecycle\Model\Candidate[] $page
     * @param JournalEntry[] $acted
     * @param JournalEntry[] $skipped
     * @param JournalEntry[] $failed
     */
    private function handlePage(
        array $page,
        RunContext $context,
        array &$acted,
        array &$skipped,
        array &$failed
    ): void {
        $storeId = $context->getStoreId();
        // One query for the whole page rather than one per user.
        $warnedAt = $this->journal->getWarnedAt($this->userIdsOf($page));

        foreach ($page as $candidate) {
            // The selection query is wider than the notice window, so the rest
            // is filtered here.
            if (!$this->inactivity->isDueForWarning($candidate, $context->getNow(), $storeId)) {
                continue;
            }

            $entry = $this->transition->warn($candidate, $warnedAt[$candidate->getUserId()] ?? null, $context);

            match ($entry->getAction()) {
                JournalEntry::ACTION_WARNED => $acted[] = $entry,
                JournalEntry::ACTION_SKIPPED => $skipped[] = $entry,
                default => $failed[] = $entry,
            };
        }
    }
}
