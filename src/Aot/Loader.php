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
     * Best-effort AOT static dispatch. Returns `[true, $result]` if
     * the class compiles and the method dispatches; returns
     * `[false, null]` on any failure (compile error, unsupported
     * opcode, missing method, runtime exception inside the AOT'd
     * code). Used by the `JavaClass::load → getInvoker` chain to
     * route through AOT when `PHPJAVA_AOT_MODE=lazy`, falling back
     * to the interpreter on failure without surfacing the error.
     *
     * Per `docs/CONTRACTS.md` §3 + §5, this is the lazy-AOT path
     * wired into the standard classloader entry — production
     * reachability via `JavaClass::load` instead of requiring a
     * direct `Loader::callStatic` from the call site.
     */
    public static function tryCallStatic(string $classPath, string $methodName, array $args): array
    {
        // Once a class has failed AOT compile/eval, never retry. Saves
        // the compile cost and avoids dispatching to a partially-
        // declared class whose state may be inconsistent.
        if (isset(self::$failed[$classPath])) return [false, null];
        try {
            if (!isset(self::$loaded[$classPath])) {
                self::loadClass($classPath);
            }
            $aotFqn  = self::aotFqn($classPath);
            $mangled = self::mangleMethod($methodName);
            if (!method_exists($aotFqn, $mangled)) {
                return [false, null];
            }
            return [true, $aotFqn::$mangled(...$args)];
        } catch (\Throwable $e) {
            return [false, null];
        }
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
