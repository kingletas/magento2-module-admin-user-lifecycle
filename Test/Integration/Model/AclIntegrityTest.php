<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Integration\Model;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\Acl\Builder;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The ACL tree still builds with this module's resources merged in.
 */
#[Group('integration')]
class AclIntegrityTest extends TestCase
{
    public const CONFIG_RESOURCE = 'Commerce_AdminUserLifecycle::config';

    public function testTheMergedAclTreeContainsNoDuplicateResourceIds(): void
    {
        $provider = Bootstrap::getObjectManager()->get(ProviderInterface::class);

        $seen = [];
        $duplicates = [];

        $this->walk($provider->getAclResources(), $seen, $duplicates);

        self::assertSame([], $duplicates, 'Duplicate ACL resource ids: ' . implode(', ', $duplicates));
    }

    public function testTheAclActuallyBuilds(): void
    {
        $acl = Bootstrap::getObjectManager()->get(Builder::class)->getAcl();

        self::assertTrue($acl->has(self::CONFIG_RESOURCE));
    }

    /**
     * The section resource has to hang off Magento_Config::config, or the
     * section can only be granted to a full administrator.
     */
    public function testTheConfigSectionResourceIsReachableUnderTheCoreChain(): void
    {
        $provider = Bootstrap::getObjectManager()->get(ProviderInterface::class);

        self::assertTrue(
            $this->hasUnder($provider->getAclResources(), 'Magento_Config::config', self::CONFIG_RESOURCE),
            'The section resource must be a child of Magento_Config::config.'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @param array<string, bool> $seen
     * @param string[] $duplicates
     */
    private function walk(array $resources, array &$seen, array &$duplicates): void
    {
        foreach ($resources as $resource) {
            $id = (string) ($resource['id'] ?? '');

            if ($id !== '') {
                if (isset($seen[$id])) {
                    $duplicates[] = $id;
                }

                $seen[$id] = true;
            }

            $this->walk($resource['children'] ?? [], $seen, $duplicates);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     */
    private function hasUnder(array $resources, string $parentId, string $childId): bool
    {
        foreach ($resources as $resource) {
            $children = $resource['children'] ?? [];

            if ((string) ($resource['id'] ?? '') === $parentId) {
                foreach ($children as $child) {
                    if ((string) ($child['id'] ?? '') === $childId) {
                        return true;
                    }
                }
            }

            if ($this->hasUnder($children, $parentId, $childId)) {
                return true;
            }
        }

        return false;
    }
}
