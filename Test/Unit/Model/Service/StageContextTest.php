<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Service;

use Commerce\AdminUserLifecycle\Model\JournalEntryMapper;
use Commerce\AdminUserLifecycle\Model\Service\StageContext;
use Commerce\AdminUserLifecycle\Test\Support\ShippedConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StageContextTest extends TestCase
{
    use ShippedConfig;

    public function testItHandsBackExactlyWhatItWasGiven(): void
    {
        $config = $this->config();
        $logger = new NullLogger();
        $mapper = new JournalEntryMapper();

        $context = new StageContext($config, $logger, $mapper);

        $this->assertSame($config, $context->config);
        $this->assertSame($logger, $context->logger);
        $this->assertSame($mapper, $context->entryMapper);
    }

    /**
     * A stage may read from the context but never reassign it.
     */
    public function testItsPropertiesAreReadOnly(): void
    {
        $context = new StageContext($this->config(), new NullLogger(), new JournalEntryMapper());

        $this->expectException(\Error::class);
        $context->logger = new NullLogger();
    }
}
