<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Api\Converter;

use Commerce\AdminUserLifecycle\Model\Api\Converter\Instant;
use PHPUnit\Framework\TestCase;

class InstantTest extends TestCase
{
    public function testAnInstantIsWrittenAsUtcWithTheZoneSpelledOut(): void
    {
        $this->assertSame('2026-08-27T14:30:00Z', (new Instant())->format(1787841000));
    }

    public function testNullSurvivesFormatting(): void
    {
        $instant = new Instant();

        $this->assertNull($instant->formatOrNull(null));
        $this->assertSame('1970-01-01T00:00:00Z', $instant->formatOrNull(0));
    }

    /**
     * The reason `parse` exists rather than a bare `strtotime`.
     */
    public function testAValueWithNoZoneIsReadAsUtcRatherThanTheServersZone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $this->assertSame(
                gmmktime(0, 0, 0, 8, 27, 2026),
                (new Instant())->parse('2026-08-27')
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAnExplicitZoneIsHonouredRatherThanOverwritten(): void
    {
        $instant = new Instant();

        $this->assertSame(gmmktime(12, 0, 0, 8, 27, 2026), $instant->parse('2026-08-27T12:00:00Z'));
        $this->assertSame(gmmktime(11, 0, 0, 8, 27, 2026), $instant->parse('2026-08-27T12:00:00+01:00'));
        $this->assertSame(gmmktime(11, 0, 0, 8, 27, 2026), $instant->parse('2026-08-27T12:00:00+0100'));
    }

    public function testSomethingThatIsNotADateIsRefusedRatherThanGuessedAt(): void
    {
        $instant = new Instant();

        $this->assertNull($instant->parse('yesterday-ish'));
        $this->assertNull($instant->parse(''));
        $this->assertNull($instant->parse('   '));
    }

    /**
     * What the API hands out as `occurred_at` is something it accepts back as
     * `since`.
     */
    public function testWhatTheApiWritesIsWhatTheApiCanRead(): void
    {
        $instant = new Instant();

        $this->assertSame(1787841000, $instant->parse($instant->format(1787841000)));
    }
}
