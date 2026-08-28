<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Api\Data;

use Commerce\AdminUserLifecycle\Api\Data\LifecycleActionResultInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleCandidateInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleJournalEntryInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleRunReportInterface;
use Commerce\AdminUserLifecycle\Api\Data\LifecycleStageReportInterface;
use Magento\Framework\Reflection\FieldNamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The names the JSON actually carries.
 */
final class FieldNamingTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function dataInterfaces(): array
    {
        return [
            'candidate' => [LifecycleCandidateInterface::class],
            'journal entry' => [LifecycleJournalEntryInterface::class],
            'action result' => [LifecycleActionResultInterface::class],
            'stage report' => [LifecycleStageReportInterface::class],
            'run report' => [LifecycleRunReportInterface::class],
        ];
    }

    /**
     * @param class-string $interface
     */
    #[DataProvider('dataInterfaces')]
    public function testEveryFieldTheApiEmitsIsNamedByAConstant(string $interface): void
    {
        $namer = new FieldNamer();
        $reflection = new ReflectionClass($interface);
        $declared = $reflection->getConstants();

        foreach ($reflection->getMethods() as $method) {
            $field = $namer->getFieldNameForMethodName($method->getName());

            if ($field === null) {
                continue;
            }

            self::assertContains(
                $field,
                $declared,
                sprintf(
                    '%s::%s() puts "%s" in the payload, and no constant on the interface says so.',
                    $reflection->getShortName(),
                    $method->getName(),
                    $field
                )
            );
        }
    }

    /**
     * The other direction, so a constant left behind by a renamed getter fails
     * rather than lingering as documentation of a field nobody sends.
     *
     * @param class-string $interface
     */
    #[DataProvider('dataInterfaces')]
    public function testEveryConstantNamesAFieldSomeMethodActuallyEmits(string $interface): void
    {
        $namer = new FieldNamer();
        $reflection = new ReflectionClass($interface);
        $emitted = [];

        foreach ($reflection->getMethods() as $method) {
            $field = $namer->getFieldNameForMethodName($method->getName());

            if ($field !== null) {
                $emitted[] = $field;
            }
        }

        foreach ($reflection->getConstants() as $name => $value) {
            self::assertContains(
                $value,
                $emitted,
                sprintf('%s::%s is "%s", which nothing emits.', $reflection->getShortName(), $name, $value)
            );
        }
    }

    /**
     * `TypeProcessor` reads the return type out of the docblock and throws when
     * there is not one.
     *
     * @param class-string $interface
     */
    #[DataProvider('dataInterfaces')]
    public function testEveryGetterCarriesTheReturnAnnotationTheRestLayerReads(string $interface): void
    {
        $namer = new FieldNamer();
        $reflection = new ReflectionClass($interface);

        foreach ($reflection->getMethods() as $method) {
            if ($namer->getFieldNameForMethodName($method->getName()) === null) {
                continue;
            }

            $docBlock = $method->getDocComment();

            self::assertIsString(
                $docBlock,
                sprintf('%s::%s() has no docblock at all.', $reflection->getShortName(), $method->getName())
            );
            self::assertMatchesRegularExpression(
                '/@return\s+\S+\s+\S/',
                $docBlock,
                sprintf(
                    '%s::%s() needs "@return <type> <description>": the type for Magento, '
                    . 'the description for the coding standard and the generated schema.',
                    $reflection->getShortName(),
                    $method->getName()
                )
            );
        }
    }
}
