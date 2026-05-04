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
        if ($i < 0 || $i >= \strlen($s)) {
            // JDK throws StringIndexOutOfBoundsException, which extends
            // IndexOutOfBoundsException — both `catch` patterns work.
            throw new StringIndexOutOfBoundsException("String index out of range: {$i}");
        }
        return \ord($s[$i]);
    }

    public static function length(string $s): int { return \strlen($s); }

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
