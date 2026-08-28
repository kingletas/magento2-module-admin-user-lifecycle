<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Support;

use Commerce\AdminUserLifecycle\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * A real `Config` over the values `etc/config.xml` ships, so a test that does
 * not care about a setting still reads what a fresh install would.
 */
trait ShippedConfig
{
    public const SECTION = 'commerce_adminusers';

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'general/enabled' => '1',
        'general/cron_enabled' => '1',
        'general/dry_run' => '0',
        'general/batch_size' => '200',
        'warn/enabled' => '1',
        'warn/days_before' => '7',
        'deactivate/enabled' => '1',
        'deactivate/inactive_days' => '90',
        'deactivate/new_account_grace_days' => '30',
        'delete/enabled' => '1',
        'delete/deactivated_days' => '180',
        'protect/usernames' => '',
        'protect/role_ids' => '',
        'protect/min_active_admins' => '2',
        'report/enabled' => '1',
        'report/recipients' => '',
        'report/only_when_changed' => '1',
        'report/journal_retention_days' => '730',
    ];

    /**
     * @param array<string, string|int|null> $overrides Section-relative paths.
     */
    private function config(array $overrides = []): Config
    {
        $values = [];

        foreach (array_merge(self::DEFAULTS, $overrides) as $path => $value) {
            $values[self::SECTION . '/' . $path] = $value;
        }

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return new Config($scopeConfig, self::SECTION);
    }
}
