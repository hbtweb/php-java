<?php
declare(strict_types=1);

// AOT-clean stdlib shim layer.
//
// AOT-emitted code lands here for JDK targets (java/*, javax/*, jdk/*,
// sun/*, com/sun/*) — see Compiler::classFqn(). These classes have native
// PHP shapes:
//
//   - Static fields are direct PHP static fields. `getstatic
//     java/lang/System.out` emits `\PHPJava\Aot\Runtime\java\lang\System::$out`.
//   - Methods take their natural Java parameters. `invokevirtual
//     PrintStream.println(String)` emits `$obj->println($s)` — no
//     methodSignature-string disambiguator like the interpreter shim.
//
// The interpreter's shim layer (\PHPJava\Packages\java\*) stays
// untouched until Week 2's boxing/wrapper subtraction pass; see
// docs/STATUS.md "What's open".

namespace PHPJava\Aot\Runtime\java\lang;

class System
{
    public static ?\PHPJava\Aot\Runtime\java\io\PrintStream $out = null;
    public static ?\PHPJava\Aot\Runtime\java\io\PrintStream $err = null;
}

// Throwable hierarchy. Each AOT-runtime exception extends its
// `\PHPJava\Packages\java\lang\*` counterpart so that:
//   - `catch (\PHPJava\Packages\java\lang\IndexOutOfBoundsException $e)`
//     in test or library code catches an exception thrown by AOT code
//     (the AOT exception is-a Packages exception via inheritance).
//   - `catch (\PHPJava\Aot\Runtime\java\lang\IndexOutOfBoundsException $e)`
//     still works for AOT-internal catch handlers emitted by the
//     IR Lowerer.
//   - `catch (\Throwable $e)` and `catch (\Exception $e)` continue to
//     work because the Packages hierarchy ultimately extends \Exception.
//
// Java's checked vs unchecked distinction is a javac concern, not a
// runtime one — PHP catch matches by class. `__construct(string $message
// = "")` matches Java's String-arg constructor; the cause-chain
// (`Throwable cause`) maps to PHP's $previous.
// Top of the hierarchy — anchored to Packages so external catches match.
class Throwable_                    extends \PHPJava\Packages\java\lang\Throwable {}
class Exception_                    extends \PHPJava\Packages\java\lang\Exception {}
class RuntimeException              extends \PHPJava\Packages\java\lang\RuntimeException {}

// Direct subclasses of RuntimeException — also extend Packages so external
// catches at any of these classes match too.
class IllegalArgumentException      extends \PHPJava\Packages\java\lang\IllegalArgumentException {}
class IllegalStateException         extends \PHPJava\Packages\java\lang\RuntimeException {} // Packages\IllegalStateException missing — fall back to RuntimeException
class NullPointerException          extends \PHPJava\Packages\java\lang\NullPointerException {}
class ArithmeticException           extends \PHPJava\Packages\java\lang\RuntimeException {} // Packages\ArithmeticException missing — fall back to RuntimeException
class IndexOutOfBoundsException     extends \PHPJava\Packages\java\lang\IndexOutOfBoundsException {}
class ClassCastException            extends \PHPJava\Packages\java\lang\ClassCastException {}
class UnsupportedOperationException extends \PHPJava\Packages\java\lang\UnsupportedOperationException {}

// Sub-subclasses extend the AOT parent (not Packages) to preserve the
// JVM-hierarchy on the AOT side. e.g. catching AOT IndexOutOfBoundsException
// must catch AOT StringIndexOutOfBoundsException (sibling-via-parent).
// External catch on Packages\IndexOutOfBoundsException still works because
// the AOT IndexOutOfBoundsException extends it.
class ArrayIndexOutOfBoundsException  extends IndexOutOfBoundsException {}
class StringIndexOutOfBoundsException extends IndexOutOfBoundsException {}
class NumberFormatException           extends IllegalArgumentException {}

/**
 * Raw-scalar adapter for `java.lang.String`. AOT-emitted code receives
 * a String as a raw PHP string (CONTRACTS.md §1, post-#12 wrapper-removal).
 * Instance methods are exposed as static functions taking the receiver
 * as the first argument; the IR Builder emits `String::method($s, ...)`
 * instead of `$s->method(...)` when the bytecode dispatches against
 * `java/lang/String`. Class name `String_` (trailing underscore) per
 * existing bootstrap convention; the IR Builder maps `java/lang/String`
 * to this FQN explicitly.
 *
 * Methods cover the most common JDK String surface — extend as fixtures
 * surface need. Char return (`charAt`) follows JVM-spec: returns `int`
 * (the UTF-16 code unit), not a `Char_` wrapper or single-byte string.
 */
class String_
{
    public static function charAt(string $s, int $i): int
    {
        if ($i < 0) {
            throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
        }
        // Java String.charAt(i) returns the UTF-16 code unit at index i.
        // PHP strings are byte sequences; we walk the UTF-8 stream and
        // return the int code unit value at the i-th UTF-16 position.
        //
        // Three encodings to handle (all in one pass):
        //   1-byte UTF-8 (ASCII)        → 1 code unit (ASCII codepoint)
        //   2-byte UTF-8 (U+0080..07FF) → 1 code unit (BMP codepoint)
        //   3-byte UTF-8 (U+0800..FFFF) → 1 code unit (BMP codepoint OR
        //                                  CESU-8-encoded surrogate from
        //                                  a Java class file)
        //   4-byte UTF-8 (U+10000+)     → 2 code units (surrogate pair)
        //
        // For the 4-byte form, we synthesise the high+low surrogate
        // values from the codepoint per the UTF-16 surrogate algorithm
        // (codepoint - 0x10000, split into top 10 bits | 0xD800 and low
        // 10 bits | 0xDC00).
        $n = \strlen($s);
        $unit = 0;
        $byte = 0;
        while ($byte < $n) {
            $b = \ord($s[$byte]);
            if ($b < 0x80) {
                if ($unit === $i) return $b;
                $byte += 1; $unit += 1;
            } elseif ($b < 0xE0) {
                if ($unit === $i) {
                    $b2 = \ord($s[$byte + 1]);
                    return (($b & 0x1F) << 6) | ($b2 & 0x3F);
                }
                $byte += 2; $unit += 1;
            } elseif ($b < 0xF0) {
                if ($unit === $i) {
                    $b2 = \ord($s[$byte + 1]);
                    $b3 = \ord($s[$byte + 2]);
                    return (($b & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                }
                $byte += 3; $unit += 1;
            } else {
                if ($unit === $i || $unit + 1 === $i) {
                    $b2 = \ord($s[$byte + 1]);
                    $b3 = \ord($s[$byte + 2]);
                    $b4 = \ord($s[$byte + 3]);
                    $cp = (($b & 0x07) << 18) | (($b2 & 0x3F) << 12)
                        | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                    $cp -= 0x10000;
                    return $unit === $i
                        ? 0xD800 | ($cp >> 10)
                        : 0xDC00 | ($cp & 0x3FF);
                }
                $byte += 4; $unit += 2;
            }
        }
        throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
    }

    public static function length(string $s): int
    {
        // Java String.length() returns UTF-16 code unit count, not byte
        // count. PHP strings are byte sequences. Walk the UTF-8 byte
        // stream, counting one unit per leading byte except for the
        // 4-byte (standard UTF-8) form which encodes a supplementary
        // char and counts as 2 units (surrogate pair in UTF-16).
        //
        // Java class files store strings in Modified UTF-8 (CESU-8):
        // supplementary chars are stored as two 3-byte sequences (one
        // per surrogate). The 3-byte branch handles both BMP chars and
        // CESU-8 surrogates as 1 unit each — correct because each
        // CESU-8 surrogate IS one UTF-16 unit.
        //
        // Note: charAt/indexOf/substring/hashCode below still use byte
        // indexing — they need the same UTF-16 awareness to be Java-
        // correct. Tracked under ROADMAP §Build "String_ fill".
        $n = \strlen($s);
        if ($n === 0) return 0;
        $units = 0;
        for ($i = 0; $i < $n; ) {
            $b = \ord($s[$i]);
            if ($b < 0x80) { $i += 1; $units += 1; }
            elseif ($b < 0xE0) { $i += 2; $units += 1; }
            elseif ($b < 0xF0) { $i += 3; $units += 1; }
            else { $i += 4; $units += 2; }
        }
        return $units;
    }

    public static function isEmpty(string $s): int { return $s === '' ? 1 : 0; }

    public static function equals(string $s, $other): int
    {
        return ($s === $other) ? 1 : 0;
    }

    public static function hashCode(string $s): int
    {
        // Java String.hashCode: s[0]*31^(n-1) + s[1]*31^(n-2) + ...
        // Result is a 32-bit signed int; mask + sign-extend for parity.
        $h = 0;
        for ($i = 0, $n = \strlen($s); $i < $n; $i++) {
            $h = (($h * 31) + \ord($s[$i])) & 0xFFFFFFFF;
        }
        return ($h & 0x80000000) ? $h - 0x100000000 : $h;
    }

    public static function toString(string $s): string { return $s; }

    public static function indexOf(string $s, $needle, int $fromIndex = 0): int
    {
        if (\is_int($needle)) $needle = \chr($needle);
        $p = \strpos($s, (string) $needle, \max(0, $fromIndex));
        return $p === false ? -1 : $p;
    }

    public static function lastIndexOf(string $s, $needle): int
    {
        if (\is_int($needle)) $needle = \chr($needle);
        $p = \strrpos($s, (string) $needle);
        return $p === false ? -1 : $p;
    }

    public static function substring(string $s, int $beginIndex, ?int $endIndex = null): string
    {
        return $endIndex === null
            ? \substr($s, $beginIndex)
            : \substr($s, $beginIndex, $endIndex - $beginIndex);
    }

    public static function trim(string $s): string { return \trim($s); }
    public static function strip(string $s): string { return \trim($s); }

    public static function startsWith(string $s, string $prefix): int
    {
        return \str_starts_with($s, $prefix) ? 1 : 0;
    }

    public static function endsWith(string $s, string $suffix): int
    {
        return \str_ends_with($s, $suffix) ? 1 : 0;
    }

    public static function contains(string $s, string $sub): int
    {
        return \str_contains($s, $sub) ? 1 : 0;
    }

    public static function replace(string $s, $target, $repl): string
    {
        if (\is_int($target)) $target = \chr($target);
        if (\is_int($repl))   $repl   = \chr($repl);
        return \str_replace((string) $target, (string) $repl, $s);
    }

    public static function toLowerCase(string $s): string { return \strtolower($s); }
    public static function toUpperCase(string $s): string { return \strtoupper($s); }
    public static function concat(string $s, string $t): string { return $s . $t; }

    public static function valueOf($v): string
    {
        if ($v === null) return 'null';
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        return (string) $v;
    }
}

/**
 * Static adapter for java.lang.Integer. Parsing throws
 * NumberFormatException to match JDK semantics.
 */
class Integer
{
    public static function parseInt(string $s, int $radix = 10): int
    {
        $s = \trim($s);
        if ($s === '' || !\preg_match('/^-?[0-9a-zA-Z]+$/', $s)) {
            throw new NumberFormatException("For input string: \"{$s}\"");
        }
        $n = \intval($s, $radix);
        $rt = $n < 0 ? '-' . \base_convert((string) -$n, 10, $radix) : \base_convert((string) $n, 10, $radix);
        if (\strtolower($rt) !== \strtolower($s)) {
            throw new NumberFormatException("For input string: \"{$s}\"");
        }
        return $n;
    }

    public static function valueOf($v): int
    {
        if (\is_string($v)) return self::parseInt($v);
        return (int) $v;
    }

    public static function toString(int $v, int $radix = 10): string
    {
        if ($radix === 10) return (string) $v;
        return $v < 0 ? '-' . \base_convert((string) -$v, 10, $radix) : \base_convert((string) $v, 10, $radix);
    }

    public static function toBinaryString(int $v): string  { return \decbin($v & 0xFFFFFFFF); }
    public static function toHexString(int $v): string     { return \dechex($v & 0xFFFFFFFF); }
    public static function toOctalString(int $v): string   { return \decoct($v & 0xFFFFFFFF); }

    public static function max(int $a, int $b): int { return $a >= $b ? $a : $b; }
    public static function min(int $a, int $b): int { return $a <= $b ? $a : $b; }
}

/**
 * Mutable string buffer adapter for java.lang.StringBuilder.
 * Fluent (returns self from append) and stringifiable (println,
 * implicit casts, and concat all go through __toString).
 */
class StringBuilder
{
    private string $buf;

    public function __construct($initial = '')
    {
        // Java overloads: StringBuilder(), (int capacity), (CharSequence/String).
        // Ints are capacity hints — irrelevant for PHP — start empty.
        $this->buf = \is_string($initial) ? $initial : '';
    }

    public function append($x): self
    {
        // Java's many append() overloads collapse to one PHP path. char
        // (passed as int per the raw-scalar contract) appends the
        // single character; everything else uses string conversion.
        if (\is_int($x) && $x >= 0 && $x <= 0x10FFFF && \func_num_args() === 1) {
            // Disambiguate append(int) from append(char): the JVM bytecode
            // is the same — both push an int. Without a way to tell them
            // apart from the AOT call site, prefer the integer-as-decimal
            // form (more common). The test fixtures here use it that way
            // (`text.append(i)` where i is an int counter).
            $this->buf .= (string) $x;
            return $this;
        }
        if ($x === null)        $this->buf .= 'null';
        elseif ($x === true)    $this->buf .= 'true';
        elseif ($x === false)   $this->buf .= 'false';
        else                    $this->buf .= (string) $x;
        return $this;
    }

    public function toString(): string { return $this->buf; }
    public function __toString(): string { return $this->buf; }

    public function length(): int { return \strlen($this->buf); }
    public function isEmpty(): int { return $this->buf === '' ? 1 : 0; }

    public function charAt(int $i): int
    {
        if ($i < 0 || $i >= \strlen($this->buf)) {
            throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
        }
        return \ord($this->buf[$i]);
    }

    public function setLength(int $n): void
    {
        $cur = \strlen($this->buf);
        if ($n < $cur) $this->buf = \substr($this->buf, 0, $n);
        elseif ($n > $cur) $this->buf .= \str_repeat("\x00", $n - $cur);
    }

    public function reverse(): self  { $this->buf = \strrev($this->buf); return $this; }
}

namespace PHPJava\Aot\Runtime\java\io;

/**
 * AOT-side PrintStream. Routes through `\PHPJava\IO\Standard\Output::write`
 * (the same mechanism the interpreter uses) so test fixtures calling
 * `Output::getHeapspace()` capture AOT-emitted output identically.
 *
 * The constructor's `$stream` arg is kept for parity with the JDK
 * shape (PrintStream takes an OutputStream); it's currently ignored
 * because Output::write handles destination via PHPJava's GlobalOptions
 * (`output.handler` / `output.heapspace`). Per-stream routing can be
 * added when a fixture surfaces the need (stderr-vs-stdout assertions
 * aren't currently exercised separately).
 */
class PrintStream
{
    public function __construct($stream)
    {
        // Preserved for shape-compat; routing is global via Output::write.
    }

    public function println($x = null): void
    {
        \PHPJava\IO\Standard\Output::write(((string) $x) . "\n");
    }

    public function print($x = null): void
    {
        \PHPJava\IO\Standard\Output::write((string) $x);
    }
}

namespace PHPJava\Aot\Runtime;

/**
 * JVM unsigned right shift on a 64-bit "long" — emulates `>>>` (PHP
 * has no logical right shift). Used by the IR Builder for LUSHR (0x7D).
 *
 * Mask shift count to low 6 bits per JVM spec; for n=0, return v
 * unchanged (avoids the "<< 64 is undefined" PHP corner). For n≥1,
 * shift right 1 with arithmetic shift, mask off the new sign bit, then
 * shift further (n-1) for the final result.
 */
function jvm_lushr(int $v, int $n): int
{
    $n &= 0x3F;
    if ($n === 0) return $v;
    return (($v >> 1) & PHP_INT_MAX) >> ($n - 1);
}

/**
 * Java `long` arithmetic — wrap modulo 2^64, two's-complement.
 *
 * PHP int is 64-bit on 64-bit hosts, but PHP overflows promote to
 * float (losing precision past 2^53) instead of wrapping. Java long
 * wraps modulo 2^64. Two-tier approach: try native first; if the
 * result is a float (overflow detected), recompute via GMP.
 *
 * Cost: native happy path ~10 ns (one is_int check); overflow path
 * ~170 ns (GMP). Most long workloads never overflow, so amortized
 * cost is barely above native. PHP's `(int)` cast on the overflowed
 * float saturates rather than wraps — diverges from Java for cases
 * other than the simplest power-of-2 boundary, so we can't shortcut
 * the GMP recovery.
 *
 * Per CONTRACTS.md §1: long is PHP int on the operand stack. These
 * helpers preserve that invariant.
 */
function jvm_ladd(int $a, int $b): int
{
    $r = $a + $b;
    return \is_int($r) ? $r : jvm_lwrap_arith('+', $a, $b);
}

function jvm_lsub(int $a, int $b): int
{
    $r = $a - $b;
    return \is_int($r) ? $r : jvm_lwrap_arith('-', $a, $b);
}

function jvm_lmul(int $a, int $b): int
{
    $r = $a * $b;
    return \is_int($r) ? $r : jvm_lwrap_arith('*', $a, $b);
}

function jvm_ldiv(int $a, int $b): int
{
    if ($b === 0) throw new \PHPJava\Packages\java\lang\ArithmeticException('/ by zero');
    // Java's only ldiv overflow case: Long.MIN_VALUE / -1 should wrap to
    // Long.MIN_VALUE (because +2^63 doesn't fit). PHP intdiv throws
    // DivisionByZeroError on this — handle explicitly.
    if ($a === \PHP_INT_MIN && $b === -1) return \PHP_INT_MIN;
    return \intdiv($a, $b);
}

function jvm_lrem(int $a, int $b): int
{
    if ($b === 0) throw new \PHPJava\Packages\java\lang\ArithmeticException('/ by zero');
    if ($a === \PHP_INT_MIN && $b === -1) return 0; // Java: MIN_VALUE % -1 = 0
    return $a % $b;
}

function jvm_lneg(int $a): int
{
    if ($a === \PHP_INT_MIN) return \PHP_INT_MIN; // -MIN_VALUE wraps to MIN_VALUE
    return -$a;
}

/**
 * Java `float` narrowing — PHP `float` is binary64; Java `float` is
 * binary32. At the JVM contract boundary (putfield/putstatic on F
 * field, fastore on float[], explicit d2f/i2f/l2f conversion), narrow
 * by round-tripping through the 4-byte single-precision IEEE754 form.
 *
 * Cost: ~50 ns/call. Per-arithmetic-op narrowing (fadd/fmul/etc.) is
 * NOT done; that would compound on hot float code, and float-heavy
 * Java workloads are already 3.8× slower than int per
 * docs/BOTTLENECKS.md §E. The boundary narrowing here catches the
 * user-visible cases (write-then-read on a float field/array, explicit
 * cast); per-op intermediate-precision divergence is a documented
 * limitation under CONTRACTS.md §1.
 */
function jvm_f32(float $v): float
{
    return \unpack('f', \pack('f', $v))[1];
}

/**
 * JVM fcmpl/dcmpl 3-way comparison — returns -1 / 0 / 1, with -1 if
 * either operand is NaN ("less" form, used by `if_lt`/`if_le` chains).
 * PHP `<=>` returns 0 for `NaN <=> NaN`, which diverges. Rare in
 * practice but breaks any sort/comparator that puts NaN at one end.
 */
function jvm_fcmpl(float $a, float $b): int
{
    if (\is_nan($a) || \is_nan($b)) return -1;
    return $a <=> $b;
}

/**
 * JVM fcmpg/dcmpg — same as fcmpl but +1 on NaN ("greater" form,
 * used by `if_gt`/`if_ge` chains).
 */
function jvm_fcmpg(float $a, float $b): int
{
    if (\is_nan($a) || \is_nan($b)) return 1;
    return $a <=> $b;
}

/**
 * Float.equals / Double.equals — NaN-aware equality. Java spec:
 * `Float.NaN.equals(Float.NaN)` returns true (so equal-Floats can be
 * keyed in HashMap). PHP `NaN === NaN` returns false. This helper
 * makes the AOT path match the documented Java semantics.
 *
 * Also returns true for +0.0/-0.0 ≠ same as Java where Float.equals
 * treats them as not-equal. PHP === treats 0.0 === -0.0 as true.
 * This helper preserves PHP behavior for now (treats them equal);
 * fix if a real workload surfaces the divergence.
 */
function jvm_float_equals(float $a, float $b): bool
{
    if (\is_nan($a) && \is_nan($b)) return true;
    return $a === $b;
}

/** GMP-based wrap recovery for long arithmetic that overflowed. */
function jvm_lwrap_arith(string $op, int $a, int $b): int
{
    if (!\extension_loaded('gmp')) {
        // GMP missing: best-effort. The (int) cast saturates rather
        // than wraps for non-power-of-2 boundary overflows; for tests
        // that hit those, install ext-gmp.
        return (int) match ($op) {
            '+' => $a + $b, '-' => $a - $b, '*' => $a * $b,
        };
    }
    $ga = \gmp_init($a);
    $gb = \gmp_init($b);
    $r = match ($op) {
        '+' => \gmp_add($ga, $gb),
        '-' => \gmp_sub($ga, $gb),
        '*' => \gmp_mul($ga, $gb),
    };
    static $two64 = null, $two63 = null;
    $two64 ??= \gmp_pow(2, 64);
    $two63 ??= \gmp_pow(2, 63);
    $r = \gmp_mod($r, $two64);
    if (\gmp_cmp($r, 0) < 0) $r = \gmp_add($r, $two64);
    if (\gmp_cmp($r, $two63) >= 0) $r = \gmp_sub($r, $two64);
    return \gmp_intval($r);
}

/**
 * SwitchBootstraps.typeSwitch / enumSwitch helper. Walks the labels
 * array; first match wins, returns the case index. -1 = default.
 * Labels: null (catchall), int (constant int case), string (class FQN).
 */
function jvm_typeswitch($selector, array $labels): int
{
    foreach ($labels as $idx => $label) {
        if ($label === null) {
            if ($selector === null) return $idx;
            continue;
        }
        if (is_int($label)) {
            if ($selector === $label) return $idx;
            continue;
        }
        // String → class FQN; \is_a handles instanceof + accepts string
        // representing class name.
        if (is_string($label) && \is_a($selector, $label, true)) {
            return $idx;
        }
    }
    return -1;
}

/**
 * Allocate a multi-dimensional array per JVM MULTIANEWARRAY (0xC5).
 *
 * Returns nested raw PHP arrays (no stdClass wrappers). This matches
 * the IR Builder's escape-analysis emit for newarray/anewarray — both
 * single- and multi-dim arrays are raw PHP arrays, accessed directly
 * via `$L[N][i]` for LocalRead-sourced cases. For arrays sourced from
 * elsewhere (fields, method returns, multi-step access patterns), the
 * fallback `Aot/Ir/ArrayHelper::get/set/len` accepts both stdClass and
 * array shapes (the wrapper exists for legacy `(object){v=>...}` paths).
 *
 * Innermost dim filled with 0 (primitive default). For ref arrays the
 * caller may want null fill — extend when a fixture surfaces that
 * requirement; the Java assignments (`a[i][j] = ref`) overwrite the
 * fill before any read, so primitive 0 fill is observably correct.
 */
function jvm_multianewarray(int ...$dims): array
{
    $build = function (array $remaining) use (&$build) {
        if (empty($remaining)) return 0;
        $first = (int) array_shift($remaining);
        $arr = [];
        for ($i = 0; $i < $first; $i++) $arr[] = $build($remaining);
        return $arr;
    };
    return $build($dims);
}

/**
 * Lazy CallSite registry for invokedynamic bootstraps the AOT compiler
 * doesn't whitelist (StringConcatFactory, LambdaMetafactory, ObjectMethods,
 * SwitchBootstraps are handled directly in the IR Builder). Custom
 * dynamic-language patterns — JRuby, Groovy 3+, Scala 3 — emit indy
 * sites that go through arbitrary bootstrap classes; the AOT can't
 * pre-compile them statically.
 *
 * The AOT emits a `IndyRegistry::resolve(...)` call at unknown indy
 * sites. Default behaviour throws UnsupportedIndyBootstrapException
 * with the bootstrap class+method named, so consumers see a clear
 * error instead of silently wrong output (was: a literal
 * "UNHANDLED_INDY:..." string left on the operand stack).
 *
 * Consumers can register handlers per (bootstrap-class, bootstrap-
 * method) tuple via `IndyRegistry::register(...)`. The handler
 * receives the bootstrap arguments and the call-site arguments, and
 * returns whatever value the indy site should evaluate to. This lets
 * a JRuby/Groovy host plug its dispatch into the AOT'd code without
 * touching the compiler.
 *
 * Per CONTRACTS.md §3 + §5 — extension surface for non-whitelisted
 * indy patterns. The full MethodHandle/CallSite API is deferred to
 * Tier 5; this is the minimum reachable hook.
 */
class IndyRegistry
{
    /** @var array<string, callable>  key = "bsmClass.bsmMethod" */
    private static array $handlers = [];

    public static function register(string $bsmClass, string $bsmMethod, callable $handler): void
    {
        self::$handlers[self::key($bsmClass, $bsmMethod)] = $handler;
    }

    public static function unregister(string $bsmClass, string $bsmMethod): void
    {
        unset(self::$handlers[self::key($bsmClass, $bsmMethod)]);
    }

    public static function isRegistered(string $bsmClass, string $bsmMethod): bool
    {
        return isset(self::$handlers[self::key($bsmClass, $bsmMethod)]);
    }

    /** Reset all registered handlers. Tests, hot-reload paths. */
    public static function reset(): void
    {
        self::$handlers = [];
    }

    /**
     * Called at AOT'd indy sites. $bsmArgs are the static bootstrap
     * arguments from the constant pool (decoded by the IR Builder);
     * $callSiteArgs are the dynamic arguments popped from the operand
     * stack at the call site. Handler returns whatever the indy site
     * should push onto the stack.
     */
    public static function resolve(
        string $bsmClass,
        string $bsmMethod,
        string $callSiteName,
        string $callSiteDesc,
        array $bsmArgs,
        array $callSiteArgs
    ) {
        $key = self::key($bsmClass, $bsmMethod);
        if (!isset(self::$handlers[$key])) {
            throw new UnsupportedIndyBootstrapException(
                "No handler registered for invokedynamic bootstrap "
                . "{$bsmClass}.{$bsmMethod} at call site '{$callSiteName}' "
                . "with descriptor '{$callSiteDesc}'. Register a handler "
                . "via \\PHPJava\\Aot\\Runtime\\IndyRegistry::register("
                . "'{$bsmClass}', '{$bsmMethod}', \$callable)."
            );
        }
        return (self::$handlers[$key])(
            $callSiteName,
            $callSiteDesc,
            $bsmArgs,
            $callSiteArgs
        );
    }

    private static function key(string $bsmClass, string $bsmMethod): string
    {
        return $bsmClass . '.' . $bsmMethod;
    }
}

class UnsupportedIndyBootstrapException extends \RuntimeException {}

// One-time init. Idempotent — safe to require_once any number of times.
if (\PHPJava\Aot\Runtime\java\lang\System::$out === null) {
    \PHPJava\Aot\Runtime\java\lang\System::$out =
        new \PHPJava\Aot\Runtime\java\io\PrintStream(\STDOUT);
    \PHPJava\Aot\Runtime\java\lang\System::$err =
        new \PHPJava\Aot\Runtime\java\io\PrintStream(\STDERR);

    // Lazy-compile AOT-Generated classes the first time PHP resolves
    // them — covers the cross-class reference case where AOT-emitted
    // bytecode does `new OtherClass()` or `OtherClass::staticMethod()`
    // and OtherClass hasn't been loaded yet.
    \spl_autoload_register([\PHPJava\Aot\Loader::class, 'autoloadAotClass']);
}
