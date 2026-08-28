<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `etc/di.xml` says what it means.
 */
class DiConsistencyTest extends TestCase
{
    /** @var array<string, string> virtualType name => type */
    private array $virtualTypes = [];

    public function testEveryArgumentNamesARealConstructorParameter(): void
    {
        $problems = [];

        foreach ($this->declarations() as $name => $node) {
            $class = $this->resolve($name);

            if (!str_starts_with($class, 'Commerce\\') || !class_exists($class)) {
                continue;
            }

            $parameters = [];
            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $parameters[$parameter->getName()] = true;
            }

            foreach ($this->argumentsOf($node) as $argument) {
                if (!isset($parameters[$argument])) {
                    $problems[] = sprintf('%s: "%s" is not a parameter of %s', $name, $argument, $class);
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * An object argument naming neither a class nor a virtualType fails only
     * when something asks.
     */
    public function testEveryObjectArgumentPointsAtSomethingThatExists(): void
    {
        $problems = [];

        foreach ($this->declarations() as $name => $node) {
            $xpath = new DOMXPath($node->ownerDocument);

            foreach ($xpath->query('./arguments/argument[@xsi:type="object"]', $node) as $argument) {
                $target = trim($argument->textContent);
                $bare = preg_replace('/\\\\Proxy$/', '', $target);
                $resolved = $this->resolve($bare);

                if (!str_starts_with($resolved, 'Commerce\\')) {
                    continue;
                }
                if (!class_exists($resolved) && !interface_exists($resolved)) {
                    $problems[] = sprintf(
                        '%s: "%s" points at unknown %s',
                        $name,
                        $argument->getAttribute('name'),
                        $target
                    );
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @return array<string, DOMElement>
     */
    private function declarations(): array
    {
        $document = new DOMDocument();
        $document->load(__DIR__ . '/../../etc/di.xml');
        $xpath = new DOMXPath($document);

        $declarations = [];

        foreach ($xpath->query('//virtualType') as $node) {
            $this->virtualTypes[$node->getAttribute('name')] = $node->getAttribute('type');
            $declarations[$node->getAttribute('name')] = $node;
        }
        foreach ($xpath->query('//type') as $node) {
            $declarations[$node->getAttribute('name')] = $node;
        }

        return $declarations;
    }

    private function resolve(string $name): string
    {
        $seen = [];

        while (isset($this->virtualTypes[$name]) && !isset($seen[$name])) {
            $seen[$name] = true;
            $name = $this->virtualTypes[$name];
        }

        return $name;
    }

    /**
     * @return string[]
     */
    private function argumentsOf(DOMElement $node): array
    {
        $xpath = new DOMXPath($node->ownerDocument);
        $names = [];

        foreach ($xpath->query('./arguments/argument', $node) as $argument) {
            $names[] = $argument->getAttribute('name');
        }

        return $names;
    }
}
