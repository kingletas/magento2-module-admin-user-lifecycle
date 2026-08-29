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
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\StageResult;

/**
 * Stage 3: remove accounts that have been switched off long enough.
 */
class DeleteDeactivatedUsers extends AbstractStage
{
    public const NAME = 'delete';

    public function __construct(
        StageContext $context,
        private readonly AdminUserFinderInterface $finder,
        private readonly LifecycleJournalInterface $journal,
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
        if (!$this->config->isDeletionEnabled($context->getStoreId())) {
            return new StageResult(false, self::NAME);
        }

        $acted = [];
        $skipped = [];
        $failed = [];

        $examined = $this->eachPage(
            fn (int $limit, int $after): array => $this->finder->findInactive($limit, $after),
            function (array $page) use ($context, &$acted, &$skipped, &$failed): void {
                // One query for the whole page rather than one per user.
                $deactivatedAt = $this->journal->getDeactivatedAt($this->userIdsOf($page));

                foreach ($page as $candidate) {
                    $entry = $this->transition->delete(
                        $candidate,
                        $deactivatedAt[$candidate->getUserId()] ?? null,
                        $context
                    );

                    match ($entry->getAction()) {
                        JournalEntry::ACTION_DELETED => $acted[] = $entry,
                        JournalEntry::ACTION_FAILED => $failed[] = $entry,
                        default => $skipped[] = $entry,
                    };
                }
            },
            $context->getStoreId()
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
}
