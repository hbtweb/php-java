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
    /** @var array<string, true>  classPath → loaded marker */
    private static array $loaded = [];

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
        $php = (new Compiler())->compileBytes($classPath, $classBytes);
        self::evalAotSource($php);
        self::$loaded[$classPath] = true;
    }

    /**
     * Load and AOT-compile a class via PHPJava's registered classpath
     * (file/JAR/shim — see `ClassResolver`). Mirror of `JavaClass::load`
     * for the AOT strategy.
     */
    public static function loadClass(string $classPath): void
    {
        if (isset(self::$loaded[$classPath])) return;
        $php = (new Compiler())->compileClass($classPath);
        self::evalAotSource($php);
        self::$loaded[$classPath] = true;
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

    /** Whether a class has been AOT-loaded into the running process. */
    public static function isLoaded(string $classPath): bool
    {
        return isset(self::$loaded[$classPath]);
    }

    /** Reset loaded-class registry. Tests, hot-reload paths. */
    public static function reset(): void
    {
        self::$loaded = [];
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
