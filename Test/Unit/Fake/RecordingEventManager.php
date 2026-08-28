<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Fake;

use Magento\Framework\Event\ManagerInterface;
use RuntimeException;

/**
 * Records what the module announced to anything listening from outside it.
 */
final class RecordingEventManager implements ManagerInterface
{
    /** @var array<int, array{name: string, data: array}> */
    public array $dispatched = [];

    /** @var string[] Event names whose dispatch should throw. */
    public array $explodeOn = [];

    public function dispatch($eventName, array $data = [])
    {
        if (in_array($eventName, $this->explodeOn, true)) {
            throw new RuntimeException(sprintf('Observer for "%s" exploded.', $eventName));
        }

        $this->dispatched[] = ['name' => (string) $eventName, 'data' => $data];
    }

    /**
     * Every dispatch of one event, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payloadsFor(string $eventName): array
    {
        $payloads = [];

        foreach ($this->dispatched as $event) {
            if ($event['name'] === $eventName) {
                $payloads[] = $event['data'];
            }
        }

        return $payloads;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_column($this->dispatched, 'name');
    }
}
