<?php
// String indexing probe — pre-registered measurement for ROADMAP
// §Phase 1 W1 (String_ byte-vs-UTF-16) and the Utf16-primitive
// consolidation.
//
// Usage (match bench/bench-aot.php's JIT settings):
//   php -d opcache.enable_cli=1 -d opcache.jit=tracing \
//       -d opcache.jit_buffer_size=1024M bench/string-index-probe.php
//
// WHAT THIS TESTS
//
// String_::charAt walks the UTF-8 byte stream from byte 0 on every
// call, and String_::length rescans the whole string. So the canonical
// Java idiom
//
//     for (int i = 0; i < s.length(); i++) { ... s.charAt(i) ... }
//
// is quadratic in two independent ways. This probe measures how bad
// that is and which of four candidate fixes actually removes it.
//
// PRE-REGISTERED PREDICTIONS (written before the first run):
//
//   P1  V0 scales quadratically: time(4096)/time(256) >= 100
//       (a 16x length increase costs >=100x if quadratic, ~16x if linear)
//   P2  V1 (per-call ASCII check) stays quadratic. The check is itself
//       an O(n) scan, so it changes the constant, not the order.
//       Expected < 2x better than V0 at n=4096.
//   P3  V2 (hoisted ASCII check) is linear and >= 5x faster than V0
//       at n=4096.
//   P4  V3 (decode-once to array) is linear and beats V0 by >= 5x at
//       n=4096 on NON-ASCII input, where V2 cannot help.
//
// FALSIFIER: if V2 gives under 2x at n=4096, the byte-walk is not the
// dominant cost. Stop, profile, and find what is before building the
// Utf16 primitive around an ASCII fast path.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Runtime\java\lang\String_;

const SIZES = [16, 256, 4096];
const RUNS  = 5;

/** Median of RUNS timings, in nanoseconds per charAt call. */
function measure(callable $fn, int $calls): float
{
    $fn(); // warm
    $times = [];
    for ($r = 0; $r < RUNS; $r++) {
        $t0 = \hrtime(true);
        $fn();
        $times[] = (\hrtime(true) - $t0) / $calls;
    }
    \sort($times);
    return $times[\intdiv(RUNS, 2)];
}

function isAscii(string $s): bool
{
    return !\preg_match('/[\x80-\xFF]/', $s);
}

/**
 * V4's memo. Whether this is viable turns on a PHP implementation
 * detail: zend_string caches its hash, so repeated lookups of the SAME
 * string zval should be O(1) rather than O(n) re-hashing. If that
 * holds, memoisation can live in the shim; if not, the decode has to
 * be hoisted by the compiler instead.
 */
$GLOBALS['__unit_memo'] = [];

function memoUnits(string $s): array
{
    if (isset($GLOBALS['__unit_memo'][$s])) {
        return $GLOBALS['__unit_memo'][$s];
    }
    return $GLOBALS['__unit_memo'][$s] = decodeUnits($s);
}

/** Decode a UTF-8 string to an array of UTF-16 code units, one pass. */
function decodeUnits(string $s): array
{
    $out = [];
    $n = \strlen($s);
    for ($i = 0; $i < $n;) {
        $b = \ord($s[$i]);
        if ($b < 0x80) {
            $out[] = $b;
            $i += 1;
        } elseif ($b < 0xE0) {
            $out[] = (($b & 0x1F) << 6) | (\ord($s[$i + 1]) & 0x3F);
            $i += 2;
        } elseif ($b < 0xF0) {
            $out[] = (($b & 0x0F) << 12) | ((\ord($s[$i + 1]) & 0x3F) << 6)
                   | (\ord($s[$i + 2]) & 0x3F);
            $i += 3;
        } else {
            $cp = (($b & 0x07) << 18) | ((\ord($s[$i + 1]) & 0x3F) << 12)
                | ((\ord($s[$i + 2]) & 0x3F) << 6) | (\ord($s[$i + 3]) & 0x3F);
            $cp -= 0x10000;
            $out[] = 0xD800 | ($cp >> 10);
            $out[] = 0xDC00 | ($cp & 0x3FF);
            $i += 1 + 3;
        }
    }
    return $out;
}

echo "String indexing probe — PHP " . \PHP_VERSION
   . ", JIT " . (\function_exists('opcache_get_status')
        && (\opcache_get_status(false)['jit']['enabled'] ?? false) ? 'on' : 'off')
   . "\n";
echo \str_repeat('-', 78) . "\n";

foreach ([['ASCII', 'a'], ['non-ASCII (2-byte)', "\u{00e9}"]] as [$label, $unit]) {
    echo "\n## charAt loop — {$label}\n\n";
    \printf("%-8s %13s %13s %13s %13s %13s\n", 'n', 'V0 current', 'V1 percall', 'V2 hoisted', 'V3 decoded', 'V4 memoised');

    $prev = [];
    foreach (SIZES as $n) {
        $s     = \str_repeat($unit, $n);
        $units = String_::length($s);

        // V0 — what ships today.
        $v0 = measure(static function () use ($s, $units) {
            $acc = 0;
            for ($i = 0; $i < $units; $i++) {
                $acc += String_::charAt($s, $i);
            }
            return $acc;
        }, $units);

        // V1 — ASCII check inside every call.
        $v1 = measure(static function () use ($s, $units) {
            $acc = 0;
            for ($i = 0; $i < $units; $i++) {
                $acc += isAscii($s) ? \ord($s[$i]) : String_::charAt($s, $i);
            }
            return $acc;
        }, $units);

        // V2 — ASCII check hoisted out of the loop. Models what a
        // compile-time-known-ASCII literal would give.
        $v2 = measure(static function () use ($s, $units) {
            $acc = 0;
            $ascii = isAscii($s);
            if ($ascii) {
                for ($i = 0; $i < $units; $i++) {
                    $acc += \ord($s[$i]);
                }
            } else {
                for ($i = 0; $i < $units; $i++) {
                    $acc += String_::charAt($s, $i);
                }
            }
            return $acc;
        }, $units);

        // V3 — single decode to a code-unit array, then index it.
        // Models hoisting the decode out of an indexing loop.
        $v3 = measure(static function () use ($s, $units) {
            $acc = 0;
            $u = decodeUnits($s);
            for ($i = 0; $i < $units; $i++) {
                $acc += $u[$i];
            }
            return $acc;
        }, $units);

        // V4 — memoised decode: models a shim-level cache where the
        // decode happens once per distinct string, not once per loop.
        $v4 = measure(static function () use ($s, $units) {
            $acc = 0;
            for ($i = 0; $i < $units; $i++) {
                $u = memoUnits($s);
                $acc += $u[$i];
            }
            return $acc;
        }, $units);

        \printf("%-8d %10.1f ns %10.1f ns %10.1f ns %10.1f ns %10.1f ns\n", $n, $v0, $v1, $v2, $v3, $v4);
        $prev[$n] = ['v0' => $v0, 'v1' => $v1, 'v2' => $v2, 'v3' => $v3, 'v4' => $v4];
    }

    $a = $prev[256];
    $b = $prev[4096];
    echo "\n  scaling 256 -> 4096 (16x longer; ~1x per-call if linear, ~16x if quadratic):\n";
    foreach (['v0', 'v1', 'v2', 'v3', 'v4'] as $k) {
        \printf("    %-4s %6.1fx per-call\n", $k, $b[$k] / $a[$k]);
    }
    echo "\n  speedup vs V0 at n=4096:\n";
    foreach (['v1', 'v2', 'v3', 'v4'] as $k) {
        \printf("    %-4s %6.2fx\n", $k, $b['v0'] / $b[$k]);
    }
}

// length() in the loop condition — the second quadratic.
echo "\n## length() per iteration vs hoisted — ASCII\n\n";
\printf("%-8s %16s %16s %10s\n", 'n', 'L0 per-iter', 'L1 hoisted', 'speedup');
foreach (SIZES as $n) {
    $s = \str_repeat('a', $n);

    $l0 = measure(static function () use ($s) {
        $acc = 0;
        for ($i = 0; $i < String_::length($s); $i++) {
            $acc++;
        }
        return $acc;
    }, $n);

    $l1 = measure(static function () use ($s) {
        $acc = 0;
        $len = String_::length($s);
        for ($i = 0; $i < $len; $i++) {
            $acc++;
        }
        return $acc;
    }, $n);

    \printf("%-8d %13.1f ns %13.1f ns %9.2fx\n", $n, $l0, $l1, $l0 / $l1);
}
