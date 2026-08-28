<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Integration\Model;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\ResourceModel\User as UserResource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The dormancy query, against a real database.
 *
 * @magentoDbIsolation enabled
 */
#[Group('integration')]
class AdminUserFinderTest extends TestCase
{
    private const DAY = 86400;

    private AdminUserFinderInterface $finder;
    private ResourceConnection $resource;
    private string $table;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->finder = $objectManager->get(AdminUserFinderInterface::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->table = $objectManager->get(UserResource::class)->getMainTable();
    }

    /**
     * A plain `logdate <= cutoff` never returns a never-used account, because
     * NULL loses every comparison.
     */
    public function testAnAccountThatHasNeverSignedInIsFoundOnceItIsOldEnough(): void
    {
        $userId = $this->insertUser('commerce_never_used', null, time() - (200 * self::DAY));

        $found = $this->finder->findDormant(90 * self::DAY, 30 * self::DAY, time(), 100, 0);

        $this->assertContains($userId, $this->idsOf($found));
    }

    /**
     * The other half: an account created this morning has no sign-in date
     * either, and must not be swept up with the abandoned ones.
     */
    public function testAFreshAccountThatHasNeverSignedInIsNotFound(): void
    {
        $userId = $this->insertUser('commerce_fresh', null, time() - self::DAY);

        $found = $this->finder->findDormant(90 * self::DAY, 30 * self::DAY, time(), 100, 0);

        $this->assertNotContains($userId, $this->idsOf($found));
    }

    public function testAnAccountThatSignedInRecentlyIsNotFound(): void
    {
        $userId = $this->insertUser('commerce_recent', time() - self::DAY, time() - (400 * self::DAY));

        $found = $this->finder->findDormant(90 * self::DAY, 30 * self::DAY, time(), 100, 0);

        $this->assertNotContains($userId, $this->idsOf($found));
    }

    /**
     * Keyset paging.
     */
    public function testPagingVisitsEveryRowExactlyOnce(): void
    {
        $ids = [];

        for ($index = 0; $index < 5; $index++) {
            $ids[] = $this->insertUser('commerce_page_' . $index, time() - (400 * self::DAY), time());
        }

        $seen = [];
        $cursor = 0;

        while (true) {
            $page = $this->finder->findDormant(90 * self::DAY, 30 * self::DAY, time(), 2, $cursor);

            if ($page === []) {
                break;
            }

            foreach ($page as $candidate) {
                $seen[] = $candidate->getUserId();
                $cursor = max($cursor, $candidate->getUserId());
            }
        }

        $this->assertSame(array_values(array_unique($seen)), $seen, 'No row may be visited twice.');
        $this->assertEmpty(array_diff($ids, $seen), 'Every inserted row must be visited.');
    }

    public function testCountActiveOnlyCountsEnabledAccounts(): void
    {
        $before = $this->finder->countActive();

        $this->insertUser('commerce_active', time(), time(), 1);
        $this->insertUser('commerce_inactive', time(), time(), 0);

        $this->assertSame($before + 1, $this->finder->countActive());
    }

    /**
     * @param \Commerce\AdminUserLifecycle\Model\Candidate[] $candidates
     * @return int[]
     */
    private function idsOf(array $candidates): array
    {
        return array_map(
            static fn ($candidate): int => $candidate->getUserId(),
            $candidates
        );
    }

    private function insertUser(string $username, ?int $logdate, int $created, int $isActive = 1): int
    {
        $connection = $this->resource->getConnection();

        $connection->insert($this->table, [
            'firstname' => 'Test',
            'lastname' => 'Account',
            'email' => $username . '@example.test',
            'username' => $username,
            // Not a usable credential: these rows exist to be selected, never
            // to be authenticated against.
            'password' => str_repeat('x', 60),
            'created' => gmdate('Y-m-d H:i:s', $created),
            'modified' => gmdate('Y-m-d H:i:s', $created),
            'logdate' => $logdate === null ? null : gmdate('Y-m-d H:i:s', $logdate),
            'is_active' => $isActive,
        ]);

        return (int) $connection->lastInsertId($this->table);
    }
}
