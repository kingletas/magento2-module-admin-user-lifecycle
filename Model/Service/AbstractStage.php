<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Service;

use Commerce\AdminUserLifecycle\Api\LifecycleStageInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Psr\Log\LoggerInterface;

/**
 * The paging and bookkeeping every stage shares.
 */
abstract class AbstractStage implements LifecycleStageInterface
{
    /**
     * A backstop, not an expectation.
     */
    private const MAX_PAGES = 10000;

    protected readonly Config $config;

    protected readonly LoggerInterface $logger;

    protected readonly JournalEntryMapper $entryMapper;

    public function __construct(StageContext $context)
    {
        $this->config = $context->config;
        $this->logger = $context->logger;
        $this->entryMapper = $context->entryMapper;
    }

    /**
     * Walk every page a fetcher produces.
     *
     * @param callable(int, int): Candidate[] $fetch  (limit, afterUserId)
     * @param callable(Candidate[]): void $handlePage
     */
    protected function eachPage(callable $fetch, callable $handlePage, ?int $storeId): int
    {
        $limit = $this->config->getBatchSize($storeId);
        $cursor = 0;
        $examined = 0;
        $pages = 0;

        while ($pages < self::MAX_PAGES) {
            $pages++;
            $page = $fetch($limit, $cursor);

            if ($page === []) {
                break;
            }

            $examined += count($page);
            $handlePage($page);

            $highest = $this->highestUserId($page);

            if ($highest <= $cursor) {
                // The fetcher is not advancing.
                $this->logger->error(
                    sprintf(
                        'Admin user lifecycle stage "%s" stopped: the cursor did not advance past user %d.',
                        $this->getName(),
                        $cursor
                    )
                );

                break;
            }

            $cursor = $highest;

            if (count($page) < $limit) {
                break;
            }
        }

        return $examined;
    }

    /**
     * @param Candidate[] $page
     * @return int[]
     */
    protected function userIdsOf(array $page): array
    {
        $ids = [];

        foreach ($page as $candidate) {
            $ids[] = $candidate->getUserId();
        }

        return $ids;
    }

    protected function skip(Candidate $candidate, string $reason, RunContext $context): JournalEntry
    {
        return $this->entryMapper->fromCandidate($candidate, JournalEntry::ACTION_SKIPPED, $reason, $context);
    }

    protected function fail(Candidate $candidate, string $reason, RunContext $context): JournalEntry
    {
        return $this->entryMapper->fromCandidate($candidate, JournalEntry::ACTION_FAILED, $reason, $context);
    }

    /**
     * @param Candidate[] $page
     */
    private function highestUserId(array $page): int
    {
        $highest = 0;

        foreach ($page as $candidate) {
            $highest = max($highest, $candidate->getUserId());
        }

        return $highest;
    }
}
