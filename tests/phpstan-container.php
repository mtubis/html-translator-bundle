<?php

declare(strict_types=1);

/*
 * Boots the default TestKernel scenario and copies its dumped container to a fixed path, so phpstan-symfony can
 * resolve services without depending on the per-scenario cache directory hash. Run before PHPStan, not as one of
 * its bootstrapFiles: phpstan-symfony reads the XML before those are loaded.
 */

use HtmlTranslator\Bundle\Tests\Functional\TestKernel;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new TestKernel();
$kernel->boot();

$dump = $kernel->getContainer()->getParameter('debug.container.dump');
$target = __DIR__ . '/../var/phpstan/container.xml';

if (!is_string($dump) || !is_file($dump)) {
    throw new RuntimeException('The test kernel did not dump its container; is it booted in debug mode?');
}

if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o777, true) && !is_dir(dirname($target))) {
    throw new RuntimeException(sprintf('Cannot create "%s".', dirname($target)));
}

if (!copy($dump, $target)) {
    throw new RuntimeException(sprintf('Cannot write "%s".', $target));
}
$kernel->shutdown();
