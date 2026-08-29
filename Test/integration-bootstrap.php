<?php
/**
 * @package   Commerce_AdminUserLifecycle
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

$root = getenv('M2_ROOT') ?: dirname(__DIR__, 4);
$frameworkBootstrap = $root . '/dev/tests/integration/framework/bootstrap.php';

if (!is_file($frameworkBootstrap)) {
    fwrite(
        STDERR,
        "The integration suite needs Magento's integration test framework.\n"
        . "Set M2_ROOT to an installation that has one, e.g.\n"
        . "  M2_ROOT=/path/to/magento vendor/bin/phpunit -c phpunit.integration.xml.dist\n"
        . "Looked for: {$frameworkBootstrap}\n"
    );

    exit(1);
}

require $frameworkBootstrap;
