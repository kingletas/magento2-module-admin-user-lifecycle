<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\Service\AbstractStage;
use Commerce\AdminUserLifecycle\Model\StageResult;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The paging loop every stage inherits.
 */
class AbstractStageTest extends TestCase
{
    use ShippedConfig;

    public function testItWalksEveryPageUntilOneComesBackShort(): void
    {
        // The batch size is what tells the loop a short page is the last one,
        // so it has to match the fixture pages for this to test anything.
        $stage = $this->stage(['general/batch_size' => '3']);
        $pages = [
            $this->candidates(1, 3),
            $this->candidates(4, 6),
            $this->candidates(7, 7),
        ];

        $seen = [];
        $examined = $stage->walk(
            function (int $limit, int $after) use (&$pages): array {
                return array_shift($pages) ?? [];
            },
            function (array $page) use (&$seen): void {
                foreach ($page as $candidate) {
                    $seen[] = $candidate->getUserId();
                }
            }
        );

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $seen);
        $this->assertSame(7, $examined);
    }

    /**
     * The cursor is what makes paging safe while rows are being changed
     * underneath it.
     */
    public function testEachPageStartsAfterTheHighestIdOfThePreviousOne(): void
    {
        $stage = $this->stage(['general/batch_size' => '3']);
        $cursors = [];
        $pages = [$this->candidates(1, 3), $this->candidates(9, 11), []];

        $stage->walk(
            function (int $limit, int $after) use (&$pages, &$cursors): array {
                $cursors[] = $after;

                return array_shift($pages) ?? [];
            },
            static function (array $page): void {
            }
        );

        $this->assertSame([0, 3, 11], $cursors);
    }

    /**
     * A backstop against an unattended infinite loop.
     */
    public function testAStalledCursorStopsTheLoopInsteadOfSpinning(): void
    {
        $stage = $this->stage(['general/batch_size' => '2']);
        $calls = 0;

        $examined = $stage->walk(
            function (int $limit, int $after) use (&$calls): array {
                $calls++;

                return $this->candidates(1, 2);
            },
            static function (array $page): void {
            }
        );

        $this->assertSame(2, $calls, 'The loop must abandon a fetcher that never advances.');
        $this->assertSame(4, $examined);
    }

    public function testAnEmptyFirstPageDoesNoWork(): void
    {
        $handled = false;

        $examined = $this->stage()->walk(
            static fn (int $limit, int $after): array => [],
            static function (array $page) use (&$handled): void {
                $handled = true;
            }
        );

        $this->assertSame(0, $examined);
        $this->assertFalse($handled);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function stage(array $overrides = []): object
    {
        $config = $this->config($overrides);

        return new class (new StageContext($config, new NullLogger(), new JournalEntryMapper())) extends AbstractStage {
            public function getName(): string
            {
                return 'test';
            }

            public function execute(RunContext $context): StageResult
            {
                return new StageResult(false, 'test');
            }

            /**
             * @param callable(int, int): Candidate[] $fetch
             * @param callable(Candidate[]): void $handle
             */
            public function walk(callable $fetch, callable $handle): int
            {
                return $this->eachPage($fetch, $handle, null);
            }
        };
    }

    /**
     * @return Candidate[]
     */
    private function candidates(int $from, int $to): array
    {
        $candidates = [];

        for ($userId = $from; $userId <= $to; $userId++) {
            $candidates[] = new Candidate($userId, 'user' . $userId, 'u@example.test', 'User', true, 1, 1);
        }

        return $candidates;
    }
}
