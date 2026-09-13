<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * Zero-dependency runner for the unit suite.
 *
 *     php tests/run.php
 *
 * The tests themselves are ordinary PHPUnit\Framework\TestCase subclasses, so they also drop
 * straight into `./console tests:run Missivus` on a real Matomo install. This runner exists so that
 * running them needs neither Composer, PHPUnit, nor a Matomo checkout — tests that need a whole
 * environment to run are tests nobody runs.
 *
 * When PHPUnit is present its TestCase wins; the shim below is only defined in its absence.
 */

require_once __DIR__ . '/Framework/TestCase.php';
require_once __DIR__ . '/Framework/Doubles.php';
require_once __DIR__ . '/../libs/autoload.php';

$files = glob(__DIR__ . '/Unit/*Test.php');
sort($files);

$declaredBefore = get_declared_classes();

foreach ($files as $file) {
    require_once $file;
}

$testClasses = array_values(array_diff(get_declared_classes(), $declaredBefore));

$passed = 0;
$failures = array();

foreach ($testClasses as $class) {
    $reflection = new ReflectionClass($class);

    if ($reflection->isAbstract() || !$reflection->isSubclassOf('PHPUnit\\Framework\\TestCase')) {
        continue;
    }

    echo $reflection->getShortName() . "\n";

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (strpos($method->getName(), 'test') !== 0) {
            continue;
        }

        $name = $method->getName();

        $test = null;

        try {
            /** @var PHPUnit\Framework\TestCase $test */
            $test = $reflection->newInstance();

            // setUp is protected under real PHPUnit. setAccessible is a no-op from PHP 8.1 and
            // deprecated from 8.5 — the 8.1 floor on this line means it is never needed.
            $setUp = $reflection->getMethod('setUp');
            $setUp->invoke($test);

            $test->{$name}();
            $test->assertExpectedExceptionWasThrown();

            $passed++;
            echo "  ok    " . $name . "\n";
        } catch (Throwable $e) {
            // An exception the test declared via expectException() is a pass, not a failure.
            if ($test !== null && $test->matchesExpectedException($e)) {
                $passed++;
                echo "  ok    " . $name . "\n";
                continue;
            }

            $failures[] = $reflection->getShortName() . '::' . $name . "\n        "
                . get_class($e) . ': ' . str_replace("\n", "\n        ", $e->getMessage());
            echo "  FAIL  " . $name . "\n";
        }
    }
}

echo "\n";

if (empty($failures)) {
    echo "OK — " . $passed . " tests passed.\n";
    exit(0);
}

echo count($failures) . " failed, " . $passed . " passed.\n\n";

foreach ($failures as $failure) {
    echo "  - " . $failure . "\n";
}

exit(1);
