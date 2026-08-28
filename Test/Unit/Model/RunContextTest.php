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

class RunContextTest extends TestCase
{
    public function testItCarriesTheClockRatherThanReadingIt(): void
    {
        $context = new RunContext(JournalEntry::ACTOR_CRON, false, 1_234_567_890, 4);

        $this->assertSame(JournalEntry::ACTOR_CRON, $context->getActor());
        $this->assertFalse($context->isDryRun());
        $this->assertSame(1_234_567_890, $context->getNow());
        $this->assertSame(4, $context->getStoreId());
    }

    /**
     * Every stage compares against the same instant.
     */
    public function testTheClockDoesNotMoveDuringAPass(): void
    {
        $context = new RunContext(JournalEntry::ACTOR_CLI, true, 1_000);

        $this->assertSame($context->getNow(), $context->getNow());
        $this->assertSame(1_000, $context->getNow());
    }
}
