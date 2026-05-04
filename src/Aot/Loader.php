<?php
declare(strict_types=1);
namespace PHPJava\Aot;

/**
 * Production entry point for AOT-compiled invocation. Compiles a class
 * (from raw bytes or via PHPJava's registered classpath) on demand,
 * eval()s the resulting PHP, and dispatches to the AOT-emitted static
 * method.
 *
 * Mirrors the existing chain
 *   `JavaClass::load(...)->getInvoker()->getStatic()->getMethods()->call(...)`
 * but routes through the AOT pipeline. Per `docs/CONTRACTS.md` §3 + §5,
 * this is the AotEager invoker strategy: the AOT path is reachable from
 * a single load call; the caller doesn't construct the Compiler directly.
 *
 * Method name mangling matches `Compiler::mangleMethod` exactly:
 *   `<init>`   → `__construct`
 *   `<clinit>` → `__staticConstruct`
 *   `$`        → `_S_`
 *   `<` / `>`  → `_LT_` / `_GT_`
 *
 * @see docs/CONTRACTS.md §3 + §5 — invoker strategies, dispatch decision
 */
final class Loader
{
    /** @var array<string, true>  classPath → load attempted (success or fail) */
    private static array $loaded = [];

    /** @var array<string, true>  classPath → AOT compile/eval threw — never retry */
    private static array $failed = [];

    /**
     * Load and AOT-compile a class from raw `.class` bytes — the
     * `defineClass(byte[])` extension surface. Subsequent `callStatic`
     * calls dispatch via the AOT-emitted PHP.
     *
     * Used by Clojure-style anonymous-fn loaders, CGLIB-style proxies,
     * mocking frameworks, JVM-language runtimes synthesising classes
     * at runtime.
     */
    public static function defineClass(string $classPath, string $classBytes): void
    {
        if (isset(self::$loaded[$classPath])) return;
        // Mark before compile/eval so a partial eval (class declared,
        // trailing trigger threw) doesn't trigger a redeclare fatal on
        // retry. Compile failures here are deterministic for a given
        // classBytes — no point retrying.
        self::$loaded[$classPath] = true;
        try {
            $php = (new Compiler())->compileBytes($classPath, $classBytes);
            self::evalAotSource($php);
        } catch (\Throwable $e) {
            self::$failed[$classPath] = true;
            throw $e;
        }
    }

    /**
     * Load and AOT-compile a class via PHPJava's registered classpath
     * (file/JAR/shim — see `ClassResolver`). Mirror of `JavaClass::load`
     * for the AOT strategy.
     */
    public static function loadClass(string $classPath): void
    {
        if (isset(self::$loaded[$classPath])) return;
        // Mark before compile/eval — see defineClass for the rationale.
        self::$loaded[$classPath] = true;
        try {
            $php = (new Compiler())->compileClass($classPath);
            self::evalAotSource($php);
        } catch (\Throwable $e) {
            self::$failed[$classPath] = true;
            throw $e;
        }
    }

    /**
     * Static-method dispatch via the AOT path. Auto-loads the class
     * (lazy-AOT — the `aot-lazy` strategy from `docs/CONTRACTS.md` §3)
     * on first call. `$args` are PHP-native per `docs/CONTRACTS.md` §1.
     *
     * @return mixed  whatever the AOT-emitted method returns
     */
    public static function callStatic(string $classPath, string $methodName, ...$args)
    {
        if (!isset(self::$loaded[$classPath])) {
            self::loadClass($classPath);
        }
        $aotFqn  = self::aotFqn($classPath);
        $mangled = self::mangleMethod($methodName);
        return $aotFqn::$mangled(...$args);
    }

    /**
     * AOT static dispatch with controlled fall-through. Returns
     * `[true, $result]` if the class compiles and the named method
     * exists on the AOT-emitted class. Returns `[false, null]` only
     * for the case where the method does not exist as a static method
     * on the compiled class — that's the legitimate fall-through
     * signal during the Phase A AOT-only flip (instance methods reach
     * here today, before the Phase B receiver-shape unification, and
     * fall through to the interpreter dispatch path).
     *
     * **Errors propagate.** Compile failure throws (caller sees the
     * AOT bug). Exceptions thrown by the AOT'd method propagate (legit
     * Java exception flow, or a real AOT bug — both signal). Per
     * `docs/LAYERS.md` Phase A: failure is signal, not silent fallback.
     *
     * Replaces the prior `PHPJAVA_AOT_MODE=lazy` opt-in path. AOT is
     * now the default for static dispatch.
     */
    public static function tryCallStatic(string $classPath, string $methodName, array $args): array
    {
        // Once a class has failed AOT compile/eval, never retry. Saves
        // the compile cost and avoids dispatching to a partially-
        // declared class whose state may be inconsistent. The original
        // failure already threw at the call site that triggered it.
        if (isset(self::$failed[$classPath])) return [false, null];
        if (!isset(self::$loaded[$classPath])) {
            self::loadClass($classPath);
        }
        $aotFqn  = self::aotFqn($classPath);
        $mangled = self::mangleMethod($methodName);
        if (!method_exists($aotFqn, $mangled)) {
            return [false, null];
        }
        // Translate PHP's ArgumentCountError to NoSuchMethodException to
        // match JDK reflective-dispatch semantics: a method invoked with
        // the wrong arity isn't "found" in the JVM-method-resolution
        // sense. Validate up-front via reflection so the caller doesn't
        // see PHP's exception type leak through.
        $rm = new \ReflectionMethod($aotFqn, $mangled);
        $required = $rm->getNumberOfRequiredParameters();
        $total    = $rm->getNumberOfParameters();
        $argc     = \count($args);
        if ($argc < $required || $argc > $total) {
            throw new \PHPJava\Packages\java\lang\NoSuchMethodException(
                "{$classPath}.{$methodName}/{$argc}"
                . " (expected {$required}" . ($required !== $total ? "..{$total}" : '') . ')'
            );
        }
        return [true, $aotFqn::$mangled(...$args)];
    }

    /**
     * AOT instance construction with explicit binary name. Used by IR
     * Lowerer for `new` of a non-current AOT-emitted class, so the load
     * uses the exact JVM binary name (`Outer$Inner`) instead of routing
     * through the autoloader's reverse-mangle heuristic — which is
     * ambiguous for inner classes.
     *
     * Counterpart to `callStatic` for the constructor case.
     */
    public static function newInstance(string $classPath, ...$args)
    {
        if (!isset(self::$loaded[$classPath])) {
            self::loadClass($classPath);
        }
        $aotFqn = self::aotFqn($classPath);
        return new $aotFqn(...$args);
    }

    /** Whether a class has been AOT-loaded into the running process. */
    public static function isLoaded(string $classPath): bool
    {
        return isset(self::$loaded[$classPath]);
    }

    /** Reset loaded-class registry. Tests, hot-reload paths. */
    public static function reset(): void
    {
        self::$loaded = [];
        self::$failed = [];
        Compiler::clearCompileCache();
    }

    /**
     * spl_autoload_register hook: when AOT-emitted code references
     * `\PHPJava\Aot\Generated\<X>` and `<X>` hasn't been AOT-compiled
     * yet, this triggers a lazy compile via `loadClass($binaryName)`.
     *
     * Mangle is `str_replace(['\\', '$'], '_', ...)` over the JVM
     * binary name (with `/` already converted to `\\`). The reverse is
     * lossy when the binary name contains `_` or `$`: we try `_ → /`
     * (the package-separator case, which covers ~all standard usage)
     * and accept the inner-class `Outer$Inner` edge as a known gap.
     *
     * Registered in bootstrap.php's one-time init at the bottom of
     * the file.
     */
    /** @var array<string,true>  in-flight autoload guard (recursion-safe) */
    private static array $autoloadInFlight = [];

    public static function autoloadAotClass(string $className): void
    {
        $prefix = 'PHPJava\\Aot\\Generated\\';
        if (!\str_starts_with($className, $prefix)) {
            return;
        }
        if (isset(self::$autoloadInFlight[$className])) {
            return;
        }
        self::$autoloadInFlight[$className] = true;
        try {
            // Single-candidate heuristic: convert `_` back to `/` (the
            // typical package separator). Verbatim retry covers the
            // package-less case `OuterClassTestOuterClass` which has
            // no `_` to convert. Inner-class names with `$` (mangled
            // to `_`) are a known gap — landing a reverse-map registry
            // would close it cleanly; defer until that work surfaces.
            $mangled = \substr($className, \strlen($prefix));
            // Single-candidate heuristic: `_ → /`. Covers the typical
            // package-separator case (most common) and the package-less
            // case (no `_` in the name, replace is a no-op so we get
            // the verbatim mangled name back).
            //
            // Inner classes (Outer$Inner mangled to Outer_Inner) and
            // the JAR cross-class case are better solved at compile
            // time by IR Lowerer emitting an explicit loadClass call
            // ahead of each `new` — see Lowerer.lowerExpr's `New_` path.
            // The lossy fallback `_ → $` was tried but exploded the
            // JAR-resolver scan time on suites with many cross-refs.
            $bin = \str_replace('_', '/', $mangled);
            try {
                self::loadClass($bin);
            } catch (\Throwable $e) {
                // Class isn't resolvable via ClassResolver — let PHP
                // raise its standard "Class not found".
            }
        } finally {
            unset(self::$autoloadInFlight[$className]);
        }
    }

    // ── internals ─────────────────────────────────────────────────

    private static function evalAotSource(string $php): void
    {
        // bootstrap.php declares System / PrintStream and helper
        // functions (jvm_lushr, jvm_multianewarray) — required at
        // runtime by AOT'd code. Idempotent require.
        require_once __DIR__ . '/Runtime/bootstrap.php';
        // `eval` expects no <?php tag; strip the leading one.
        $php = preg_replace('/^<\?php\s*/', '', $php);
        eval($php);
    }

    private static function aotFqn(string $classPath): string
    {
        return '\\PHPJava\\Aot\\Generated\\'
            . str_replace(['.', '/', '\\', '$'], '_', $classPath);
    }

    private static function mangleMethod(string $name): string
    {
        if ($name === '<init>')   return '__construct';
        if ($name === '<clinit>') return '__staticConstruct';
        return str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name);
    }
}
