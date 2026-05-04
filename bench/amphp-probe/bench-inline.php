<?php
declare(strict_types=1);

/**
 * The "compiler-inlined" limit: what if static analysis determines
 * the async body doesn't suspend, and the compiler emits a direct
 * call instead of routing through async/await machinery?
 *
 * Java source:
 *   int x = ((Future<Integer>) executor.submit(() -> 42)).get();
 *
 * Without analysis: emit Runtime::async + Runtime::await pair (358 ns
 *                   under PoC v2; 2044 ns under AMPHP).
 *
 * With "may-suspend" analysis confirming the lambda doesn't reach a
 * suspend point: emit
 *   $x = (static fn() => 42)();
 * which is the bare-call floor (9 ns).
 *
 * The runtime didn't get faster. The compiler eliminated the runtime
 * altogether for the pattern that doesn't need it.
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/poc-runtime.php';
require __DIR__ . '/poc-runtime-v2.php';

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

// 1. Inlined — what the compiler emits when static analysis confirms no suspend.
$inlined = bench('compiler-inlined: $r = $fn()', function () {
    $fn = static fn() => 42;
    $r = 0;
    for ($i = 0; $i < N; $i++) {
        $r = $fn();
    }
});

// 2. PoC v2 (current best runtime).
$pocV2 = bench('PoC v2 async + await', function () {
    \Bench\Poc\V2\Runtime::reset();
    for ($i = 0; $i < N; $i++) {
        $id = \Bench\Poc\V2\Runtime::async(static fn() => 42);
        \Bench\Poc\V2\Runtime::await($id);
    }
});

// 3. PoC v1.
$pocV1 = bench('PoC v1 async + await', function () {
    \Bench\Poc\Runtime::reset();
    for ($i = 0; $i < N; $i++) {
        $id = \Bench\Poc\Runtime::async(static fn() => 42);
        \Bench\Poc\Runtime::await($id);
    }
});

// 4. AMPHP.
$amp = bench('Amp\\async + await', function () {
    for ($i = 0; $i < N; $i++) \Amp\async(static fn() => 42)->await();
});

// 5. Direct value — the "literally just do the work" lower bound.
$direct = bench('direct: $r = 42', function () {
    $r = 0;
    for ($i = 0; $i < N; $i++) {
        $r = 42;
    }
});

echo "PHP " . \PHP_VERSION . " | iters=" . N . " | reps=" . REPS;
echo " | jit=" . (\ini_get('opcache.jit') ?: 'off') . "\n";
echo \str_repeat('─', 80) . "\n";
foreach ([$direct, $inlined, $pocV2, $pocV1, $amp] as $r) {
    \printf("  %-46s  %7.0f ns/op\n", $r['label'], $r['median']);
}
echo \str_repeat('─', 80) . "\n";

echo "\nLayered speedups (latest → reference):\n";
\printf("  direct → inlined            %.0fx (closure call indirection)\n",
    $inlined['median'] / \max($direct['median'], 1));
\printf("  inlined → PoC v2            %.0fx (runtime machinery)\n",
    $pocV2['median'] / $inlined['median']);
\printf("  PoC v2 → PoC v1             %.2fx (wrapper-closure + array_shift + extra static call)\n",
    $pocV1['median'] / $pocV2['median']);
\printf("  PoC v1 → AMPHP              %.0fx (Future obj + Fiber + Suspension + EventLoop)\n",
    $amp['median'] / $pocV1['median']);
\printf("  inlined → AMPHP             %.0fx (full stack of avoidable work)\n",
    $amp['median'] / $inlined['median']);
