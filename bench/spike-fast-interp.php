<?php
// Spike: three alternative implementations of BenchAdd::sum1k() to
// validate the per-op cost projections from bench/profile-c930e2c.md.
// All three produce the same return value (sum 0..999 = 499500).
//
// Bytecode reference (javap -c BenchAdd.class):
//    0: iconst_0     ->  push 0
//    1: istore_0     ->  $L[0] = pop  (s = 0)
//    2: iconst_0     ->  push 0
//    3: istore_1     ->  $L[1] = pop  (i = 0)
//    4: iload_1      ->  push $L[1]
//    5: sipush 1000  ->  push 1000
//    8: if_icmpge 21 ->  if i >= 1000 goto 21
//   11: iload_0      ->  push $L[0]
//   12: iload_1      ->  push $L[1]
//   13: iadd         ->  push pop+pop
//   14: istore_0     ->  $L[0] = pop  (s += i)
//   15: iinc 1, 1    ->  $L[1]++
//   18: goto 4       ->  loop
//   21: iload_0      ->  push $L[0]
//   22: ireturn      ->  return pop
//
// Total bytecode ops: 1000 iters × 9 ops/iter (inside loop)
//                   + 4 ops setup + 2 ops finalise
//                   = ~9006 ops per call.
//
// Iter count for bench: 50 calls (matches bench-cli.php iadd-1k bench).

require_once __DIR__ . '/../vendor/autoload.php';

const ITERS = 50;
const BYTECODE_OPS_PER_CALL = 9006;  // empirical from instruction count

// =============================================================================
// Version A — switch dispatch over pre-decoded int array
// =============================================================================
//
// Bytecode is pre-decoded once into a flat int array; multi-byte operands
// are inlined as adjacent ints; branch targets pre-computed to absolute
// instruction indices. The hot loop is a `while + switch ($op)`.

function sum1k_switch(): int {
    // Pre-decoded sum1k. Each entry is one int. Branches use absolute
    // instruction indices (0-based on the pre-decoded array, NOT the
    // original byte offsets).
    //
    //   idx | op           | byte offset | inlined operand?
    //   ----|--------------|-------------|------------------
    //    0  | iconst_0     | 0           | -
    //    1  | istore_0     | 1           | -
    //    2  | iconst_0     | 2           | -
    //    3  | istore_1     | 3           | -
    //    4  | iload_1      | 4           | -
    //    5  | sipush 1000  | 5           | followed by 1000
    //    7  | if_icmpge X  | 8           | followed by absolute idx 17
    //    9  | iload_0      | 11          | -
    //   10  | iload_1      | 12          | -
    //   11  | iadd         | 13          | -
    //   12  | istore_0     | 14          | -
    //   13  | iinc 1, 1    | 15          | followed by 1, then 1
    //   16  | goto 4       | 18          | followed by absolute idx 4
    //   17  | iload_0      | 21          | -
    //   18  | ireturn      | 22          | -
    static $ops = [
        0x03,                    // 0  iconst_0
        0x3B,                    // 1  istore_0
        0x03,                    // 2  iconst_0
        0x3C,                    // 3  istore_1
        0x1B,                    // 4  iload_1
        0x11, 1000,              // 5  sipush 1000
        0xA2, 18,                // 7  if_icmpge -> idx 18 (iload_0 after loop)
        0x1A,                    // 9  iload_0
        0x1B,                    // 10 iload_1
        0x60,                    // 11 iadd
        0x3B,                    // 12 istore_0
        0x84, 1, 1,              // 13 iinc 1, 1
        0xA7, 4,                 // 16 goto -> idx 4
        0x1A,                    // 17 iload_0
        0xAC,                    // 18 ireturn
    ];
    $stack = [];
    $sp = 0;
    $L = [0, 0];
    $pc = 0;
    while (true) {
        $op = $ops[$pc++];
        switch ($op) {
            case 0x03:  // iconst_0
                $stack[$sp++] = 0;
                break;
            case 0x1A:  // iload_0
                $stack[$sp++] = $L[0];
                break;
            case 0x1B:  // iload_1
                $stack[$sp++] = $L[1];
                break;
            case 0x3B:  // istore_0
                $L[0] = $stack[--$sp];
                break;
            case 0x3C:  // istore_1
                $L[1] = $stack[--$sp];
                break;
            case 0x60:  // iadd
                $b = $stack[--$sp];
                $stack[$sp - 1] += $b;
                break;
            case 0x11:  // sipush
                $stack[$sp++] = $ops[$pc++];
                break;
            case 0x84:  // iinc
                $idx = $ops[$pc++];
                $delta = $ops[$pc++];
                $L[$idx] += $delta;
                break;
            case 0xA2:  // if_icmpge
                $b = $stack[--$sp];
                $a = $stack[--$sp];
                $target = $ops[$pc++];
                if ($a >= $b) $pc = $target;
                break;
            case 0xA7:  // goto
                $pc = $ops[$pc];
                break;
            case 0xAC:  // ireturn
                return $stack[--$sp];
        }
    }
}

// =============================================================================
// Version B — array-of-closures dispatch (mesh sig.php style)
// =============================================================================
//
// Same pre-decoded ops array. Dispatch via a 256-entry array of closures
// indexed by opcode byte. Frame state passed by reference.
//
// Closures capture by reference for state mutation. Note: PHP closures
// with use(&$x) have a small per-call use-frame setup cost (~30-50ns)
// vs static method refs.

function sum1k_closure_table(): int {
    static $ops = [
        0x03, 0x3B, 0x03, 0x3C, 0x1B, 0x11, 1000, 0xA2, 18,
        0x1A, 0x1B, 0x60, 0x3B, 0x84, 1, 1, 0xA7, 4, 0x1A, 0xAC,
    ];
    static $T = null;
    if ($T === null) {
        $T = [];
        $T[0x03] = function (&$s, &$sp, &$L, &$pc, &$ops) { $s[$sp++] = 0; };
        $T[0x1A] = function (&$s, &$sp, &$L, &$pc, &$ops) { $s[$sp++] = $L[0]; };
        $T[0x1B] = function (&$s, &$sp, &$L, &$pc, &$ops) { $s[$sp++] = $L[1]; };
        $T[0x3B] = function (&$s, &$sp, &$L, &$pc, &$ops) { $L[0] = $s[--$sp]; };
        $T[0x3C] = function (&$s, &$sp, &$L, &$pc, &$ops) { $L[1] = $s[--$sp]; };
        $T[0x60] = function (&$s, &$sp, &$L, &$pc, &$ops) { $b = $s[--$sp]; $s[$sp - 1] += $b; };
        $T[0x11] = function (&$s, &$sp, &$L, &$pc, &$ops) { $s[$sp++] = $ops[$pc++]; };
        $T[0x84] = function (&$s, &$sp, &$L, &$pc, &$ops) { $i = $ops[$pc++]; $L[$i] += $ops[$pc++]; };
        $T[0xA2] = function (&$s, &$sp, &$L, &$pc, &$ops) { $b = $s[--$sp]; $a = $s[--$sp]; $tgt = $ops[$pc++]; if ($a >= $b) $pc = $tgt; };
        $T[0xA7] = function (&$s, &$sp, &$L, &$pc, &$ops) { $pc = $ops[$pc]; };
        // 0xAC handled inline (return out of loop)
    }
    $stack = [];
    $sp = 0;
    $L = [0, 0];
    $pc = 0;
    while (true) {
        $op = $ops[$pc++];
        if ($op === 0xAC) return $stack[--$sp];
        $T[$op]($stack, $sp, $L, $pc, $ops);
    }
}

// =============================================================================
// Version C2 — naive AOT (emit each opcode inline, operand stack still array)
// =============================================================================
//
// What a non-optimising AOT compiler would produce. Each JVM bytecode op
// becomes inline PHP statements, but the operand stack is preserved as a
// PHP array — same way the interpreter sees it. NO opcode dispatch loop;
// each method is its own PHP function. Tests the "remove dispatch but keep
// stack model" middle ground between interpreter and idiomatic AOT.

function sum1k_naive_aot(): int {
    $L = [0, 0];
    $stack = []; $sp = 0;
    // iconst_0; istore_0
    $stack[$sp++] = 0; $L[0] = $stack[--$sp];
    // iconst_0; istore_1
    $stack[$sp++] = 0; $L[1] = $stack[--$sp];
    L_4:
        // iload_1; sipush 1000; if_icmpge L_18
        $stack[$sp++] = $L[1];
        $stack[$sp++] = 1000;
        $b = $stack[--$sp]; $a = $stack[--$sp];
        if ($a >= $b) goto L_18;
        // iload_0; iload_1; iadd; istore_0
        $stack[$sp++] = $L[0];
        $stack[$sp++] = $L[1];
        $b = $stack[--$sp]; $stack[$sp - 1] += $b;
        $L[0] = $stack[--$sp];
        // iinc 1, 1
        $L[1] += 1;
        // goto L_4
        goto L_4;
    L_18:
        // iload_0; ireturn
        return $L[0];
}

// =============================================================================
// Version C — hand-translated AOT (no interpreter; PHP-native)
// =============================================================================
//
// What the AOT compiler would emit: each Java bytecode op replaced by
// inline PHP statements that perform the same observable behaviour.
// No dispatch loop, no operand stack array, no opcode table. Java
// locals become PHP locals; the operand stack is dissolved into the
// expression tree.

function sum1k_aot(): int {
    $s = 0;
    for ($i = 0; $i < 1000; $i++) {
        $s += $i;
    }
    return $s;
}

// =============================================================================
// Reference: native PHPJava — invoke through the class loader for parity
// =============================================================================

use PHPJava\Core\JavaClass;
use PHPJava\Kernel\Resolvers\ClassResolver;

function sum1k_phpjava_setup(): callable {
    ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures']]);
    $cls = JavaClass::load('BenchAdd');
    return function () use ($cls) {
        return $cls->getInvoker()->getStatic()->getMethods()->call('sum1k');
    };
}

// =============================================================================
// Bench
// =============================================================================

function bench_loop(string $label, int $iters, callable $body): array {
    // Warm up.
    $body();
    $start = microtime(true);
    $result = null;
    for ($i = 0; $i < $iters; $i++) {
        $result = $body();
    }
    $total_ns = (microtime(true) - $start) * 1e9;
    return [
        'label' => $label,
        'iters' => $iters,
        'total_ms' => round($total_ns / 1e6, 3),
        'ns_per_call' => round($total_ns / $iters, 0),
        'ns_per_op' => round($total_ns / $iters / BYTECODE_OPS_PER_CALL, 1),
        'result' => $result,
    ];
}

// Sanity: all four must produce 499500.
$x1 = sum1k_switch();
$x2 = sum1k_closure_table();
$x3 = sum1k_aot();
$x4 = sum1k_naive_aot();
if ($x1 !== 499500 || $x2 !== 499500 || $x3 !== 499500 || $x4 !== 499500) {
    fwrite(STDERR, "Sanity FAIL: switch=$x1 closure=$x2 aot=$x3 naive=$x4\n");
    exit(1);
}

$phpjava = sum1k_phpjava_setup();

// Real-AOT: compiled by Aot\Compiler from BenchAdd.class via the same path
// PHPJava already parses .class files. Validates the unified-compiler claim.
$realAotPath = __DIR__ . '/aot-out/BenchAdd.php';
if (file_exists($realAotPath)) {
    require_once $realAotPath;
    $realAot = fn() => \PHPJava\Aot\Generated\BenchAdd::sum1k();
} else {
    fwrite(STDERR, "Run 'php aot-compile.php' first to generate {$realAotPath}\n");
    $realAot = null;
}

$results = [
    'phpjava'         => bench_loop('A: phpjava (current)',         ITERS, $phpjava),
    'spike_switch'    => bench_loop('B: switch dispatch',            ITERS, 'sum1k_switch'),
    'spike_closure'   => bench_loop('C: array of closures',          ITERS, 'sum1k_closure_table'),
    'spike_naive_aot' => bench_loop('D: hand-translated naive AOT',  ITERS, 'sum1k_naive_aot'),
    'real_naive_aot'  => $realAot ? bench_loop('E: REAL naive AOT (compiler-emitted)', ITERS, $realAot) : ['ns_per_op' => 0, 'ns_per_call' => 0, 'total_ms' => 0, 'iters' => 0, 'label' => 'E: skipped'],
    'spike_aot'       => bench_loop('F: hand-translated idiomatic AOT', ITERS, 'sum1k_aot'),
];

echo str_pad('label',                   30)
   . str_pad('total ms',                10)
   . str_pad('ns/call',                 12)
   . str_pad('ns/op',                   10)
   . str_pad('vs phpjava',              14)
   . PHP_EOL;
echo str_repeat('-', 76) . PHP_EOL;

$base = $results['phpjava']['ns_per_op'];
foreach ($results as $r) {
    $ratio = $r['ns_per_op'] > 0 ? round($base / $r['ns_per_op'], 1) : 0;
    echo str_pad($r['label'],                                  30)
       . str_pad((string)$r['total_ms'],                        10)
       . str_pad((string)$r['ns_per_call'],                     12)
       . str_pad((string)$r['ns_per_op'],                       10)
       . str_pad($ratio . 'x faster',                            14)
       . PHP_EOL;
}

echo PHP_EOL . "HotSpot interpreted reference: 0.52 ns/op (from bench/baseline-d803364-hotspot.json)" . PHP_EOL;
echo "HotSpot JIT reference:        ~0.10 ns/op" . PHP_EOL;
