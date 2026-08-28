<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model;

use Commerce\AdminUserLifecycle\Model\JournalEntry;
use Commerce\AdminUserLifecycle\Model\RunContext;
use PHPUnit\Framework\TestCase;

final class RunContextTest extends TestCase
{
    public function testItCarriesTheClockRatherThanReadingIt(): void
    {
        $context = new RunContext(JournalEntry::ACTOR_CRON, false, 1_234_567_890, 4);

        self::assertSame(JournalEntry::ACTOR_CRON, $context->getActor());
        self::assertFalse($context->isDryRun());
        self::assertSame(1_234_567_890, $context->getNow());
        self::assertSame(4, $context->getStoreId());
    }

    /**
     * Every stage compares against the same instant.
     */
    public function testTheClockDoesNotMoveDuringAPass(): void
    {
        $context = new RunContext(JournalEntry::ACTOR_CLI, true, 1_000);

        self::assertSame($context->getNow(), $context->getNow());
        self::assertSame(1_000, $context->getNow());
    }
}
