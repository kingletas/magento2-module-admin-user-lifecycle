<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Api\Converter;

/**
 * The one date format the API speaks, in both directions.
 */
class Instant
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    public function format(int $timestamp): string
    {
        return gmdate(self::FORMAT, $timestamp);
    }

    public function formatOrNull(?int $timestamp): ?string
    {
        return $timestamp === null ? null : $this->format($timestamp);
    }

    /**
     * Read a caller-supplied instant.
     *
     * @return int|null Null when the value cannot be read as a date at all.
     */
    public function parse(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $parsed = strtotime($trimmed . (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $trimmed) ? '' : ' UTC'));

        return $parsed === false ? null : $parsed;
    }
}
