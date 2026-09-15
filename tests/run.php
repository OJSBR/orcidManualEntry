<?php

/**
 * @file plugins/generic/orcidManualEntry/tests/run.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Standalone runner for the test suite.
 *
 * The suite is written for PHPUnit and is collected by PKP's `ApplicationPlugins`
 * suite, which looks for plugins/../tests/*Test.php. But the OJS release tarball
 * ships no development dependencies, so on an ordinary server there is no
 * PHPUnit to run it with. This runner executes the same test classes with no
 * dependencies at all:
 *
 *     php plugins/generic/orcidManualEntry/tests/run.php
 *
 * Exit code 0 when everything passes, 1 otherwise.
 */

// Use the suite's own assertions even when the OJS bootstrap makes PHPUnit
// autoloadable: PHPUnit assertions need its runner to report a failure.
define('ORCIDMANUALENTRY_STANDALONE_TESTS', true);

require_once __DIR__ . '/bootstrap.php';

if (is_file(dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/bin/phpunit')) {
    fwrite(STDERR, "PHPUnit is available; run it directly for the full reporting:\n"
        . "  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage " . dirname(__DIR__) . "/tests\n\n");
}

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = $failed = 0;
$failures = [];

foreach ($files as $file) {
    require_once $file;
    $class = 'APP\\plugins\\generic\\orcidManualEntry\\tests\\' . basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }

    printf("\n%s\n", $reflection->getShortName());
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $instance = $reflection->newInstance();
        try {
            $method->invoke($instance);
            $passed++;
            printf("  ok   %s\n", $method->getName());
        } catch (Throwable $e) {
            $failed++;
            $failures[] = sprintf("%s::%s\n    %s", $reflection->getShortName(), $method->getName(), $e->getMessage());
            printf("  FAIL %s\n", $method->getName());
        }
    }
}

printf("\n%s\n", str_repeat('-', 60));
printf("%d passed, %d failed\n", $passed, $failed);

if ($failures) {
    printf("\nFailures:\n\n%s\n", implode("\n\n", $failures));
}

exit($failed === 0 ? 0 : 1);
