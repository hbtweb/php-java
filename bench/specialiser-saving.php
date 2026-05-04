<?php
declare(strict_types=1);

/**
 * Measure the per-call-site saving of AsyncSpecialiserPass (ROADMAP §T3 step 5).
 *
 * The pass rewrites
 *     CompletableFuture::supplyAsync($s)->get()        (runtime path)
 * into
 *     ($s)()                                            (direct callable)
 * and the analogous .join() / runAsync variants.
 *
 * This bench compares both shapes at 100k iterations × 5 reps and
 * reports the median ns/op delta — i.e. the floor that step 5 elides
 * per matching call site.
 *
 * Run:  php bench/specialiser-saving.php
 * Falsifier for ROADMAP claim "saves ~365 ns/op": if the measured
 * delta lands outside [200, 600] ns/op on this machine the claim
 * downgrades to whatever was measured.
 */

require __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Runtime\Async\InlineExecutor;
use PHPJava\Aot\Runtime\java\util\concurrent\CompletableFuture;

const N    = 100_000;
const REPS = 7;

/** @return array{label:string, median:float, min:float, samples:array<int,float>} */
function bench(string $label, callable $fn): array
{
    $samples = [];
    for ($r = 0; $r < REPS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $samples[] = (\hrtime(true) - $t0) / N;
    }
    \sort($samples);
    return [
        'label'   => $label,
        'median'  => $samples[(int) (REPS / 2)],
        'min'     => $samples[0],
        'samples' => $samples,
    ];
}

/* ─── workload A: bare callable invocation — what the specialiser emits ──── */
$direct = bench('direct: ($s)() — specialiser output', function () {
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $v = $s();
    }
});

/* ─── workload B: CompletableFuture::supplyAsync($s)->get() — runtime path ─ */
$cfGet = bench('CF::supplyAsync($s)->get() — runtime path', function () {
    InlineExecutor::reset();
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $v = CompletableFuture::supplyAsync($s)->get();
    }
});

/* ─── workload C: same shape with .join() ────────────────────────────────── */
$cfJoin = bench('CF::supplyAsync($s)->join() — runtime path', function () {
    InlineExecutor::reset();
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $v = CompletableFuture::supplyAsync($s)->join();
    }
});

/* ─── workload D: runAsync($r)->get() — runtime path, void variant ───────── */
$cfRun = bench('CF::runAsync($r)->get() — runtime path', function () {
    InlineExecutor::reset();
    $r = static function () { /* void */ };
    for ($i = 0; $i < N; $i++) {
        CompletableFuture::runAsync($r)->get();
    }
});

/* ─── workload E: bare InlineExecutor::async + await — no CF wrapper ─────── */
$ieRaw = bench('InlineExecutor::async + await — no CF', function () {
    InlineExecutor::reset();
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $v = InlineExecutor::await(InlineExecutor::async($s));
    }
});

/* ─── workload F: no-escape unspecialised — `$L = CF::supplyAsync($s); $L->get();` ── */
$noEscRaw = bench('no-escape unspecialised: $L = CF::sa($s); $L->get()', function () {
    InlineExecutor::reset();
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $L = CompletableFuture::supplyAsync($s);
        $v = $L->get();
    }
});

/* ─── workload G: no-escape specialised — `$L = $s; ($L)();` (what the pass emits) ── */
$noEscSpec = bench('no-escape specialised:    $L = $s; ($L)()', function () {
    $s = static fn() => 42;
    for ($i = 0; $i < N; $i++) {
        $L = $s;
        $v = ($L)();
    }
});

$jit = \ini_get('opcache.jit') ?: 'off';
echo 'PHP ' . \PHP_VERSION . ' | iters=' . N . ' | reps=' . REPS . ' | jit=' . $jit . "\n";
echo \str_repeat('─', 78) . "\n";
foreach ([$direct, $ieRaw, $cfGet, $cfJoin, $cfRun, $noEscRaw, $noEscSpec] as $r) {
    \printf("  %-50s  median %7.0f ns/op  (min %7.0f)\n",
        $r['label'], $r['median'], $r['min']);
}
echo \str_repeat('─', 78) . "\n";

$savingGet     = $cfGet['median']     - $direct['median'];
$savingJoin    = $cfJoin['median']    - $direct['median'];
$savingRun     = $cfRun['median']     - $direct['median'];
$ieFloor       = $ieRaw['median']     - $direct['median'];
$savingNoEsc   = $noEscRaw['median']  - $noEscSpec['median'];
$slotIndirect  = $noEscSpec['median'] - $direct['median'];

\printf("inline collapse saving — supplyAsync()->get():  %6.0f ns/op\n", $savingGet);
\printf("inline collapse saving — supplyAsync()->join(): %6.0f ns/op\n", $savingJoin);
\printf("inline collapse saving — runAsync()->get():     %6.0f ns/op\n", $savingRun);
\printf("no-escape collapse saving (\$L = sa(\$s); \$L->get()): %6.0f ns/op\n", $savingNoEsc);
\printf("slot-indirection cost (specialised vs direct):  %6.0f ns/op\n", $slotIndirect);
\printf("InlineExecutor floor (CF wrapper excluded):     %6.0f ns/op\n", $ieFloor);
echo \str_repeat('─', 78) . "\n";
