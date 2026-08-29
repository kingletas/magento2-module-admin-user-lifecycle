<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model;

use Commerce\Foundation\Model\Config\ModuleConfig;

/**
 * Every setting this module has, read once and coerced.
 */
class Config extends ModuleConfig
{
    /**
     * Below one active administrator there is nobody who can undo any of this.
     */
    public const ABSOLUTE_MIN_ACTIVE_ADMINS = 1;

    public const DEFAULT_BATCH_SIZE = 200;
    public const DEFAULT_WARN_DAYS = 7;
    public const DEFAULT_INACTIVE_DAYS = 90;
    public const DEFAULT_NEW_ACCOUNT_GRACE_DAYS = 30;
    public const DEFAULT_DELETE_AFTER_DAYS = 180;
    public const DEFAULT_MIN_ACTIVE_ADMINS = 2;
    public const DEFAULT_JOURNAL_RETENTION_DAYS = 730;

    private const SECONDS_PER_DAY = 86400;

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/enabled', $storeId);
    }

    /**
     * Whether the scheduled pass runs.
     */
    public function isCronEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('general/cron_enabled', $storeId);
    }

    /**
     * Whether writes are simulated.
     */
    public function isDryRun(?int $storeId = null): bool
    {
        $value = $this->getValue('general/dry_run', $storeId);

        return $value === null || $value === '' || $this->isSetFlag('general/dry_run', $storeId);
    }

    public function getBatchSize(?int $storeId = null): int
    {
        return min(
            10000,
            $this->getPositiveInt('general/batch_size', self::DEFAULT_BATCH_SIZE, $storeId)
        );
    }

    public function isWarningEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('warn/enabled', $storeId);
    }

    public function getWarningSeconds(?int $storeId = null): int
    {
        return $this->getPositiveInt('warn/days_before', self::DEFAULT_WARN_DAYS, $storeId)
            * self::SECONDS_PER_DAY;
    }

    /**
     * How dormant an account has to be before it enters the notice window.
     */
    public function getWarningLeadSeconds(?int $storeId = null): int
    {
        return max(0, $this->getInactiveSeconds($storeId) - $this->getWarningSeconds($storeId));
    }

    public function isDeactivationEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('deactivate/enabled', $storeId);
    }

    public function getInactiveSeconds(?int $storeId = null): int
    {
        return $this->getPositiveInt('deactivate/inactive_days', self::DEFAULT_INACTIVE_DAYS, $storeId)
            * self::SECONDS_PER_DAY;
    }

    /**
     * How long a never-used account is left alone after being created.
     */
    public function getNewAccountGraceSeconds(?int $storeId = null): int
    {
        $days = $this->getInt('deactivate/new_account_grace_days', self::DEFAULT_NEW_ACCOUNT_GRACE_DAYS, $storeId);

        return max(0, $days) * self::SECONDS_PER_DAY;
    }

    public function isDeletionEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('delete/enabled', $storeId);
    }

    public function getDeleteAfterSeconds(?int $storeId = null): int
    {
        return $this->getPositiveInt('delete/deactivated_days', self::DEFAULT_DELETE_AFTER_DAYS, $storeId)
            * self::SECONDS_PER_DAY;
    }

    /**
     * Usernames that must never be touched, lower-cased for comparison.
     *
     * @return string[]
     */
    public function getProtectedUsernames(?int $storeId = null): array
    {
        $raw = $this->getString('protect/usernames', '', $storeId);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = mb_strtolower(trim($part));

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Role ids whose members must never be touched.
     *
     * @return int[]
     */
    public function getProtectedRoleIds(?int $storeId = null): array
    {
        $ids = [];

        foreach ($this->getList('protect/role_ids', $storeId) as $value) {
            if (ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = true;
            }
        }

        return array_keys($ids);
    }

    public function getMinActiveAdmins(?int $storeId = null): int
    {
        return max(
            self::ABSOLUTE_MIN_ACTIVE_ADMINS,
            $this->getPositiveInt('protect/min_active_admins', self::DEFAULT_MIN_ACTIVE_ADMINS, $storeId)
        );
    }

    public function isReportEnabled(?int $storeId = null): bool
    {
        return $this->isSetFlag('report/enabled', $storeId);
    }

    /**
     * @return string[]
     */
    public function getReportRecipients(?int $storeId = null): array
    {
        $raw = $this->getString('report/recipients', '', $storeId);

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];
        $valid = [];

        foreach ($parts as $part) {
            $address = trim($part);

            // Invalid addresses are dropped here, so one of them cannot abort
            // the whole transport build.
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[$address] = true;
            }
        }

        return array_keys($valid);
    }

    public function isReportOnlyWhenChanged(?int $storeId = null): bool
    {
        return $this->isSetFlag('report/only_when_changed', $storeId);
    }

    public function getReportTemplate(?int $storeId = null): string
    {
        return $this->getString(
            'report/email_template',
            'commerce_adminusers_report_email_template',
            $storeId
        );
    }

    public function getWarningTemplate(?int $storeId = null): string
    {
        return $this->getString(
            'warn/email_template',
            'commerce_adminusers_warning_email_template',
            $storeId
        );
    }

    public function getSenderIdentity(?int $storeId = null): string
    {
        return $this->getString('report/sender_identity', 'general', $storeId);
    }

    /**
     * How far back the journal is kept, in seconds.
     */
    public function getJournalRetentionSeconds(?int $storeId = null): int
    {
        $configured = $this->getPositiveInt(
            'report/journal_retention_days',
            self::DEFAULT_JOURNAL_RETENTION_DAYS,
            $storeId
        ) * self::SECONDS_PER_DAY;

        $floor = $this->getDeleteAfterSeconds($storeId) + (30 * self::SECONDS_PER_DAY);

        return max($configured, $floor);
    }
}
