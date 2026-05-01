<?php
// Hot-loop dispatch shootout. Six variants of "execute 5000 mixed
// JVM-style ops in a tight loop", picked to validate which patterns
// Zend (and Zend JIT) actually optimise well.
//
// Variants:
//   A. Hand-unrolled inline       — idealised AOT output (5.6 ns/op floor)
//   B. Switch over int array      — Phase 2 spike pattern
//   C. Closure-array table        — mesh sig.php pattern in a loop
//   D. eval'd closure with switch — pre-built dispatcher
//   E. eval'd per-method function — what AOT produces (= variant A but
//                                    via eval at construction time)
//   F. Threaded code              — each handler returns next handler

const OPS = 5000;       // ops per "method" call
const CALLS = 2000;     // method invocations to bench

// Simulated opcode stream (mix of iadd, iload_1, istore_2, sipush, goto-back)
function generate_ops(int $n): array {
    $ops = [];
    // Pattern: iload_1 ; sipush 1 ; iadd ; istore_1
    // (counter += 1) — gives N iterations of `i = i + 1`
    for ($i = 0; $i < $n / 4; $i++) {
        $ops[] = [0x1B, null];   // iload_1
        $ops[] = [0x11, 1];      // sipush 1
        $ops[] = [0x60, null];   // iadd
        $ops[] = [0x3C, null];   // istore_1
    }
    return $ops;
}

// =============================================================================
// Variant A — hand-unrolled inline (idealised AOT output)
// =============================================================================

function variantA(int $iters): int {
    $L = [0, 0];
    for ($i = 0; $i < $iters / 4; $i++) {
        $L[1] = $L[1] + 1;       // collapsed iload_1 + sipush + iadd + istore_1
    }
    return $L[1];
}

// =============================================================================
// Variant B — switch over int array (Phase 2 spike pattern)
// =============================================================================

function variantB(array $ops): int {
    $L = [0, 0];
    $stack = [];
    $sp = 0;
    $end = count($ops);
    for ($pc = 0; $pc < $end; $pc++) {
        [$op, $arg] = $ops[$pc];
        switch ($op) {
            case 0x1B:  // iload_1
                $stack[$sp++] = $L[1];
                break;
            case 0x11:  // sipush
                $stack[$sp++] = $arg;
                break;
            case 0x60:  // iadd
                $b = $stack[--$sp];
                $stack[$sp - 1] += $b;
                break;
            case 0x3C:  // istore_1
                $L[1] = $stack[--$sp];
                break;
        }
    }
    return $L[1];
}

// =============================================================================
// Variant C — closure-array table
// =============================================================================

function variantC_factory(): array {
    return [
        0x1B => static function (&$L, &$stack, &$sp, $arg) { $stack[$sp++] = $L[1]; },
        0x11 => static function (&$L, &$stack, &$sp, $arg) { $stack[$sp++] = $arg; },
        0x60 => static function (&$L, &$stack, &$sp, $arg) { $b = $stack[--$sp]; $stack[$sp - 1] += $b; },
        0x3C => static function (&$L, &$stack, &$sp, $arg) { $L[1] = $stack[--$sp]; },
    ];
}

function variantC(array $ops, array $T): int {
    $L = [0, 0];
    $stack = [];
    $sp = 0;
    foreach ($ops as [$op, $arg]) {
        $T[$op]($L, $stack, $sp, $arg);
    }
    return $L[1];
}

// =============================================================================
// Variant D — eval'd closure with switch baked in
// =============================================================================

function variantD_factory(): \Closure {
    // Build the switch as a PHP source string, eval to a closure ONCE.
    // The closure has ALL the cases inline; no per-iter table lookup.
    $body = '
        $L = [0, 0];
        $stack = [];
        $sp = 0;
        $end = count($ops);
        for ($pc = 0; $pc < $end; $pc++) {
            [$op, $arg] = $ops[$pc];
            switch ($op) {
                case 0x1B: $stack[$sp++] = $L[1]; break;
                case 0x11: $stack[$sp++] = $arg; break;
                case 0x60: $b = $stack[--$sp]; $stack[$sp - 1] += $b; break;
                case 0x3C: $L[1] = $stack[--$sp]; break;
            }
        }
        return $L[1];
    ';
    return eval("return function (array \$ops): int { {$body} };");
}

// =============================================================================
// Variant E — eval'd per-method function (real AOT)
// =============================================================================
// Generated PHP equivalent of variantA, but via eval at construction.
// This proves that "compile to a string, eval once, call N times" works.

function variantE_factory(int $iters): \Closure {
    // Generated body for the unrolled method (matches variantA output).
    $body = '
        $L = [0, 0];
        for ($i = 0; $i < ' . ($iters / 4) . '; $i++) {
            $L[1] = $L[1] + 1;
        }
        return $L[1];
    ';
    return eval("return function (): int { {$body} };");
}

// =============================================================================
// Variant F — threaded code (each handler returns next)
// =============================================================================
// Each handler is a closure. Handler returns the index of the next
// handler to run (or -1 to halt). No outer dispatch loop — handlers
// chain.

function variantF(array $ops): int {
    $L = [0, 0];
    $stack = [];
    $sp = 0;
    $pc = 0;
    $end = count($ops);
    // No real "threading" in PHP — closures can't tail-call each other
    // efficiently. Best we can do: trampoline via a while loop. So this
    // is structurally similar to B/C but with closures.
    $T = [
        0x1B => static fn(&$L, &$st, &$sp, $arg) => $st[$sp++] = $L[1],
        0x11 => static fn(&$L, &$st, &$sp, $arg) => $st[$sp++] = $arg,
        0x60 => static function (&$L, &$st, &$sp, $arg) {
            $b = $st[--$sp]; $st[$sp - 1] += $b;
        },
        0x3C => static function (&$L, &$st, &$sp, $arg) {
            $L[1] = $st[--$sp];
        },
    ];
    while ($pc < $end) {
        [$op, $arg] = $ops[$pc++];
        $T[$op]($L, $stack, $sp, $arg);
    }
    return $L[1];
}

// =============================================================================
// Bench
// =============================================================================

function bench(string $label, int $expected, callable $body): void {
    $r = $body();
    if ($r !== $expected) {
        fwrite(STDERR, "FAIL: {$label} returned {$r}, expected {$expected}\n");
        return;
    }
    $t0 = hrtime(true);
    for ($i = 0; $i < CALLS; $i++) $body();
    $totalNs = hrtime(true) - $t0;
    $perCall = $totalNs / CALLS;
    $perOp = $perCall / OPS;
    printf("  %-40s %10.0f ns/call  %8.2f ns/op\n", $label, $perCall, $perOp);
}

$ops = generate_ops(OPS);
$expected = OPS / 4;       // L[1] += 1, OPS/4 times
$T_C = variantC_factory();
$D = variantD_factory();
$E = variantE_factory(OPS);

echo "PHP " . PHP_VERSION . "\n";
echo (extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli'))
    ? "(opcache; jit=" . (ini_get('opcache.jit') ?: 'off') . ")\n\n"
    : "(no opcache)\n\n";

echo "Hot loop: " . OPS . " ops/call × " . CALLS . " calls = " . (OPS * CALLS) . " total ops\n";
echo str_repeat('-', 80) . "\n";
bench('A. Hand-unrolled inline (= idiomatic AOT)', $expected, fn() => variantA(OPS));
bench('B. Switch over int array',                 $expected, fn() => variantB($ops));
bench('C. Closure-array table',                   $expected, fn() => variantC($ops, $T_C));
bench('D. Eval-built switch closure',             $expected, fn() => $D($ops));
bench('E. Eval-built unrolled function',          $expected, fn() => $E());
bench('F. Threaded-code closures',                $expected, fn() => variantF($ops));
