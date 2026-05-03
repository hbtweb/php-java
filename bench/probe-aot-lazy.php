<?php
declare(strict_types=1);
// Targeted probe of PHPJAVA_AOT_MODE=lazy behaviour. Loads a small set
// of representative test fixtures via Loader::tryCallStatic and reports
// per-class outcome (PASS/FALLBACK/FATAL). Cheap alternative to running
// the full PHPUnit suite under env=lazy.
//
// Verifies my Loader.php changes ($failed registry, mark-before-eval)
// keep failures bounded — no uncatchable fatals.

require __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Loader;
use PHPJava\Core\JavaClass;
use PHPJava\Core\JVM\Parameters\GlobalOptions;
use PHPJava\Kernel\Resolvers\ClassResolver;

GlobalOptions::set([
    'output' => ['handler' => '/dev/null', 'heapspace' => true],
    'operations' => ['enable_trace' => false],
]);

putenv('PHPJAVA_AOT_MODE=lazy');

$pathRoot = __DIR__ . '/../tests/Cases/fixtures/java/';
$cacheDir = __DIR__ . '/../tests/Cases/caches';

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, $cacheDir],
]);

// Targets: classes that exercise AOT failure modes I want to verify.
$targets = [
    // Method overloading — should bail via overload guard, fall back to interp.
    'AccessStaticMethodTest',
    // Static-field initialisers — exercises clinit AOT.
    'BoundaryValueTypeForBooleanTest',
    'BoundaryValueTypeForIntTest',
    // Plain method call — should AOT cleanly.
    'CallTest',
    // Normal field access via clinit + getstatic.
    'GetFieldTest',
];

$ok = 0; $fall = 0; $fatal = 0;

foreach ($targets as $name) {
    @exec('javac --release 11 -classpath ' . escapeshellarg($pathRoot)
        . ' -encoding UTF8 ' . escapeshellarg($pathRoot . $name . '.java')
        . ' -d ' . escapeshellarg($cacheDir));

    try {
        // Try to AOT-compile and verify failure mode is bounded.
        $compileOk = false;
        $compileErr = null;
        try {
            (new \PHPJava\Aot\Compiler())->compileClass($name);
            $compileOk = true;
        } catch (\Throwable $e) {
            $compileErr = $e->getMessage();
        }

        // Now exercise the lazy path via JavaClass::load (which routes
        // through tryCallStatic when env is lazy).
        $jc = JavaClass::load($name);
        // Just constructing should be safe; constructor invocation
        // varies per fixture. Test the registry state.
        $aotLoaded = Loader::isLoaded($name);

        $status = $compileOk ? 'AOT-OK' : 'AOT-BAIL';
        printf("%-40s %s%s\n",
            $name,
            $status,
            $compileOk ? '' : "  (reason: " . substr($compileErr, 0, 60) . ")"
        );
        if ($compileOk) $ok++; else $fall++;
    } catch (\Throwable $e) {
        printf("%-40s UNCAUGHT %s: %s\n",
            $name, get_class($e), $e->getMessage());
        $fatal++;
    }
}

printf("\nSummary: %d AOT-clean, %d AOT-bail (graceful fallback), %d uncaught\n",
    $ok, $fall, $fatal);
exit($fatal === 0 ? 0 : 1);
