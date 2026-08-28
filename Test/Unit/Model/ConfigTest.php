<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\Config;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    use ShippedConfig;

    private const DAY = 86400;

    public function testTheSectionComesFromTheConstructorNotAConstant(): void
    {
        $scopeConfig = $this->scopeConfig(['acme_adminusers/general/enabled' => '1']);
        $config = new Config($scopeConfig, 'acme_adminusers');

        $this->assertTrue($config->isEnabled());
    }

    public function testAnAbsentDryRunSettingReadsAsADryRun(): void
    {
        $config = new Config($this->scopeConfig([]), self::SECTION);

        $this->assertTrue(
            $config->isDryRun(),
            'An install whose config row is missing must not become a live account-deletion job.'
        );
    }

    #[DataProvider('dryRunProvider')]
    public function testDryRunCoercion(mixed $stored, bool $expected): void
    {
        $config = $this->config(['general/dry_run' => $stored]);

        $this->assertSame($expected, $config->isDryRun());
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function dryRunProvider(): array
    {
        return [
            'explicit off' => ['0', false],
            'explicit on' => ['1', true],
            'empty string falls back to safe' => ['', true],
            'null falls back to safe' => [null, true],
        ];
    }

    public function testThresholdsAreExpressedInSeconds(): void
    {
        $config = $this->config([
            'warn/days_before' => '3',
            'deactivate/inactive_days' => '45',
            'delete/deactivated_days' => '200',
        ]);

        $this->assertSame(3 * self::DAY, $config->getWarningSeconds());
        $this->assertSame(45 * self::DAY, $config->getInactiveSeconds());
        $this->assertSame(200 * self::DAY, $config->getDeleteAfterSeconds());
    }

    /**
     * A zero here would make every account dormant since the epoch, so it falls
     * back to the shipped default rather than being honoured.
     */
    #[DataProvider('rejectedThresholdProvider')]
    public function testAZeroOrNegativeInactivityThresholdFallsBackToTheDefault(mixed $stored): void
    {
        $config = $this->config(['deactivate/inactive_days' => $stored]);

        $this->assertSame(Config::DEFAULT_INACTIVE_DAYS * self::DAY, $config->getInactiveSeconds());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function rejectedThresholdProvider(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-5'],
            'empty' => [''],
            'not a number' => ['soon'],
        ];
    }

    /**
     * Zero grace is a legitimate choice - "judge new accounts immediately" - so
     * unlike the other thresholds it is honoured rather than replaced.
     */
    public function testZeroCreationGraceIsHonoured(): void
    {
        $config = $this->config(['deactivate/new_account_grace_days' => '0']);

        $this->assertSame(0, $config->getNewAccountGraceSeconds());
    }

    public function testMinimumActiveAdministratorsCanNeverBeDrivenBelowOne(): void
    {
        foreach (['0', '-3', '', 'none'] as $stored) {
            $config = $this->config(['protect/min_active_admins' => $stored]);

            $this->assertGreaterThanOrEqual(
                Config::ABSOLUTE_MIN_ACTIVE_ADMINS,
                $config->getMinActiveAdmins(),
                sprintf('Stored value %s must not disable the last-administrator guard.', var_export($stored, true))
            );
        }
    }

    public function testProtectedUsernamesAcceptCommasAndNewlinesAndAreLowerCased(): void
    {
        $config = $this->config([
            'protect/usernames' => "Admin, break-glass\nINTEGRATION\n\n , ,deploy",
        ]);

        $this->assertSame(['admin', 'break-glass', 'integration', 'deploy'], $config->getProtectedUsernames());
    }

    public function testProtectedRoleIdsIgnoreAnythingThatIsNotAPositiveInteger(): void
    {
        $config = $this->config(['protect/role_ids' => '3,,abc,-1,0,7,3']);

        $this->assertSame([3, 7], $config->getProtectedRoleIds());
    }

    /**
     * One malformed address must not cost every other recipient the report:
     * `addTo()` with an invalid address aborts the whole transport build.
     */
    public function testReportRecipientsDropInvalidAddressesRatherThanFailing(): void
    {
        $config = $this->config([
            'report/recipients' => "ops@example.com,not-an-address\nsecurity@example.com,ops@example.com",
        ]);

        $this->assertSame(['ops@example.com', 'security@example.com'], $config->getReportRecipients());
    }

    /**
     * Pruning inside the deletion window would destroy the record that
     * authorises a deletion.
     */
    public function testJournalRetentionIsFlooredByTheDeletionWindow(): void
    {
        $config = $this->config([
            'delete/deactivated_days' => '365',
            'report/journal_retention_days' => '30',
        ]);

        $this->assertSame(
            (365 + 30) * self::DAY,
            $config->getJournalRetentionSeconds(),
            'Retention must exceed the deletion window it provides evidence for.'
        );
    }

    public function testBatchSizeIsBounded(): void
    {
        $this->assertSame(10000, $this->config(['general/batch_size' => '999999'])->getBatchSize());
        $this->assertSame(
            Config::DEFAULT_BATCH_SIZE,
            $this->config(['general/batch_size' => '0'])->getBatchSize()
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function scopeConfig(array $values): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => !in_array($values[$path] ?? null, [null, '', '0', 0, false], true)
        );

        return $scopeConfig;
    }
}
