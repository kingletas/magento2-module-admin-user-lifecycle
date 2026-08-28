<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Test\Unit\Model\Config\Backend;

use Commerce\AdminUserLifecycle\Model\Config\Backend\CronExpression;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Magento's schedule matcher never matches a malformed expression rather than
 * erroring.
 */
class CronExpressionTest extends TestCase
{
    #[DataProvider('validProvider')]
    public function testAValidExpressionIsAccepted(string $expression): void
    {
        $model = $this->model($expression);

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validProvider(): array
    {
        return [
            'daily at two' => ['0 2 * * *'],
            'every fifteen minutes' => ['*/15 * * * *'],
            'weekday range' => ['0 3 * * 1-5'],
            'list' => ['0 1,13 * * *'],
            'named month' => ['0 0 1 JAN *'],
            'extra whitespace' => ['  0   2 * * *  '],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testAMalformedExpressionIsRefusedAtSaveTime(string $expression): void
    {
        $this->expectException(LocalizedException::class);

        $this->model($expression)->beforeSave();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'too few fields' => ['0 2 * *'],
            'too many fields' => ['0 2 * * * *'],
            'prose' => ['every day at 2am'],
            'stray characters' => ['0 2 * * !'],
        ];
    }

    public function testTheStoredValueIsTrimmed(): void
    {
        $model = $this->model('  0 2 * * *  ');
        $model->beforeSave();

        $this->assertSame('0 2 * * *', $model->getValue());
    }

    private function model(string $value): CronExpression
    {
        $model = new CronExpression(
            $this->context(),
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(AbstractResource::class),
            $this->createMock(AbstractDb::class)
        );

        $model->setValue($value);

        return $model;
    }

    /**
     * `AbstractModel::beforeSave()` dispatches events, so the context has to
     * carry a real event manager rather than a bare mock returning null.
     */
    private function context(): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getLogger')->willReturn(new NullLogger());
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getCacheManager')
            ->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getAppState')->willReturn($this->createMock(\Magento\Framework\App\State::class));
        $context->method('getActionValidator')->willReturn($this->createMock(RemoveAction::class));

        return $context;
    }
}
