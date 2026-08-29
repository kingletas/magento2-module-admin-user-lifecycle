<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\AdminUserLifecycle\Api;

use Commerce\AdminUserLifecycle\Model\RunContext;
use Commerce\AdminUserLifecycle\Model\StageResult;

/**
 * One step of the retirement pipeline.
 */
interface LifecycleStageInterface
{
    public function getName(): string;

    /**
     * Run the stage.
     */
    public function execute(RunContext $context): StageResult;
}
