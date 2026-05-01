<?php
// Boxing mitigation patterns. Multi-dimensional: 6 patterns × 5 workloads.
//
// Patterns explored:
//   P1. Pure object wrapper          (current PHPJava — baseline)
//   P2. Cached factory (-128..127)   (Java IntegerCache pattern)
//   P3. Array-tagged ['I', $v]       (no class allocation)
//   P4. Single-element [$v]          (cheapest tag — array-ness IS the tag)
//   P5. Pure unboxed (no wrapping)   (best case — what AOT escape analysis emits)
//   P6. Static-method indirection    (no real wrapping — proxy through static fns)
//
// Workloads (model real Java patterns):
//   W1. Tight Integer arithmetic   (100 ops; box+unbox each iter)
//   W2. HashMap<Integer,Str> put   (100 puts; autobox at boundary)
//   W3. Sum List<Integer>          (100 elements iterated; foreach + unwrap)
//   W4. valueOf+intValue round-trip (100 explicit autobox/unbox pairs)
//   W5. Polymorphic Number access  (100 ops; .intValue() called on Number ref)
//
// Output: 6×5 grid of ns/op (per workload total / count).

const REPS = 5000;

function bench(string $label, int $expected, callable $body): float {
    $r = $body();
    if (is_int($r) && is_int($expected) && $r !== $expected) {
        fwrite(STDERR, "  FAIL {$label}: got {$r}, expected {$expected}\n");
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < REPS; $i++) $body();
    return (hrtime(true) - $t0) / REPS;
}

// =============================================================================
// PATTERN PRIMITIVES
// =============================================================================

// P1 — object wrapper
final class IntBox { public function __construct(public int $value) {} }
$P1_box = static fn(int $v): IntBox => new IntBox($v);
$P1_unbox = static fn(IntBox $b): int => $b->value;

// P2 — cached factory (Java IntegerCache, -128..127)
class IntCache {
    /** @var IntBox[] */
    private static array $cache = [];
    public static function init(): void {
        if (!self::$cache) {
            for ($i = -128; $i <= 127; $i++) self::$cache[$i] = new IntBox($i);
        }
    }
    public static function valueOf(int $v): IntBox {
        return ($v >= -128 && $v <= 127) ? self::$cache[$v] : new IntBox($v);
    }
}
IntCache::init();
$P2_box = static fn(int $v): IntBox => IntCache::valueOf($v);
$P2_unbox = static fn(IntBox $b): int => $b->value;

// P3 — array-tagged
$P3_box = static fn(int $v): array => ['I', $v];
$P3_unbox = static fn(array $b): int => $b[1];

// P4 — single-element
$P4_box = static fn(int $v): array => [$v];
$P4_unbox = static fn(array $b): int => $b[0];

// P5 — pure unboxed (no transformation)
$P5_box = static fn(int $v): int => $v;
$P5_unbox = static fn(int $b): int => $b;

// P6 — static-method indirection (no real wrapping; useful as a "what does the
// dispatch alone cost?" baseline)
class IntProxy {
    public static function box(int $v): int { return $v; }
    public static function unbox(int $v): int { return $v; }
}
$P6_box = static fn(int $v): int => IntProxy::box($v);
$P6_unbox = static fn(int $v): int => IntProxy::unbox($v);

$patterns = [
    'P1 object wrapper'    => [$P1_box, $P1_unbox],
    'P2 cached factory'    => [$P2_box, $P2_unbox],
    'P3 array tagged'      => [$P3_box, $P3_unbox],
    'P4 single-element [v]'=> [$P4_box, $P4_unbox],
    'P5 pure unboxed'      => [$P5_box, $P5_unbox],
    'P6 static method ind' => [$P6_box, $P6_unbox],
];

// =============================================================================
// WORKLOADS
// =============================================================================

// W1 — tight Integer arithmetic. Sum 0..99 boxing each step.
function w1(callable $box, callable $unbox): int {
    $s = $box(0);
    for ($i = 0; $i < 100; $i++) {
        $s = $box($unbox($s) + $i);
    }
    return $unbox($s);  // expected: 4950
}

// W2 — HashMap<Integer,String> put — uses native PHP array.
function w2(callable $box, callable $unbox): int {
    $map = [];
    for ($i = 0; $i < 100; $i++) {
        $key = $box($i);
        // PHP arrays don't support objects as keys directly; serialize wrapper to int
        $map[$unbox($key)] = "v{$i}";
    }
    return count($map);  // expected: 100
}

// W3 — sum List<Integer> via foreach.
function w3(callable $box, callable $unbox): int {
    $list = [];
    for ($i = 0; $i < 100; $i++) $list[] = $box($i);
    $s = 0;
    foreach ($list as $v) $s += $unbox($v);
    return $s;  // expected: 4950
}

// W4 — round-trip valueOf+intValue 100 times (the autoboxing site cost).
function w4(callable $box, callable $unbox): int {
    $s = 0;
    for ($i = 0; $i < 100; $i++) $s += $unbox($box($i));
    return $s;  // expected: 4950
}

// W5 — polymorphic Number access (call .intValue() through a "Number" ref).
function w5(callable $box, callable $unbox): int {
    $s = 0;
    for ($i = 0; $i < 100; $i++) {
        $n = $box($i);  // store as "Number"
        $s += $unbox($n);  // call intValue()
    }
    return $s;  // expected: 4950
}

$workloads = [
    'W1 arith chain'    => [fn($b, $u) => w1($b, $u), 4950],
    'W2 map put'        => [fn($b, $u) => w2($b, $u), 100],
    'W3 list sum'       => [fn($b, $u) => w3($b, $u), 4950],
    'W4 valueOf×intVal' => [fn($b, $u) => w4($b, $u), 4950],
    'W5 polymorphic'    => [fn($b, $u) => w5($b, $u), 4950],
];

// =============================================================================
// RUN
// =============================================================================

echo "PHP " . PHP_VERSION . " ";
echo (extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli'))
    ? "(opcache; jit=" . (ini_get('opcache.jit') ?: 'off') . ")"
    : "(no opcache)";
echo "\n\n";

$header = sprintf("  %-22s", 'pattern');
foreach (array_keys($workloads) as $w) $header .= sprintf(" %12s", $w);
echo $header . "\n";
echo str_repeat('-', strlen($header)) . "\n";

foreach ($patterns as $pName => [$box, $unbox]) {
    $row = sprintf("  %-22s", $pName);
    foreach ($workloads as $wName => [$wfn, $expected]) {
        $ns = bench("{$pName} / {$wName}", $expected, fn() => $wfn($box, $unbox));
        $row .= sprintf(" %12.0f", $ns);
    }
    echo $row . "\n";
}

echo "\n(ns per workload — workloads do 100 ops each, divide by 100 for ns/op)\n";

// =============================================================================
// CROSS-DIMENSION: AOT-style escape analysis (W1 unboxed if escape detected)
// =============================================================================

echo "\nAOT ESCAPE-ANALYSIS PROJECTION (compile-time elimination)\n";
echo "----------------------------------------------------------\n";
echo "If the AOT compiler detects that the boxed value never escapes\n";
echo "the local scope, it can emit raw arithmetic. The W1 workload\n";
echo "above with P5 measures this case — the compiler eliminates the\n";
echo "wrapping entirely. Cross-pattern comparison for W1:\n\n";

$baseline_w1 = bench("baseline", 4950, fn() => w1($P1_box, $P1_unbox));
foreach ($patterns as $pName => [$box, $unbox]) {
    $ns = bench("ratio", 4950, fn() => w1($box, $unbox));
    $ratio = $baseline_w1 / $ns;
    printf("  %-25s %10.0f ns  (%.1fx faster than P1)\n", $pName, $ns, $ratio);
}

// =============================================================================
// MEMORY DIMENSION (peak allocations during workload)
// =============================================================================

echo "\nMEMORY PRESSURE (peak memory delta during 1000-op workload)\n";
echo "-----------------------------------------------------------\n";

foreach ($patterns as $pName => [$box, $unbox]) {
    gc_collect_cycles();
    $mem0 = memory_get_usage();
    $peakStart = memory_get_peak_usage();

    // 1000 box+unbox round-trips with various values (defeats cache for some)
    for ($i = 0; $i < 1000; $i++) {
        $b = $box($i * 1000);  // values outside -128..127 to defeat P2 cache
        $u = $unbox($b);
    }

    $memDelta = memory_get_peak_usage() - $peakStart;
    printf("  %-25s peak +%10s  retained +%s\n",
        $pName,
        number_format($memDelta) . ' B',
        number_format(memory_get_usage() - $mem0) . ' B'
    );
}
