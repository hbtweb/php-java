<?php
// Smoke-test the AOT classloader-integration path: instead of
// constructing Compiler directly + eval'ing emitted PHP, go through
// the high-level Loader API (mirror of JavaClass::load).
//
// Validates Open Q (HANDOVER 2026-05-03 #5 in plan): "AOT classloader
// integration into JavaClass::load — production reachability."
require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Loader;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

// Path 1: classpath load + dispatch
$result = Loader::callStatic('BenchAdd', 'sum1k');
$ok1 = $result === 499500;
echo "Loader::callStatic('BenchAdd', 'sum1k') = {$result}    " . ($ok1 ? "PASS" : "FAIL") . "\n";

// Path 2: define-from-bytes + dispatch
Loader::reset();
$bytes = file_get_contents(__DIR__ . '/fixtures/BenchAdd.class');
Loader::defineClass('BenchAddFromBytes', $bytes);
$result = Loader::callStatic('BenchAddFromBytes', 'sum1k');
$ok2 = $result === 499500;
echo "Loader::defineClass + callStatic = {$result}    " . ($ok2 ? "PASS" : "FAIL") . "\n";

// Path 3: invokestatic-heavy fixture via Loader
Loader::reset();
$result = Loader::callStatic('BenchInvoke', 'callLoop');
$ok3 = $result === 100;
echo "Loader::callStatic('BenchInvoke', 'callLoop') = {$result}    " . ($ok3 ? "PASS" : "FAIL") . "\n";

exit($ok1 && $ok2 && $ok3 ? 0 : 1);
