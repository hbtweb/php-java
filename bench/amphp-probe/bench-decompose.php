<?php
declare(strict_types=1);

/**
 * Decompose where the PoC's 0.5 µs/call goes — and find the floor.
 *
 * Measures each layer in isolation to attribute cost. Identifies where
 * v2 optimisations would actually move the needle.
 */

require __DIR__ . '/vendor/autoload.php';

const N = 100_000;
const REPS = 5;

function bench(string $label, callable $fn): array
{
    $samples = [];
    for ($r = 0; $r < REPS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $samples[] = (\hrtime(true) - $t0) / N;
    }
    \sort($samples);
    return ['label' => $label, 'median' => $samples[(int) (REPS / 2)]];
}

// 1. Bare function call — the absolute floor.
$bareCall = bench('bare callable invocation         $fn()', function () {
    $fn = static fn() => 42;
    for ($i = 0; $i < N; $i++) $fn();
});

// 2. Closure allocation (no call).
$closureAlloc = bench('closure alloc only               static fn() => 42', function () {
    for ($i = 0; $i < N; $i++) static fn() => 42;
});

// 3. Closure alloc + call.
$closureCall = bench('closure alloc + call             (static fn() => 42)()', function () {
    for ($i = 0; $i < N; $i++) (static fn() => 42)();
});

// 4. Array push only.
$arrayPush = bench('array push                       $a[] = 1', function () {
    $a = [];
    for ($i = 0; $i < N; $i++) $a[] = 1;
});

// 5. Array push + sp index pop.
$arrayStack = bench('array stack push+pop (sp)        $a[$sp++], $sp--', function () {
    $a = []; $sp = 0;
    for ($i = 0; $i < N; $i++) {
        $a[$sp++] = 1;
        $sp--;
    }
});

// 6. array_shift — O(n) cost when queue grows.
$arrayShift = bench('array_shift small queue          $cb=array_shift($q)', function () {
    for ($i = 0; $i < N; $i++) {
        $q = [1];
        $cb = \array_shift($q);
    }
});

// 7. Static-property write + read.
class _Probe { public static array $futures = []; public static int $nextId = 0; public static array $queue = []; }
$staticProp = bench('static-prop write + read         self::$x = $i; $r = self::$x', function () {
    for ($i = 0; $i < N; $i++) {
        \_Probe::$nextId = $i;
        $x = \_Probe::$nextId;
    }
});

// 8. Typed-array slot write (PoC's [STATE, value, waiter]).
$arrayTriple = bench('array-triple write+read          $f=[$a,$b,$c]; $r=$f[1]', function () {
    for ($i = 0; $i < N; $i++) {
        $f = [0, 42, -1];
        $r = $f[1];
    }
});

// 9. Full PoC `async + await` (from poc-runtime.php).
require __DIR__ . '/poc-runtime.php';
$pocFull = bench('PoC v1 async + await             current PoC', function () {
    \Bench\Poc\Runtime::reset();
    for ($i = 0; $i < N; $i++) {
        $id = \Bench\Poc\Runtime::async(static fn() => 42);
        \Bench\Poc\Runtime::await($id);
    }
});

// ── Report ─────────────────────────────────────────────────────────────

echo "PHP " . \PHP_VERSION . " | iters=" . N . " | reps=" . REPS;
echo " | jit=" . (\ini_get('opcache.jit') ?: 'off') . "\n";
echo \str_repeat('─', 80) . "\n";
foreach ([
    $bareCall, $closureAlloc, $closureCall,
    $arrayPush, $arrayStack, $arrayShift,
    $staticProp, $arrayTriple,
    $pocFull,
] as $r) {
    \printf("%-50s  %7.0f ns/op\n", $r['label'], $r['median']);
}
echo \str_repeat('─', 80) . "\n";
\printf("PoC v1 - bareCall (overhead):  %.0f ns/op\n", $pocFull['median'] - $bareCall['median']);
