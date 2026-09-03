<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\ResourceModel;

use Commerce\AdminUserLifecycle\Api\AdminUserFinderInterface;
use Commerce\AdminUserLifecycle\Model\Candidate;
use Magento\Authorization\Model\Acl\Role\User as UserRole;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\User\Model\ResourceModel\User as UserResource;

/**
 * Selects admin accounts, one keyset page at a time.
 */
class AdminUserFinder implements AdminUserFinderInterface
{
    /**
     * `authorization_role` holds one row per user whose `parent_id` is the role
     * group they belong to.
     */
    private const ROLE_ALIAS = 'role';
    private const USER_ALIAS = 'main';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UserResource $userResource,
        private readonly RoleResource $roleResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findDormant(
        int $dormantSeconds,
        int $graceSeconds,
        int $now,
        int $limit,
        int $afterUserId
    ): array {
        $connection = $this->resource->getConnection();
        $select = $this->baseSelect()
            ->where(self::USER_ALIAS . '.is_active = ?', 1)
            ->where(self::USER_ALIAS . '.user_id > ?', $afterUserId)
            ->order(self::USER_ALIAS . '.user_id ASC')
            ->limit(max(1, $limit));

        // Two disjoint populations, and conflating them is the bug this
        // condition exists to avoid.
        $activeCutoff = gmdate('Y-m-d H:i:s', $now - max(0, $dormantSeconds));
        $neverUsedCutoff = gmdate('Y-m-d H:i:s', $now - max(0, $dormantSeconds, $graceSeconds));

        $select->where(
            sprintf(
                '(%1$s.logdate IS NOT NULL AND %1$s.logdate <= %2$s) '
                . 'OR (%1$s.logdate IS NULL AND %1$s.created <= %3$s)',
                self::USER_ALIAS,
                $connection->quote($activeCutoff),
                $connection->quote($neverUsedCutoff)
            )
        );

        return $this->hydrateAll($connection->fetchAll($select));
    }

    /**
     * @inheritDoc
     */
    public function findInactive(int $limit, int $afterUserId): array
    {
        $connection = $this->resource->getConnection();
        $select = $this->baseSelect()
            ->where(self::USER_ALIAS . '.is_active = ?', 0)
            ->where(self::USER_ALIAS . '.user_id > ?', $afterUserId)
            ->order(self::USER_ALIAS . '.user_id ASC')
            ->limit(max(1, $limit));

        return $this->hydrateAll($connection->fetchAll($select));
    }

    /**
     * @inheritDoc
     */
    public function countActive(): int
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from([self::USER_ALIAS => $this->userResource->getMainTable()], ['count' => 'COUNT(*)'])
            ->where(self::USER_ALIAS . '.is_active = ?', 1);

        return (int) $connection->fetchOne($select);
    }

    /**
     * @inheritDoc
     */
    public function getById(int $userId): ?Candidate
    {
        $connection = $this->resource->getConnection();
        $select = $this->baseSelect()->where(self::USER_ALIAS . '.user_id = ?', $userId)->limit(1);
        $row = $connection->fetchRow($select);

        return is_array($row) && $row !== [] ? $this->hydrate($row) : null;
    }

    private function baseSelect(): Select
    {
        $connection = $this->resource->getConnection();

        return $connection->select()
            ->from(
                [self::USER_ALIAS => $this->userResource->getMainTable()],
                ['user_id', 'username', 'email', 'firstname', 'lastname', 'is_active', 'logdate', 'created']
            )
            ->joinLeft(
                [self::ROLE_ALIAS => $this->roleResource->getMainTable()],
                sprintf(
                    '%1$s.user_id = %2$s.user_id AND %1$s.user_type = %3$d AND %1$s.role_type = %4$s',
                    self::ROLE_ALIAS,
                    self::USER_ALIAS,
                    UserContextInterface::USER_TYPE_ADMIN,
                    $connection->quote(UserRole::ROLE_TYPE)
                ),
                ['role_id' => self::ROLE_ALIAS . '.parent_id']
            );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Candidate[]
     */
    private function hydrateAll(array $rows): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = $this->hydrate($row);
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Candidate
    {
        $name = trim(sprintf('%s %s', (string) ($row['firstname'] ?? ''), (string) ($row['lastname'] ?? '')));

        return new Candidate(
            (int) $row['user_id'],
            (string) ($row['username'] ?? ''),
            (string) ($row['email'] ?? ''),
            $name,
            (int) ($row['is_active'] ?? 0) === 1,
            $this->toTimestamp($row['logdate'] ?? null),
            $this->toTimestamp($row['created'] ?? null) ?? 0,
            isset($row['role_id']) ? (int) $row['role_id'] : null
        );
    }

    /**
     * Read as UTC, which is how Magento stores these columns.
     */
    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }
}
