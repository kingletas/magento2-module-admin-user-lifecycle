<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\StageResult;

/**
 * Stage 2: switch dormant accounts off.
 */
class DeactivateInactiveUsers extends AbstractStage
{
    public const NAME = 'deactivate';

    public function __construct(
        StageContext $context,
        private readonly AdminUserFinderInterface $finder,
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

        if (!$this->config->isDeactivationEnabled($storeId)) {
            return new StageResult(false, self::NAME);
        }

        $acted = [];
        $skipped = [];
        $failed = [];
        $remaining = $this->finder->countActive();

        $examined = $this->eachPage(
            fn (int $limit, int $after): array => $this->finder->findDormant(
                $this->config->getInactiveSeconds($storeId),
                $this->config->getNewAccountGraceSeconds($storeId),
                $context->getNow(),
                $limit,
                $after
            ),
            function (array $page) use ($context, &$acted, &$skipped, &$failed, &$remaining): void {
                foreach ($page as $candidate) {
                    $entry = $this->transition->deactivate($candidate, $remaining, $context);

                    match ($entry->getAction()) {
                        JournalEntry::ACTION_DEACTIVATED => $acted[] = $entry,
                        JournalEntry::ACTION_SKIPPED => $skipped[] = $entry,
                        default => $failed[] = $entry,
                    };

                    if ($entry->getAction() === JournalEntry::ACTION_DEACTIVATED && !$context->isDryRun()) {
                        // The floor is counted down as the pass proceeds.
                        $remaining--;
                    }
                }
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
}
