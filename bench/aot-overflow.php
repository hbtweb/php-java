<?php
// JVM int-overflow semantics — validates that both interpreter and AOT
// paths preserve 32-bit signed wraparound per CONTRACTS.md §1.
//
// The fixture takes args so javac can't constant-fold the arithmetic
// (which it does aggressively for Integer.MAX_VALUE + literal expressions);
// each method exercises an actual iadd / isub / imul / ineg opcode.
//
// Reference values come from HotSpot.
require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Loader;
use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

$MAX = 2147483647;
$MIN = -2147483648;

// [method, args, expected]
$cases = [
    ['add',      [$MAX, 1],   $MIN],     // 2^31-1 + 1 → -2^31
    ['sub',      [$MIN, 1],   $MAX],     // -2^31 - 1 → 2^31-1
    ['mul',      [$MIN, -1],  $MIN],     // -2^31 * -1 → -2^31 (sign keeps)
    ['neg',      [$MIN],      $MIN],     // -(-2^31) → -2^31
    ['mulByTwo', [$MAX],      -2],       // 2^31-1 * 2 → -2
    // Sanity (in-range)
    ['add',      [100, 200],  300],
    ['sub',      [100, 50],   50],
    ['mul',      [12, 11],    132],
    ['neg',      [42],        -42],
];

echo "=== AOT path ===\n";
$aotOk = true;
foreach ($cases as [$method, $args, $expected]) {
    $got = Loader::callStatic('BenchIntOverflow', $method, ...$args);
    $argsStr = implode(',', $args);
    $ok = $got === $expected;
    printf("  %-9s(%-22s) = %12d  (expected %12d)  %s\n",
        $method, $argsStr, $got, $expected, $ok ? "PASS" : "FAIL");
    $aotOk = $aotOk && $ok;
}

echo "\n=== Interpreter path ===\n";
$interpOk = true;
$javaCls = JavaClass::load('BenchIntOverflow');
foreach ($cases as [$method, $args, $expected]) {
    $raw = $javaCls->getInvoker()->getStatic()->getMethods()->call($method, ...$args);
    $got = is_object($raw) ? $raw->getValue() : $raw;
    $argsStr = implode(',', $args);
    $ok = $got === $expected;
    printf("  %-9s(%-22s) = %12d  (expected %12d)  %s\n",
        $method, $argsStr, $got, $expected, $ok ? "PASS" : "FAIL");
    $interpOk = $interpOk && $ok;
}

echo "\n";
exit($aotOk && $interpOk ? 0 : 1);
