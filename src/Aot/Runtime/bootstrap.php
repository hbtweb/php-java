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

// Throwable hierarchy. Java's checked vs unchecked distinction is a
// javac concern, not a runtime one — PHP catch matches by class name.
// We piggyback on PHP's native Throwable hierarchy: Throwable → Exception
// (RuntimeException, IllegalArgumentException, etc.) → custom subclasses.
//
// `__construct(string $message = "")` matches Java's String-arg constructor;
// the Throwable cause-chain (`Throwable cause` arg) is mapped to PHP's
// $previous. Fields beyond message/cause come back via getMessage() etc.
class Throwable_ extends \Exception {}
class Exception_ extends \Exception {}
class RuntimeException extends \RuntimeException {}
class IllegalArgumentException extends \InvalidArgumentException {}
class IllegalStateException extends \LogicException {}
class NullPointerException extends \TypeError {}
class ArithmeticException extends \DivisionByZeroError {}
class ArrayIndexOutOfBoundsException extends \OutOfBoundsException {}
class ClassCastException extends \TypeError {}
class IndexOutOfBoundsException extends \OutOfBoundsException {}
class NumberFormatException extends \InvalidArgumentException {}
class UnsupportedOperationException extends \BadMethodCallException {}

namespace PHPJava\Aot\Runtime\java\io;

class PrintStream
{
    /** @var resource */
    private $stream;

    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function println($x = null): void
    {
        \fwrite($this->stream, ((string)$x) . "\n");
    }

    public function print($x = null): void
    {
        \fwrite($this->stream, (string)$x);
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
 * Inner dimensions filled with 0 for primitive elements (the AOT
 * doesn't currently distinguish primitive vs reference for this op);
 * for reference arrays the caller would need null inner-fill — extend
 * if a fixture surfaces that requirement.
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
}
