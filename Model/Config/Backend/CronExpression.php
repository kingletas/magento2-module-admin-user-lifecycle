<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rejects a schedule Magento's cron would silently never run.
 */
class CronExpression extends Value
{
    /**
     * Magento's matcher understands `*`, lists, ranges and steps, and a numeric
     * or three-letter name in each position.
     */
    private const FIELD_PATTERN = '/^(\*|\?|[0-9A-Za-z]+)([\/\-,][0-9A-Za-z*]+)*$/';

    private const EXPECTED_FIELDS = 5;

    /**
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        $expression = trim((string) $this->getValue());

        if ($expression === '') {
            throw new LocalizedException(
                __('Enter a cron expression, for example "0 2 * * *" for 02:00 every day.')
            );
        }

        $fields = preg_split('/\s+/', $expression) ?: [];

        if (count($fields) !== self::EXPECTED_FIELDS) {
            throw new LocalizedException(
                __(
                    'A cron expression needs exactly 5 space-separated fields '
                    . '(minute hour day month weekday); "%1" has %2.',
                    $expression,
                    count($fields)
                )
            );
        }

        foreach ($fields as $position => $field) {
            if (preg_match(self::FIELD_PATTERN, $field) !== 1) {
                throw new LocalizedException(
                    __('Field %1 of the cron expression ("%2") is not valid.', $position + 1, $field)
                );
            }
        }

        $this->setValue($expression);

        return parent::beforeSave();
    }
}
