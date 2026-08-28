<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Fake;

use Commerce\AdminUserLifecycle\Model\Config;

/**
 * Builds a real `Config` over an array, with the module's shipped defaults.
 */
final class ConfigBuilder
{
    public const SECTION = 'commerce_adminusers';

    /**
     * The values `etc/config.xml` ships, so the tests and the defaults cannot
     * drift apart without a test noticing.
     *
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
    public static function build(array $overrides = []): Config
    {
        return new Config(self::scopeConfig($overrides), self::SECTION);
    }

    /**
     * @param array<string, string|int|null> $overrides
     */
    public static function scopeConfig(array $overrides = []): ArrayScopeConfig
    {
        $values = [];

        foreach (array_merge(self::DEFAULTS, $overrides) as $path => $value) {
            $values[self::SECTION . '/' . $path] = $value;
        }

        return new ArrayScopeConfig($values);
    }
}
