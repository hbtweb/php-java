<?php
// Validate the claim "Zend's JIT does ~80% of HotSpot tier-4 work on
// the emitted PHP" by isolating specific tier-4 optimisations and
// measuring with/without each. Run under three modes:
//   php          (no opcache, no JIT)
//   php -d opcache.enable_cli=1 -d opcache.jit=0
//   php -d opcache.enable_cli=1 -d opcache.jit=tracing
//
// Each test has two variants:
//   A — code that DEPENDS on the optimisation to run fast
//   B — manually optimised baseline (what A would compile to if the
//       optimisation were applied)
// If JIT actually applies the optimisation, A's perf converges to B's.
// If JIT doesn't apply it, A is markedly slower than B.

const N = 1_000_000;
const WARMUP = 5_000;

function timeit(string $label, callable $body): array {
    for ($i = 0; $i < WARMUP; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $body();
    $ns = (microtime(true) - $start) * 1e9;
    return ['label' => $label, 'ns' => $ns / N];
}

// ── Test 1: DCE — eliminate dead computations ─────────────────────
// A computes 5 unused values then returns a constant.
// B just returns the constant.
function dce_a(): int {
    $x = 13 * 17 + 41;
    $y = $x * 2 - 9;
    $z = $y % 7 + 3;
    $w = $z << 2 ^ 0xCAFE;
    $v = $w | 0xC0FFEE & 0xFFFF;
    return 42;
}
function dce_b(): int { return 42; }

// ── Test 2: CSE — common subexpression ────────────────────────────
// A computes ($a+$b)*($a+$b) twice; B uses one temp.
function cse_a(int $a, int $b): int {
    return ($a + $b) * ($a + $b) + ($a + $b);
}
function cse_b(int $a, int $b): int {
    $t = $a + $b;
    return $t * $t + $t;
}

// ── Test 3: LICM — loop-invariant code motion ──────────────────────
// A computes $a*$b inside the loop; B hoists.
function licm_a(int $a, int $b, int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $a * $b;
    return $s;
}
function licm_b(int $a, int $b, int $n): int {
    $s = 0; $k = $a * $b;
    for ($i = 0; $i < $n; $i++) $s += $k;
    return $s;
}

// ── Test 4: function inlining ─────────────────────────────────────
function helper_add(int $a, int $b): int { return $a + $b; }
function inline_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s = helper_add($s, $i);
    return $s;
}
function inline_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s = $s + $i;
    return $s;
}

// ── Test 5: static method inlining ────────────────────────────────
final class Helper {
    public static function add(int $a, int $b): int { return $a + $b; }
}
function smethod_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s = Helper::add($s, $i);
    return $s;
}
function smethod_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s = $s + $i;
    return $s;
}

// ── Test 6: type specialisation (int vs mixed) ────────────────────
function typed_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}
function untyped_b($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}

// ── Test 7: object property access (EA proxy) ─────────────────────
final class Box { public int $v = 0; }
function prop_a(int $n): int {
    $b = new Box();
    for ($i = 0; $i < $n; $i++) $b->v += $i;
    return $b->v;
}
function prop_b(int $n): int {
    $v = 0;
    for ($i = 0; $i < $n; $i++) $v += $i;
    return $v;
}

// ── run all ───────────────────────────────────────────────────────

$tests = [
    ['DCE',          fn() => dce_a(),              fn() => dce_b()],
    ['CSE',          fn() => cse_a(7, 13),         fn() => cse_b(7, 13)],
    ['LICM',         fn() => licm_a(7, 13, 100),   fn() => licm_b(7, 13, 100)],
    ['inline-fn',    fn() => inline_a(100),        fn() => inline_b(100)],
    ['inline-static',fn() => smethod_a(100),       fn() => smethod_b(100)],
    ['type-special', fn() => typed_a(100),         fn() => untyped_b(100)],
    ['prop-access',  fn() => prop_a(100),          fn() => prop_b(100)],
];

printf("PHP %s, opcache=%s, JIT=%s\n",
    PHP_VERSION,
    ini_get('opcache.enable_cli') ? '1' : '0',
    ini_get('opcache.jit') ?: 'off');
echo str_repeat('-', 80), "\n";
printf("%-16s %12s %12s %10s   verdict\n", 'test', 'A (ns)', 'B (ns)', 'A/B');
echo str_repeat('-', 80), "\n";

foreach ($tests as [$label, $a, $b]) {
    $ra = timeit($label . '-A', $a);
    $rb = timeit($label . '-B', $b);
    $ratio = $rb['ns'] > 0 ? $ra['ns'] / $rb['ns'] : INF;
    // Verdict: ratio < 1.20 means JIT collapses A→B (optimisation applied).
    // 1.20–2.0: partial. >2.0: not applied.
    $verdict = match (true) {
        $ratio < 1.20 => 'OPT-APPLIED',
        $ratio < 2.00 => 'partial',
        default       => 'NOT-APPLIED',
    };
    printf("%-16s %10.2f ns %10.2f ns   %5.2fx   %s\n",
        $label, $ra['ns'], $rb['ns'], $ratio, $verdict);
}
