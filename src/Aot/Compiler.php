<?php
declare(strict_types=1);
namespace PHPJava\Aot;

use PHPJava\Core\JavaClass;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Core\Stream\Reader\InlineReader;
use PHPJava\Kernel\Attributes\BootstrapMethodsAttribute;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Maps\FieldAccessFlag;
use PHPJava\Kernel\Maps\MethodAccessFlag;
use PHPJava\Kernel\Resolvers\AttributionResolver;
use PHPJava\Kernel\Structures\ClassInfo;
use PHPJava\Kernel\Structures\DoubleInfo;
use PHPJava\Kernel\Structures\FieldrefInfo;
use PHPJava\Kernel\Structures\FloatInfo;
use PHPJava\Kernel\Structures\IntegerInfo;
use PHPJava\Kernel\Structures\InterfaceMethodrefInfo;
use PHPJava\Kernel\Structures\InvokeDynamicInfo;
use PHPJava\Kernel\Structures\LongInfo;
use PHPJava\Kernel\Structures\MethodHandleInfo;
use PHPJava\Kernel\Structures\MethodInfo;
use PHPJava\Kernel\Structures\MethodrefInfo;
use PHPJava\Kernel\Structures\NameAndTypeInfo;
use PHPJava\Kernel\Structures\StringInfo;
use PHPJava\Kernel\Structures\Utf8Info;

/**
 * Naive AOT compiler — walks PHPJava's parsed bytecode and emits a PHP
 * function per Java method. Validates the architectural claim that
 * interpreter and AOT are the same compiler with different consumers.
 *
 * Operand-stack model: PHP array. Each opcode emits PHP statements that
 * push/pop the stack. The "naive AOT" level from bench/spike-fast-interp.php.
 *
 * JIT-friendly emit (rank-1 verified 2026-05-02 via bench/bench-aot.php):
 *
 *   1. Fixed-arity method signatures. Variadic `...$__args` defeats PHP's
 *      tracing JIT (measured 4× regression).
 *   2. No null-coalesce defaults on local reads. JVM verifier guarantees
 *      every iload follows a corresponding istore on the same slot —
 *      `$L[N]` direct, no `?? 0`.
 *   3. Methods emit explicit `$L[i] = $__ai` prelude rather than
 *      `$L = $__args` — keeps slot indices monotonic for the JIT.
 *   4. Caller side (bench harness, AOT-emitted call sites) uses literal
 *      class names; variable class names defeat JIT specialisation.
 */
final class Compiler
{
    /** @var \PHPJava\Kernel\Structures\StructureInterface[] */
    private array $constantPool;

    /** Binary name (java/foo/Bar) of the class currently being compiled. */
    private string $currentClassBin = '';

    /**
     * Single-expression inlinable methods discovered during compile.
     * Keyed by mangled method name. Each entry: ['params' => [...],
     * 'returnExpr' => string]. Used by the inline pass to substitute
     * `self::name(args)` calls with the method's return expression.
     * @var array<string, array{params: list<string>, returnExpr: string}>
     */
    private array $inlinableMethods = [];

    /**
     * BootstrapMethod entries for INVOKEDYNAMIC resolution. Indexed by
     * the bootstrap_method_attr_index from CP_InvokeDynamic.
     * @var \PHPJava\Kernel\Structures\BootstrapMethod[]
     */
    private array $bootstrapMethods = [];

    /**
     * Synthetic lambda classes generated during this compileClass call.
     * Each entry: ['fqn' => '...', 'php' => '<full class definition>'].
     * Emitted after the main class body so they're loadable by the
     * `new` site that references them.
     * @var array<int, array{fqn:string, php:string}>
     */
    private array $lambdaClasses = [];

    private int $lambdaCounter = 0;

    /**
     * LRU cache for compileClass — keyed on classPath. Hit skips
     * JavaClass::load + JCC reflection + parse + IR build + lower.
     * Bounded to `cacheMaxSize()` entries; oldest is evicted on insert
     * when full. PHP arrays preserve insertion order, so re-inserting
     * on hit promotes to most-recent (true LRU, not FIFO).
     */
    private static array $compileClassCache = [];

    /**
     * Cache hit/miss/eviction counters. Daemon production visibility per
     * ROADMAP §Refinement #2 — without these, a daemon loading > cacheMaxSize
     * distinct classes thrashes silently. Read via cacheStats().
     *
     * @var array{compileClass:array{hits:int,misses:int,evictions:int},compileBytes:array{hits:int,misses:int,evictions:int}}
     */
    private static array $cacheStats = [
        'compileClass' => ['hits' => 0, 'misses' => 0, 'evictions' => 0],
        'compileBytes' => ['hits' => 0, 'misses' => 0, 'evictions' => 0],
    ];

    public function compileClass(string $classPath): string
    {
        if (isset(self::$compileClassCache[$classPath])) {
            self::$cacheStats['compileClass']['hits']++;
            // Promote to most-recent: remove + reinsert.
            $cached = self::$compileClassCache[$classPath];
            unset(self::$compileClassCache[$classPath]);
            self::$compileClassCache[$classPath] = $cached;
            return $cached;
        }
        self::$cacheStats['compileClass']['misses']++;
        $cls = JavaClass::load($classPath);
        // JavaClass wraps a JavaCompiledClass via `genericClass` (private);
        // reach in via reflection — fine for a spike. The production
        // compiler will get a proper accessor.
        $rc = new \ReflectionClass($cls);
        $prop = $rc->getProperty('genericClass');
        $prop->setAccessible(true);
        /** @var JavaCompiledClass $jcc */
        $jcc = $prop->getValue($cls);

        $out = $this->compileFromGenericClass($jcc, $classPath);
        self::lruInsert(self::$compileClassCache, $classPath, $out, 'compileClass');
        return $out;
    }

    /**
     * Compile from raw `.class` bytes — the `defineClass(byte[])` path.
     *
     * Used when bytecode arrives at runtime (custom classloaders,
     * mocking frameworks, JVM-language runtimes that synthesise class
     * files on the fly — Clojure's anonymous fn classes, JRuby's
     * generated Ruby method dispatchers, CGLIB's subclass proxies).
     *
     * `$classPath` is the dotted class name used for the emitted PHP
     * namespace and FQN — caller is responsible for matching it to
     * what's actually inside the bytes (parser doesn't enforce this).
     */
    /**
     * Per-bytecode-hash compile cache. Keys: xxh3 of the class bytes;
     * values: the rendered PHP source. Hits skip JCC parse + IR build
     * + Lower entirely — closes the 1280µs JCC-parse bottleneck per
     * the 2026-05-03 compile-time profile (parser is 83% of total).
     *
     * @var array<string, string>
     */
    private static array $compileBytesCache = [];

    /**
     * Reset the compile cache. Useful in tests, or when the AOT
     * runtime/shim layer is refreshed and cached output may reference
     * stale class FQNs.
     */
    public static function clearCompileCache(): void
    {
        self::$compileBytesCache = [];
        self::$compileClassCache = [];
    }

    /**
     * @param array<string,string> $substitutionMap  Optional cross-runtime
     *        FQN remap consulted at every CP class-ref / Methodref
     *        resolution. Keys are JVM binary names (e.g.
     *        `clojure/lang/PersistentHashMap`); values are PHP FQNs to
     *        emit instead of the default `\PHPJava\Aot\Runtime\…` path.
     *        Used by the cljp dual-runtime story — see
     *        `~/GitHub/ClojurePHP/docs/CLJP-POSITIONING.md`
     *        §"The substitution table is the load-bearing piece". The
     *        cache key includes a hash of the map so substituted and
     *        non-substituted compiles don't collide.
     */
    public function compileBytes(string $classPath, string $classBytes, array $substitutionMap = []): string
    {
        // Cache key: hash of (classPath, bytes, substitutionMap).
        // Different class names for the same bytes produce different
        // output (the namespace + class identifier change), and the
        // substitution map changes every cross-class resolution — both
        // go into the key.
        $mapHash = empty($substitutionMap) ? '' : \hash('xxh3', \json_encode($substitutionMap));
        $key = \hash('xxh3', $classPath . "\0" . $classBytes . "\0" . $mapHash);
        if (isset(self::$compileBytesCache[$key])) {
            self::$cacheStats['compileBytes']['hits']++;
            // Promote to most-recent (LRU).
            $cached = self::$compileBytesCache[$key];
            unset(self::$compileBytesCache[$key]);
            self::$compileBytesCache[$key] = $cached;
            return $cached;
        }
        self::$cacheStats['compileBytes']['misses']++;
        $this->substitutionMap = $substitutionMap;
        try {
            $jcc = new JavaCompiledClass(new InlineReader($classPath, $classBytes));
            $out = $this->compileFromGenericClass($jcc, $classPath);
        } finally {
            $this->substitutionMap = [];
        }
        self::lruInsert(self::$compileBytesCache, $key, $out, 'compileBytes');
        return $out;
    }

    /** @var array<string,string>  Threaded into Builder during one compile. */
    private array $substitutionMap = [];

    /**
     * Cache observability for daemon production deploys. Returns
     * per-cache hit/miss/eviction counts plus current size.
     *
     * Counters reset only on clearCompileCache(). For a Swoole or
     * RoadRunner worker, sample at scrape interval to compute hit rate.
     * Eviction count > 0 means cache is at capacity; raise
     * `PHPJAVA_AOT_CACHE_MAX` if working set exceeds it.
     *
     * @return array{compileClass:array{hits:int,misses:int,evictions:int,size:int},compileBytes:array{hits:int,misses:int,evictions:int,size:int}}
     */
    public static function cacheStats(): array
    {
        return [
            'compileClass' => self::$cacheStats['compileClass']
                + ['size' => \count(self::$compileClassCache)],
            'compileBytes' => self::$cacheStats['compileBytes']
                + ['size' => \count(self::$compileBytesCache)],
        ];
    }

    /** Reset the hit/miss counters without flushing the caches themselves. */
    public static function resetCacheStats(): void
    {
        self::$cacheStats = [
            'compileClass' => ['hits' => 0, 'misses' => 0, 'evictions' => 0],
            'compileBytes' => ['hits' => 0, 'misses' => 0, 'evictions' => 0],
        ];
    }

    /**
     * Maximum entries per cache (compileClass + compileBytes each).
     * Configurable via env `PHPJAVA_AOT_CACHE_MAX`; default 1000.
     * 1000 entries × ~10 KB rendered PHP each ≈ 10 MB ceiling per cache,
     * 20 MB combined — safe for long-running daemons.
     */
    private static function cacheMaxSize(): int
    {
        static $max = null;
        if ($max === null) {
            $env = getenv('PHPJAVA_AOT_CACHE_MAX');
            $max = ($env !== false && ctype_digit($env) && (int)$env > 0)
                ? (int)$env
                : 1000;
        }
        return $max;
    }

    /**
     * LRU insert helper. Caller has already verified the key isn't
     * present. If at capacity, evict the oldest entry (insertion-order
     * front) before inserting at the back.
     *
     * @param array<string,string> $cache mutated by reference
     */
    private static function lruInsert(array &$cache, string $key, string $value, ?string $statsKey = null): void
    {
        $max = self::cacheMaxSize();
        if (count($cache) >= $max) {
            // array_shift drops the oldest insertion-ordered entry.
            array_shift($cache);
            if ($statsKey !== null) {
                self::$cacheStats[$statsKey]['evictions']++;
            }
        }
        $cache[$key] = $value;
    }

    private function compileFromGenericClass(JavaCompiledClass $jcc, string $classPath): string
    {
        $this->constantPool = $jcc->getConstantPool()->getEntries();
        $this->currentClassBin = str_replace('.', '/', $classPath);
        $this->lambdaClasses = [];
        $this->lambdaCounter = 0;
        $this->inlinableMethods = [];

        // Inner-class resolution gap (`Outer$Inner` mangled to
        // `Outer_Inner` is reverse-ambiguous against a real
        // `Outer_Inner` class). Eager-load was attempted here but
        // caused JAR-resolver hangs on suites with multiple registered
        // classpath roots. Future fix: thread the binary name through
        // `New_` / `StaticCall` IR nodes and emit `Loader::loadClass($bin)`
        // inline in the Lowerer — known binary name, no autoloader
        // heuristic needed. Tracked as JarTest::testEnclosingMethodInJar,
        // EnclosingMethodTest, InnerClassTest.
        // Fresh IR Builder/Lowerer per compileClass — keeps the lambda
        // counter and BootstrapMethods cache scoped to this class.
        $this->irBuilder = null;
        $this->irLowerer = null;
        // Pull the BootstrapMethods attribute (Java 7+) — drives
        // INVOKEDYNAMIC resolution. Empty array if the class has no
        // indy call sites (most pre-Java-9 code). PHPJava wraps
        // attributes in `AttributeInfo` containers; the typed inner
        // attribute is reached via `getAttributeData()`. Use
        // `AttributionResolver::resolve` (same pattern as CodeAttribute
        // lookup above) to find the BootstrapMethods entry.
        $this->bootstrapMethods = [];
        try {
            $bsmAttr = AttributionResolver::resolve(
                $jcc->getAttributes(),
                BootstrapMethodsAttribute::class
            );
            $this->bootstrapMethods = $bsmAttr->getBootstrapMethods();
        } catch (\PHPJava\Exceptions\UnableToFindAttributionException $e) {
            // No BootstrapMethods attribute — class has no indy call sites.
        }
        $methods = $jcc->getDefinedMethods();

        // Java method overloading by parameter type — same name, different
        // descriptor — is legal JVM-side and very common. PHP has no
        // method overloading. Resolution: descriptor-mangle each overload
        // (`main_aI`, `main_aLjava_lang_String_`) and emit a `main(...)`
        // dispatcher that picks at runtime by arg-shape inspection. Names
        // with a single descriptor stay simple-mangled (no dispatcher
        // overhead).
        //
        // The overload index drives both Compiler emit (which methods get
        // descriptor suffixes; which need a dispatcher) AND IR Builder
        // (self-class invokes use descriptor-mangled name when the target
        // is overloaded).
        $this->overloadIndex = $this->buildOverloadIndex($methods);

        // Two-phase: collect IR Methods (or string-emitted method
        // strings if IR fallback) → run IR InlinePass on the Module
        // → lower the Module to final PHP source. This replaces the
        // earlier per-method streaming + post-emit-text inline pass
        // with a structural IR transform.
        /** @var \PHPJava\Aot\Ir\Method[] $irMethods */
        $irMethods = [];
        $stringEmittedMethods = []; // for fallback case
        $hasClinit = false;
        foreach ($methods as $method) {
            $name = $this->utf8At($method->getNameIndex());
            $desc = $this->utf8At($method->getDescriptorIndex());
            // <clinit> emits as __staticConstruct (mangleMethod). Triggered
            // post-class-definition, after the class+field declarations
            // are in place — see the trailing `::__staticConstruct()` call
            // appended below.
            if ($name === '<clinit>') {
                $hasClinit = true;
            }

            try {
                $codeAttr = AttributionResolver::resolve(
                    $method->getAttributes(),
                    CodeAttribute::class
                );
            } catch (\PHPJava\Exceptions\UnableToFindAttributionException $e) {
                // No Code attribute = abstract method (interface signature
                // or abstract class method). Emit a PHP `abstract` stub
                // so the abstract class declaration is well-formed and
                // implementing classes get a clear "must override" hook.
                $stringEmittedMethods[] = $this->emitAbstractMethodStub(
                    $name, $desc,
                    ($method->getAccessFlag() & MethodAccessFlag::ACC_STATIC) !== 0
                );
                continue;
            }

            $isStatic = ($method->getAccessFlag() & MethodAccessFlag::ACC_STATIC) !== 0;

            $irMethod = $this->tryBuildIrMethod(
                $jcc, $classPath, $name, $desc,
                $codeAttr->getCode(), $codeAttr->getExceptionTables(),
                $isStatic
            );
            if ($irMethod !== null) {
                $irMethods[] = $irMethod;
            } else {
                $stringEmittedMethods[] = $this->compileMethod(
                    $classPath, $name, $desc,
                    $codeAttr->getCode(), $codeAttr->getExceptionTables(),
                    $isStatic
                );
            }
        }

        // Build a Module from the IR methods, run optimisations, lower.
        $emittedMethods = $stringEmittedMethods;
        if (!empty($irMethods)) {
            $module = new \PHPJava\Aot\Ir\Module(
                'PHPJava\\Aot\\Generated',
                $this->mangle($classPath),
                $irMethods,
                $this->irBuilder?->getLambdaClasses() ?? [],
            );
            // IR-level cross-method inlining (replaces post-emit-text
            // inlineAcrossText for IR-built methods).
            (new \PHPJava\Aot\Ir\InlinePass())->run($module);
            // Async-emit specialiser: collapse `CompletableFuture::
            // supplyAsync(s)->get()` and similar immediate-await
            // peepholes to direct callable invocation, skipping the
            // executor at hot call sites. ROADMAP §Build T3 step 5.
            (new \PHPJava\Aot\Ir\AsyncSpecialiserPass())->run($module);
            // Wrapper escape analysis (Phase 3 of the String identity
            // contract, CONTRACTS.md §1). Elide String_Identity
            // allocations whose identity isn't observed within the
            // method — restores Phase 1's perf shape for the common
            // case (concat → println / value-method) while keeping
            // the wrapper at sites where identity IS observed
            // (identityHashCode, intern, ===, escape past method).
            (new \PHPJava\Aot\Ir\WrapperEscapePass())->run($module);
            // Lower each method to its final PHP source.
            foreach ($module->methods as $m) {
                $emittedMethods[] = $this->irLowerer->lowerMethod($m);
            }
            // Merge module lambdas into the Compiler's lambdaClasses list.
            foreach ($module->lambdaClasses as $lc) {
                $this->lambdaClasses[] = $lc;
            }
        }

        // Emit overload dispatchers — one `<simple>(...$args)` per
        // overloaded name, picking the right `<simple>_<descMangle>`
        // overload by arg-count + arg-shape. Drops in alongside the
        // descriptor-mangled overloads. Names with a single descriptor
        // emit no dispatcher (no overhead).
        foreach ($this->overloadIndex as $simpleName => $descriptors) {
            // Determine static-vs-instance from the FIRST overload —
            // all overloads of a Java method share the static flag (a
            // class can't have a static and an instance method with
            // the same name).
            $isStatic = false;
            foreach ($methods as $m) {
                if ($this->mangleMethod($this->utf8At($m->getNameIndex())) === $simpleName) {
                    $isStatic = ($m->getAccessFlag() & MethodAccessFlag::ACC_STATIC) !== 0;
                    break;
                }
            }
            $emittedMethods[] = $this->emitOverloadDispatcher($simpleName, $descriptors, $isStatic);
        }

        // Emit field declarations from FieldPool. Without these, putfield
        // emits hit dynamic-property creation (deprecated in PHP 8.2+,
        // error in 9). Static fields → `public static $name = default;`,
        // instance fields → `public $name = default;`. Default value
        // derived from the descriptor's primitive type (int/long → 0,
        // float/double → 0.0, boolean → false, ref/array → null).
        $fieldDecls = $this->emitFieldDeclarations($jcc);

        $body = implode("\n\n", $emittedMethods);
        if ($fieldDecls !== '') {
            $body = $fieldDecls . "\n\n" . $body;
        }
        // ── cross-method inline pass ────────────────────────────────
        // After all methods have registered any inlinable single-
        // expression bodies, substitute `self::<name>(args)` call
        // sites in the concatenated body with the parameterised
        // return expression. Runs to fixpoint so a chain of inlinable
        // calls (a calls b calls c) collapses end-to-end.
        $body = $this->inlineAcrossText($body);
        // #[\AllowDynamicProperties]: AOT-emitted classes don't yet
        // declare PHP fields per Java FieldInfo (gap #2-B). Without
        // this attribute, putfield emits hit PHP 8.2+ deprecation
        // warnings (and PHP 9 errors) on dynamic property creation.
        // Drop once #2-B emits explicit `public $field;` declarations.
        // Drop `final`: AOT-emitted classes may be parents of other AOT
        // classes (anonymous inner classes extend their enclosing class;
        // explicit subclasses extend their superclass). PHP's `final`
        // would block that. JIT specialisation doesn't depend on `final`
        // in PHP, so the loss is purely the language-level seal.
        //
        // Emit `extends \PHPJava\Aot\Generated\X` when the JVM superclass
        // is also an AOT-routed (non-Object, non-JDK) class. The parent
        // declaration is autoloaded via Loader::autoloadAotClass when
        // PHP resolves `extends` at class-decl time.
        $superBin = $this->aotSuperClassBin($jcc);
        $extends = $superBin !== null
            ? ' extends \\PHPJava\\Aot\\Generated\\' . $this->mangle($superBin)
            : '';
        // Pre-load the parent before the class declaration, with the
        // exact JVM binary name. PHP autoloads `extends` targets at
        // class-decl time; the AOT autoloader's `_ → /` heuristic
        // can't reverse `Outer$Inner` (mangled to `Outer_Inner`) —
        // same root cause as the inner-class new/static-call routing.
        $extendsPreload = $superBin !== null
            ? "\\PHPJava\\Aot\\Loader::loadClass('"
              . \addcslashes($superBin, "'\\")
              . "');\n"
            : '';
        // Interfaces are emitted as PHP `abstract class` — single
        // inheritance allows implementing classes to `extends` the
        // interface-as-class, inheriting default-method bodies and
        // honouring the abstract-method contracts. PHP `interface`
        // can't carry method bodies, which is what default methods
        // require; PHP `trait` can but doesn't compose with type
        // checks. Abstract class is the closest single-shape match.
        $isInterface = ($jcc->getAccessFlag() & \PHPJava\Kernel\Maps\ClassAccessFlag::ACC_INTERFACE) !== 0;
        $classKw = $isInterface ? 'abstract class' : 'class';
        $main = "<?php\nnamespace PHPJava\\Aot\\Generated;\n\n{$extendsPreload}#[\\AllowDynamicProperties]\n{$classKw} {$this->mangle($classPath)}{$extends}\n{\n{$body}\n}\n";

        // Append synthetic lambda classes generated during method emit.
        // Each is a self-contained class definition declared in the same
        // namespace; require_once of the file loads them all atomically.
        if ($this->lambdaClasses) {
            foreach ($this->lambdaClasses as $lc) {
                $main .= "\n" . $lc['php'];
            }
        }
        // Trigger Java's <clinit> (now mangled to __staticConstruct).
        // JVM spec: class is initialised on first active use (static
        // method invocation, static field read/write, instantiation).
        // For our deployment model — AOT classes loaded eagerly through
        // Loader::loadClass / defineClass and called via Loader::callStatic
        // — eval-time trigger is JVM-correct enough: every load is
        // followed by use, and static-field defaults emitted by
        // emitFieldDeclarations are overwritten by the initialiser body
        // before any reader can observe them.
        if ($hasClinit) {
            $fqn = '\\PHPJava\\Aot\\Generated\\' . $this->mangle($classPath);
            $main .= "\n{$fqn}::__staticConstruct();\n";
        }
        return $main;
    }

    /**
     * Try the IR path. Returns the IR Method on success, null if the
     * IR Builder hits an unsupported opcode (caller falls back to
     * string-path). The Module assembly + InlinePass + Lower happens
     * at the end of compileFromGenericClass.
     */
    /**
     * Emit `public [static] $name = default;` declarations for every
     * field in the class's FieldPool. Without these, putfield-emit
     * sites (`$obj->name = $val`) create dynamic properties — fine
     * with #[\AllowDynamicProperties] but cleaner and more JIT-friendly
     * with declared properties.
     *
     * Default values derive from the JVM descriptor's primitive type;
     * ConstantValue attribute (for `static final` literals) not yet
     * threaded — would override the default with the actual constant.
     */
    private function emitFieldDeclarations(JavaCompiledClass $jcc): string
    {
        $cp = $jcc->getConstantPool()->getEntries();
        $lines = [];
        foreach ($jcc->getDefinedFields() as $field) {
            $nameEntry = $cp[$field->getNameIndex()] ?? null;
            $descEntry = $cp[$field->getDescriptorIndex()] ?? null;
            if (!($nameEntry instanceof Utf8Info)
                || !($descEntry instanceof Utf8Info)) {
                continue;
            }
            $name = $nameEntry->getString();
            $desc = $descEntry->getString();
            // Java field names allow `$` (synthetic / inner-class refs);
            // PHP property names follow the same rule. Mangle anyway
            // for safety (matches mangle helper for class names).
            $phpName = str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name);
            $isStatic = ($field->getAccessFlag() & FieldAccessFlag::ACC_STATIC) !== 0;
            $default = $this->primitiveDefault($desc);
            $kw = $isStatic ? 'public static' : 'public';
            $lines[] = "    {$kw} \${$phpName} = {$default};";
        }
        return implode("\n", $lines);
    }

    /** JVM descriptor → PHP literal for the field's default value. */
    private function primitiveDefault(string $desc): string
    {
        return match ($desc[0] ?? '') {
            'I', 'J', 'B', 'S', 'C' => '0',
            'F', 'D' => '0.0',
            'Z' => 'false',
            '[', 'L' => 'null',
            default => 'null',
        };
    }

    /**
     * Returns the JVM binary name of the superclass when it should be
     * emitted as a PHP `extends` target — i.e. when the parent is an
     * AOT-routed class (not Object, not a JDK shim). Null otherwise.
     *
     * Java interfaces are emitted as PHP abstract classes — single
     * inheritance suffices for the failing test surface (anon class
     * implementing one interface). When this class's JVM superclass
     * is Object but it implements an interface, treat the first
     * interface as the extends target. Multi-interface implementation
     * needs PHP traits; deferred.
     *
     * JDK supers (java/lang/Throwable etc.) are intentionally not
     * chained: the AOT runtime shims for exceptions are flat classes
     * extending the legacy Packages exceptions; threading an AOT-class
     * extends chain through them would couple the AOT shape to the
     * legacy hierarchy and isn't required for the failing test surface.
     */
    private function aotSuperClassBin(JavaCompiledClass $jcc): ?string
    {
        $bin = $jcc->getSuperClassName();
        if ($bin !== null && !$this->isJdkBin($bin) && $bin !== 'java/lang/Object') {
            return $bin;
        }
        // Object super + implements interface(s): use first interface
        // as extends target (interface emitted as abstract class).
        $ifaces = $jcc->getInterfaceBinaryNames();
        foreach ($ifaces as $iface) {
            if (!$this->isJdkBin($iface)) {
                return $iface;
            }
        }
        return null;
    }

    private function isJdkBin(string $bin): bool
    {
        return \str_starts_with($bin, 'java/')
            || \str_starts_with($bin, 'javax/')
            || \str_starts_with($bin, 'jdk/')
            || \str_starts_with($bin, 'sun/')
            || \str_starts_with($bin, 'com/sun/');
    }

    /**
     * Emit a PHP `abstract` method stub for a Java method without a
     * Code attribute (interface signature, abstract class method).
     * The implementing class's concrete override satisfies PHP's
     * abstract-method contract.
     */
    private function emitAbstractMethodStub(string $name, string $desc, bool $isStatic): string
    {
        $argTypes = $this->parseDescriptorArgTypes($desc);
        $params = [];
        foreach ($argTypes as $i => $_) {
            $params[] = "\$__a{$i}";
        }
        $paramStr = \implode(', ', $params);
        $mangled = self::mangleMethodForOverload($name, $desc, $this->overloadIndex);
        $sigQual = $isStatic ? 'public static function' : 'public function';
        return "    abstract {$sigQual} {$mangled}({$paramStr});";
    }

    private function tryBuildIrMethod(
        JavaCompiledClass $jcc, string $classPath, string $methodName,
        string $descriptor, string $bytecode, array $exceptionTables,
        bool $isStatic = true
    ): ?\PHPJava\Aot\Ir\Method {
        try {
            if (!isset($this->irBuilder)) {
                $this->irBuilder = new \PHPJava\Aot\Ir\Builder();
                $this->irBuilder->setAotClassFqn(
                    '\\PHPJava\\Aot\\Generated\\' . $this->mangle($classPath)
                );
                $this->irBuilder->setSuperClassBin(
                    $this->aotSuperClassBin($jcc)
                );
                $this->irBuilder->setOverloadIndex($this->overloadIndex);
                $this->irBuilder->setSubstitutionMap($this->substitutionMap);
            }
            if (!isset($this->irLowerer)) {
                $this->irLowerer = new \PHPJava\Aot\Ir\Lowerer();
            }
            return $this->irBuilder->buildMethod(
                $jcc, $methodName, $descriptor, $bytecode,
                str_replace('.', '/', $classPath), $exceptionTables,
                $isStatic
            );
        } catch (\Throwable $e) {
            if (getenv('CLJP_IR_DEBUG')) {
                fwrite(STDERR, "IR fallback for {$methodName}: " . $e->getMessage() . "\n");
            }
            return null;
        }
    }

    private ?\PHPJava\Aot\Ir\Builder $irBuilder = null;
    private ?\PHPJava\Aot\Ir\Lowerer $irLowerer = null;

    /** Detect single-expression inlinable IR methods. Registers them
     *  in $this->inlinableMethods so the existing string-level inline
     *  pass at end of compileFromGenericClass picks them up. */
    private function detectInlinableFromIr(\PHPJava\Aot\Ir\Method $m): void
    {
        if (count($m->blocks) !== 1) return;
        $bb = reset($m->blocks);
        if (count($bb->stmts) !== 0) return;
        if (!$bb->term instanceof \PHPJava\Aot\Ir\Return_) return;
        if ($bb->term->value === null) return;
        if (!$this->irExprIsInlinePure($bb->term->value)) return;

        $expr = $this->irLowerer->lowerExpr($bb->term->value);
        $this->inlinableMethods[$m->name] = [
            'params' => $m->params,
            'returnExpr' => $expr,
        ];
    }

    private function irExprIsInlinePure(\PHPJava\Aot\Ir\Expr $e): bool
    {
        if ($e instanceof \PHPJava\Aot\Ir\IntLit
            || $e instanceof \PHPJava\Aot\Ir\FloatLit
            || $e instanceof \PHPJava\Aot\Ir\StringLit
            || $e instanceof \PHPJava\Aot\Ir\NullLit
            || $e instanceof \PHPJava\Aot\Ir\LocalRead
            || $e instanceof \PHPJava\Aot\Ir\ParamRead) return true;
        if ($e instanceof \PHPJava\Aot\Ir\BinOp) {
            return $this->irExprIsInlinePure($e->left) && $this->irExprIsInlinePure($e->right);
        }
        if ($e instanceof \PHPJava\Aot\Ir\UnaryOp) {
            return $this->irExprIsInlinePure($e->operand);
        }
        return false;
    }

    /**
     * If the method's post-peephole body collapsed to a single
     * `return <expr>;`, register it as inlinable. Detection: the body
     * statements (excluding label / prelude) is exactly one statement
     * matching `return <expr>;`. The <expr> must reference only $L[N]
     * locals (i.e. the params), no $stack/$sp/method calls.
     *
     * The inline pass then substitutes `self::<name>(args)` call sites
     * with the parameterised return expression.
     */
    private function detectInlinable(string $name, string $descriptor, array $stmts): void
    {
        // Strip pure prelude/label statements to find the "real" body.
        $realStmts = [];
        foreach ($stmts as $s) {
            // Skip label-only stmts and the standard preludes.
            if (preg_match('/^L_\d+:$/', $s)) continue;
            $realStmts[] = $s;
        }
        if (count($realStmts) !== 1) return;
        if (!preg_match('/^        return (.+);$/', $realStmts[0], $m)) return;
        $expr = trim($m[1]);
        // Disqualify if the body references stack/sp (not stack-erased).
        if (str_contains($expr, '$stack') || str_contains($expr, '$sp')) return;
        // Disqualify if the body has a method call (cross-method dependency).
        if (str_contains($expr, '::') || str_contains($expr, '->')) return;

        [$argTypes, ] = $this->parseMethodDescriptor($descriptor);
        $argc = count($argTypes);
        $params = [];
        for ($i = 0; $i < $argc; $i++) $params[] = "\$__a{$i}";

        $this->inlinableMethods[$this->mangleMethod($name)] = [
            'params' => $params,
            'returnExpr' => $expr,
        ];
    }

    /**
     * Substitute `self::<name>(args)` calls with the inlinable
     * method's return expression, parameter-substituted. Closes the
     * 22× static-call cost the JIT-claims battery surfaced.
     *
     * Args parser: comma-split with paren-depth tracking — handles
     * nested expressions like `self::f(self::g($x))`.
     */
    private function inlinePass(array $stmts): array
    {
        if (empty($this->inlinableMethods)) return $stmts;
        do {
            $changed = false;
            foreach ($stmts as $i => $stmt) {
                // Find `self::<name>(...)` — match each candidate name.
                foreach ($this->inlinableMethods as $name => $info) {
                    $needle = 'self::' . $name . '(';
                    $pos = 0;
                    while (($at = strpos($stmt, $needle, $pos)) !== false) {
                        // Find matching close paren with depth tracking.
                        $start = $at + strlen($needle);
                        $depth = 1;
                        $end = $start;
                        while ($end < strlen($stmt) && $depth > 0) {
                            $c = $stmt[$end];
                            if ($c === '(') $depth++;
                            elseif ($c === ')') $depth--;
                            if ($depth > 0) $end++;
                        }
                        if ($depth !== 0) { $pos = $at + 1; continue; }
                        $argsStr = substr($stmt, $start, $end - $start);
                        $args = $this->splitArgs($argsStr);
                        if (count($args) !== count($info['params'])) {
                            $pos = $at + 1; continue;
                        }
                        // Substitute $L[N] → corresponding arg
                        $expr = $info['returnExpr'];
                        foreach ($info['params'] as $idx => $_) {
                            $expr = preg_replace(
                                '/\$L\[' . $idx . '\]/',
                                '(' . $args[$idx] . ')',
                                $expr
                            );
                        }
                        // Replace `self::<name>(<args>)` with `(<expr>)`.
                        $replacement = '(' . $expr . ')';
                        $stmt = substr($stmt, 0, $at)
                              . $replacement
                              . substr($stmt, $end + 1);
                        $changed = true;
                        $pos = $at + strlen($replacement);
                    }
                }
                $stmts[$i] = $stmt;
            }
        } while ($changed);
        return $stmts;
    }

    /**
     * Inline pass operating on a single concatenated text (the class
     * body). Same logic as inlinePass but works on `string` rather
     * than `string[]` — used after method bodies are joined so
     * inlining can cross statement-array boundaries (and the regex
     * doesn't have to care about per-line splits).
     */
    private function inlineAcrossText(string $text): string
    {
        if (empty($this->inlinableMethods)) return $text;
        do {
            $changed = false;
            foreach ($this->inlinableMethods as $name => $info) {
                $needle = 'self::' . $name . '(';
                $pos = 0;
                while (($at = strpos($text, $needle, $pos)) !== false) {
                    $start = $at + strlen($needle);
                    $depth = 1;
                    $end = $start;
                    while ($end < strlen($text) && $depth > 0) {
                        $c = $text[$end];
                        if ($c === '(') $depth++;
                        elseif ($c === ')') $depth--;
                        if ($depth > 0) $end++;
                    }
                    if ($depth !== 0) { $pos = $at + 1; continue; }
                    $argsStr = substr($text, $start, $end - $start);
                    $args = $this->splitArgs($argsStr);
                    if (count($args) !== count($info['params'])) {
                        $pos = $at + 1; continue;
                    }
                    $expr = $info['returnExpr'];
                    foreach ($info['params'] as $idx => $_) {
                        $expr = preg_replace(
                            '/\$L\[' . $idx . '\]/',
                            '(' . $args[$idx] . ')',
                            $expr
                        );
                    }
                    $replacement = '(' . $expr . ')';
                    $text = substr($text, 0, $at) . $replacement . substr($text, $end + 1);
                    $changed = true;
                    $pos = $at + strlen($replacement);
                }
            }
        } while ($changed);
        return $text;
    }

    /** Comma-split with paren-depth tracking. */
    private function splitArgs(string $s): array
    {
        $s = trim($s);
        if ($s === '') return [];
        $args = [];
        $depth = 0;
        $cur = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $c = $s[$i];
            if ($c === '(' || $c === '[') $depth++;
            elseif ($c === ')' || $c === ']') $depth--;
            if ($c === ',' && $depth === 0) {
                $args[] = trim($cur);
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        if ($cur !== '') $args[] = trim($cur);
        return $args;
    }

    private function compileMethod(string $owner, string $name, string $descriptor, string $bytecode, array $exceptionTables = [], bool $isStatic = true): string
    {
        $bytes = array_values(unpack('C*', $bytecode));
        $end = count($bytes);

        // Exception tables → start_pc → list of {end, handler, type}.
        // Sorted-by-start at insertion. Used during the second pass to
        // open `try` at start_pc and close + emit `catch` at end_pc.
        // Naive: per-range try/catch wraps with NO nesting handling — if
        // ranges nest or overlap, fall back to a leading comment and
        // emit the body without protection. Refine when a fixture
        // surfaces a nested try-catch.
        $exByStart = [];
        $exByEnd = [];
        foreach ($exceptionTables as $et) {
            $startPc = $et->getStartPc();
            $endPc   = $et->getEndPc();
            $handler = $et->getHandlerPc();
            $catchTypeIdx = $et->getCatchType();
            $fqn = '\\Throwable';
            if ($catchTypeIdx !== 0) {
                $cls = $this->constantPool[$catchTypeIdx] ?? null;
                if ($cls instanceof ClassInfo) {
                    $fqn = $this->classFqn($this->utf8At($cls->getClassIndex()));
                }
            }
            $exByStart[$startPc][] = ['end' => $endPc, 'handler' => $handler, 'type' => $fqn];
            $exByEnd[$endPc][]     = ['start' => $startPc, 'handler' => $handler, 'type' => $fqn];
        }
        $hasNesting = false;
        foreach ($exByStart as $entries) if (count($entries) > 1) { $hasNesting = true; break; }
        foreach ($exByEnd as $entries) if (count($entries) > 1) { $hasNesting = true; break; }
        // Make the no-protection fallback visible: silent miss caused
        // real divergence per the 2026-05-04 audit (gap T7). Trigger a
        // user warning once per compile so test runs and production
        // logs surface the case where nested try/catch isn't honoured.
        // Refine when a fixture surfaces actual nested-handler dispatch.
        if ($hasNesting) {
            \trigger_error(
                "PHPJava AOT: {$owner}::{$name}{$descriptor} has nested or "
                . "overlapping exception ranges; emitting without try/catch "
                . "protection. Thrown exceptions in this body will propagate "
                . "uncaught. (audit gap T7 — fix tracked in ROADMAP §Refinement.)",
                \E_USER_WARNING
            );
        }

        // First pass: collect branch targets + the highest local-slot
        // index referenced. Slot count drives the $L pre-init below;
        // pre-allocated $L is ~1ns/op faster than lazily-grown — JIT
        // specialises over the array's sealed shape (rank-1 verified).
        $labels = [0 => 'L_0'];
        $maxSlot = -1;
        $pcCopy = 0;
        while ($pcCopy < $end) {
            $start = $pcCopy;
            $op = $bytes[$pcCopy++];
            $advance = $this->opcodeLength($op);
            if (in_array($op, [0xA2, 0xA7, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F, 0xA0, 0xA1, 0xA3, 0xA4, 0xA5, 0xA6, 0xC6, 0xC7], true)) {
                $hi = $bytes[$pcCopy] ?? 0;
                $lo = $bytes[$pcCopy + 1] ?? 0;
                $rawOffset = ($hi << 8) | $lo;
                if ($rawOffset & 0x8000) $rawOffset -= 0x10000;
                $target = $start + $rawOffset;
                $labels[$target] = "L_{$target}";
            }
            // Track local-slot accesses for $L pre-init sizing.
            // *load_<n>/*store_<n> immediates: 0x1A-0x2D (loads), 0x3B-0x4E (stores)
            // wide-byte forms: 0x15-0x19 (loads), 0x36-0x3A (stores), 0x84 (iinc)
            if ($op >= 0x1A && $op <= 0x2D) {
                $maxSlot = max($maxSlot, ($op - 0x1A) % 4);
            } elseif ($op >= 0x3B && $op <= 0x4E) {
                $maxSlot = max($maxSlot, ($op - 0x3B) % 4);
            } elseif (in_array($op, [0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0x84], true)) {
                $maxSlot = max($maxSlot, $bytes[$pcCopy] ?? 0);
            }
            $pcCopy += $advance;
        }

        // Second pass: emit PHP per opcode.
        $stmts = [];
        $pc = 0;
        while ($pc < $end) {
            $start = $pc;
            $op = $bytes[$pc++];

            // Close any try blocks ending at this PC (before label, before
            // opcode emit — handler dispatch goes through a catch that
            // sits between the try body and the handler's label).
            if (!$hasNesting && isset($exByEnd[$start])) {
                foreach ($exByEnd[$start] as $entry) {
                    $handlerLabel = $labels[$entry['handler']] ?? "L_{$entry['handler']}";
                    if (!isset($labels[$entry['handler']])) $labels[$entry['handler']] = $handlerLabel;
                    // Reset stack to [exception] per JVM exception-handler
                    // contract: when a handler runs, the operand stack is
                    // cleared and the exception is the sole value pushed.
                    $stmts[] = "        } catch ({$entry['type']} \$__e) { \$sp = 0; \$stack[\$sp++] = \$__e; goto {$handlerLabel}; }";
                }
            }
            if (isset($labels[$start])) {
                $stmts[] = "{$labels[$start]}:";
            }
            // Open try blocks starting at this PC. Multi-catch on the same
            // range collapses to multiple entries at the same start_pc;
            // we emit one try per entry — only handled cleanly when
            // there's no overlap (hasNesting=false).
            if (!$hasNesting && isset($exByStart[$start])) {
                foreach ($exByStart[$start] as $entry) {
                    $stmts[] = "        try {";
                }
            }

            switch ($op) {
                case 0x00: break; // nop
                case 0x01: $stmts[] = "        \$stack[\$sp++] = null;"; break; // aconst_null
                case 0x02: $stmts[] = "        \$stack[\$sp++] = -1;"; break;   // iconst_m1
                case 0x03: $stmts[] = "        \$stack[\$sp++] = 0;"; break;    // iconst_0
                case 0x04: $stmts[] = "        \$stack[\$sp++] = 1;"; break;    // iconst_1
                case 0x05: $stmts[] = "        \$stack[\$sp++] = 2;"; break;    // iconst_2
                case 0x06: $stmts[] = "        \$stack[\$sp++] = 3;"; break;    // iconst_3
                case 0x07: $stmts[] = "        \$stack[\$sp++] = 4;"; break;    // iconst_4
                case 0x08: $stmts[] = "        \$stack[\$sp++] = 5;"; break;    // iconst_5
                case 0x09: $stmts[] = "        \$stack[\$sp++] = 0;"; break;    // lconst_0
                case 0x0A: $stmts[] = "        \$stack[\$sp++] = 1;"; break;    // lconst_1
                case 0x0B: $stmts[] = "        \$stack[\$sp++] = 0.0;"; break;  // fconst_0
                case 0x0C: $stmts[] = "        \$stack[\$sp++] = 1.0;"; break;  // fconst_1
                case 0x0D: $stmts[] = "        \$stack[\$sp++] = 2.0;"; break;  // fconst_2
                case 0x0E: $stmts[] = "        \$stack[\$sp++] = 0.0;"; break;  // dconst_0
                case 0x0F: $stmts[] = "        \$stack[\$sp++] = 1.0;"; break;  // dconst_1
                case 0x10: // bipush
                    $val = $this->readSignedByte($bytes, $pc); $pc += 1;
                    $stmts[] = "        \$stack[\$sp++] = {$val};";
                    break;
                case 0x11: // sipush
                    $val = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $stmts[] = "        \$stack[\$sp++] = {$val};";
                    break;
                // ── ldc / ldc_w / ldc2_w ──────────────────────────────
                case 0x12: // ldc
                    $idx = $bytes[$pc++];
                    $stmts[] = "        \$stack[\$sp++] = " . $this->ldcLiteral($idx) . ";";
                    break;
                case 0x13: // ldc_w
                case 0x14: // ldc2_w
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $stmts[] = "        \$stack[\$sp++] = " . $this->ldcLiteral($idx) . ";";
                    break;
                // ── load (all types collapse — primitives by value, refs
                // by value too because Java reference types map to PHP
                // objects which are by-reference natively) ───────────
                case 0x15: case 0x16: case 0x17: case 0x18: case 0x19:
                    $idx = $bytes[$pc++];
                    $stmts[] = "        \$stack[\$sp++] = \$L[{$idx}];";
                    break;
                case 0x1A: case 0x1E: case 0x22: case 0x26: case 0x2A: // *load_0
                    $stmts[] = "        \$stack[\$sp++] = \$L[0];"; break;
                case 0x1B: case 0x1F: case 0x23: case 0x27: case 0x2B: // *load_1
                    $stmts[] = "        \$stack[\$sp++] = \$L[1];"; break;
                case 0x1C: case 0x20: case 0x24: case 0x28: case 0x2C: // *load_2
                    $stmts[] = "        \$stack[\$sp++] = \$L[2];"; break;
                case 0x1D: case 0x21: case 0x25: case 0x29: case 0x2D: // *load_3
                    $stmts[] = "        \$stack[\$sp++] = \$L[3];"; break;
                // ── store (wide-index byte form) ──────────────────────
                case 0x36: case 0x37: case 0x38: case 0x39: case 0x3A:
                    $idx = $bytes[$pc++];
                    $stmts[] = "        \$L[{$idx}] = \$stack[--\$sp];";
                    break;
                // ── store_<n> immediates ──────────────────────────────
                case 0x3B: case 0x3F: case 0x43: case 0x47: case 0x4B: // *store_0
                    $stmts[] = "        \$L[0] = \$stack[--\$sp];"; break;
                case 0x3C: case 0x40: case 0x44: case 0x48: case 0x4C: // *store_1
                    $stmts[] = "        \$L[1] = \$stack[--\$sp];"; break;
                case 0x3D: case 0x41: case 0x45: case 0x49: case 0x4D: // *store_2
                    $stmts[] = "        \$L[2] = \$stack[--\$sp];"; break;
                case 0x3E: case 0x42: case 0x46: case 0x4A: case 0x4E: // *store_3
                    $stmts[] = "        \$L[3] = \$stack[--\$sp];"; break;
                // ── stack ops ─────────────────────────────────────────
                case 0x57: $stmts[] = "        --\$sp;"; break;          // pop
                case 0x58: $stmts[] = "        \$sp -= 2;"; break;       // pop2
                case 0x59: $stmts[] = "        \$stack[\$sp] = \$stack[\$sp - 1]; \$sp++;"; break; // dup
                case 0x5F: $stmts[] = "        \$__t = \$stack[\$sp - 1]; \$stack[\$sp - 1] = \$stack[\$sp - 2]; \$stack[\$sp - 2] = \$__t;"; break; // swap
                // ── arithmetic ────────────────────────────────────────
                // Emit shape: `$b = $stack[--$sp]; $stack[$sp - 1] OP= $b`.
                // Tried temp-free emit (`$stack[$sp - 2] OP= $stack[$sp - 1];
                // --$sp;`) — measured ~15% regression on iadd-1k JIT
                // (0.81 → 0.94 ns/op median). PHP's tracing JIT specialises
                // better over the `$b` temporary than over two indexed
                // reads on the same array — the temp is a known-type
                // scalar, the second indexed read defeats specialisation.
                // Counter-intuitive result, rank-1 verified 2026-05-02.
                case 0x60: case 0x61: case 0x62: case 0x63: // *add
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] += \$b;"; break;
                case 0x64: case 0x65: case 0x66: case 0x67: // *sub
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] -= \$b;"; break;
                case 0x68: case 0x69: case 0x6A: case 0x6B: // *mul
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] *= \$b;"; break;
                case 0x6C: case 0x6D: // idiv, ldiv (truncate toward zero per JVM)
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] = \\intdiv(\$stack[\$sp - 1], \$b);"; break;
                case 0x6E: case 0x6F: // fdiv, ddiv
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] /= \$b;"; break;
                case 0x70: case 0x71: // irem, lrem
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] %= \$b;"; break;
                case 0x72: case 0x73: // frem, drem
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] = \\fmod(\$stack[\$sp - 1], \$b);"; break;
                case 0x74: case 0x75: case 0x76: case 0x77: // *neg
                    $stmts[] = "        \$stack[\$sp - 1] = -\$stack[\$sp - 1];"; break;
                case 0x78: case 0x79: // ishl, lshl (mask amount per JVM)
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] <<= (\$b & 0x1F);"; break;
                case 0x7A: case 0x7B: // ishr, lshr
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] >>= (\$b & 0x1F);"; break;
                case 0x7C: case 0x7D: // iushr, lushr (logical right shift)
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] = (\$stack[\$sp - 1] >> (\$b & 0x1F)) & ((1 << (32 - (\$b & 0x1F))) - 1);"; break;
                case 0x7E: case 0x7F: // iand, land
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] &= \$b;"; break;
                case 0x80: case 0x81: // ior, lor
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] |= \$b;"; break;
                case 0x82: case 0x83: // ixor, lxor
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] ^= \$b;"; break;
                case 0x84: // iinc index const
                    $idx = $bytes[$pc++];
                    $delta = $this->readSignedByte($bytes, $pc); $pc += 1;
                    $stmts[] = "        \$L[{$idx}] += {$delta};";
                    break;
                // ── type conversion ───────────────────────────────────
                case 0x85: case 0x86: case 0x87: case 0x88: // i2l, i2f, i2d, l2i
                    $stmts[] = "        \$stack[\$sp - 1] = (int)\$stack[\$sp - 1];"; break;
                case 0x89: case 0x8A: // l2f, l2d
                    $stmts[] = "        \$stack[\$sp - 1] = (float)\$stack[\$sp - 1];"; break;
                case 0x8B: case 0x8E: // f2i, d2i
                case 0x8C: case 0x8F: // f2l, d2l
                    $stmts[] = "        \$stack[\$sp - 1] = (int)\$stack[\$sp - 1];"; break;
                case 0x8D: case 0x90: break; // f2d, d2f — no-op (PHP float == double)
                case 0x91: // i2b
                    $stmts[] = "        \$stack[\$sp - 1] = (\$stack[\$sp - 1] & 0xFF); if (\$stack[\$sp - 1] & 0x80) \$stack[\$sp - 1] -= 0x100;"; break;
                case 0x92: // i2c
                    $stmts[] = "        \$stack[\$sp - 1] &= 0xFFFF;"; break;
                case 0x93: // i2s
                    $stmts[] = "        \$stack[\$sp - 1] = (\$stack[\$sp - 1] & 0xFFFF); if (\$stack[\$sp - 1] & 0x8000) \$stack[\$sp - 1] -= 0x10000;"; break;
                // ── comparisons ───────────────────────────────────────
                case 0x94: case 0x95: case 0x96: case 0x97: case 0x98: // lcmp, fcmpl/g, dcmpl/g
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] = \$stack[\$sp - 1] <=> \$b;"; break;
                // ── single-operand if (vs 0) ──────────────────────────
                case 0x99: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '=== 0'); $pc += 2; break;
                case 0x9A: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '!== 0'); $pc += 2; break;
                case 0x9B: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '< 0');   $pc += 2; break;
                case 0x9C: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '>= 0');  $pc += 2; break;
                case 0x9D: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '> 0');   $pc += 2; break;
                case 0x9E: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '<= 0');  $pc += 2; break;
                case 0xC6: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '=== null'); $pc += 2; break;
                case 0xC7: $stmts[] = $this->ifBranch1($bytes, $pc, $start, $labels, '!== null'); $pc += 2; break;
                // ── two-operand if ────────────────────────────────────
                case 0x9F: case 0xA5: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '==='); $pc += 2; break;
                case 0xA0: case 0xA6: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '!=='); $pc += 2; break;
                case 0xA1: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '<');   $pc += 2; break;
                case 0xA2: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '>=');  $pc += 2; break;
                case 0xA3: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '>');   $pc += 2; break;
                case 0xA4: $stmts[] = $this->ifBranch2($bytes, $pc, $start, $labels, '<=');  $pc += 2; break;
                case 0xA7: // goto
                    $offset = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $target = $start + $offset;
                    $stmts[] = "        goto {$labels[$target]};";
                    break;
                // ── returns ───────────────────────────────────────────
                case 0xAC: case 0xAD: case 0xAE: case 0xAF: case 0xB0: // *return (value)
                    $stmts[] = "        return \$stack[--\$sp];"; break;
                case 0xB1: // return (void)
                    $stmts[] = "        return;"; break;
                case 0xBF: // athrow
                    $stmts[] = "        throw \$stack[--\$sp];"; break;
                // ── field access ──────────────────────────────────────
                case 0xB2: // getstatic
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    [$cls, $field, ] = $this->resolveFieldRef($idx);
                    $fqn = $this->classFqn($cls);
                    $stmts[] = "        \$stack[\$sp++] = {$fqn}::\${$field};";
                    break;
                case 0xB3: // putstatic
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    [$cls, $field, ] = $this->resolveFieldRef($idx);
                    $fqn = $this->classFqn($cls);
                    $stmts[] = "        {$fqn}::\${$field} = \$stack[--\$sp];";
                    break;
                case 0xB4: // getfield
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    [, $field, ] = $this->resolveFieldRef($idx);
                    $stmts[] = "        \$__obj = \$stack[--\$sp]; \$stack[\$sp++] = \$__obj->{$field};";
                    break;
                case 0xB5: // putfield
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    [, $field, ] = $this->resolveFieldRef($idx);
                    $stmts[] = "        \$__v = \$stack[--\$sp]; \$__obj = \$stack[--\$sp]; \$__obj->{$field} = \$__v;";
                    break;
                // ── method dispatch ───────────────────────────────────
                case 0xB6: case 0xB9: // invokevirtual, invokeinterface
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    if ($op === 0xB9) $pc += 2; // count + reserved
                    $stmts[] = $this->emitInvokeInstance($idx);
                    break;
                case 0xB7: // invokespecial
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $stmts[] = $this->emitInvokeInstance($idx);
                    break;
                case 0xB8: // invokestatic
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $stmts[] = $this->emitInvokeStatic($idx);
                    break;
                case 0xBA: // invokedynamic
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $pc += 2; // 2 reserved zero bytes
                    $stmts[] = $this->emitInvokeDynamic($idx);
                    break;
                case 0xBB: // new
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $cls = $this->constantPool[$idx] ?? null;
                    $newClsFqn = ($cls instanceof ClassInfo)
                        ? $this->classFqn($this->utf8At($cls->getClassIndex()))
                        : '\\stdClass';
                    $stmts[] = "        \$stack[\$sp++] = (new \\ReflectionClass({$newClsFqn}::class))->newInstanceWithoutConstructor();";
                    break;
                // ── arrays ────────────────────────────────────────────
                // Java arrays wrap as `(object){'v' => phpArray}`. Two
                // reasons:
                //   1. PHP arrays copy-on-write on the operand stack —
                //      writes through a stack copy don't propagate to the
                //      source local. References (`=&`) leak across slot
                //      reuse.
                //   2. PHP objects are by-reference natively — a value
                //      push of an object propagates writes correctly with
                //      no stack-slot bookkeeping.
                // Cost: one property hop (`->v`) per access. PHP JIT
                // handles this cheaply.
                // The 8 typed *aload / *astore variants collapse to one
                // emit since PHP doesn't distinguish int[] from byte[].
                case 0xBC: // newarray (atype byte, length on stack)
                    $pc += 1;
                    $stmts[] = "        \$__sz = \$stack[--\$sp]; \$stack[\$sp++] = (object)['v' => \\array_fill(0, \$__sz, 0)];";
                    break;
                case 0xBD: // anewarray (CP class idx, length on stack)
                    $pc += 2;
                    $stmts[] = "        \$__sz = \$stack[--\$sp]; \$stack[\$sp++] = (object)['v' => \\array_fill(0, \$__sz, null)];";
                    break;
                case 0xBE: // arraylength
                    $stmts[] = "        \$stack[\$sp - 1] = \\count(\$stack[\$sp - 1]->v);";
                    break;
                case 0x2E: case 0x2F: case 0x30: case 0x31: // *aload
                case 0x32: case 0x33: case 0x34: case 0x35:
                    $stmts[] = "        \$__i = \$stack[--\$sp]; \$stack[\$sp - 1] = \$stack[\$sp - 1]->v[\$__i];";
                    break;
                case 0x4F: case 0x50: case 0x51: case 0x52: // *astore
                case 0x53: case 0x54: case 0x55: case 0x56:
                    $stmts[] = "        \$__v = \$stack[--\$sp]; \$__i = \$stack[--\$sp]; \$stack[--\$sp]->v[\$__i] = \$__v;";
                    break;
                case 0xC5: // multianewarray (CP class idx 2 bytes, dimensions 1 byte)
                    $pc += 2; // class index
                    $dims = $bytes[$pc++];
                    $popLines = [];
                    $dimRefs = [];
                    for ($i = $dims - 1; $i >= 0; $i--) {
                        $popLines[] = "\$__d{$i} = \$stack[--\$sp];";
                        $dimRefs[] = "\$__d{$i}";
                    }
                    // Build nested array via a recursive helper inline.
                    // Naive but correct; real impl can inline if dims small.
                    $stmts[] = "        " . implode(' ', array_reverse($popLines))
                        . " \$__build = function(\$dims) use (&\$__build) { if (empty(\$dims)) return null; \$d = array_shift(\$dims); return array_fill(0, \$d, \$dims ? \$__build(\$dims) : null); };"
                        . " \$stack[\$sp++] = \$__build([" . implode(', ', $dimRefs) . "]);";
                    break;
                // ── type checks ───────────────────────────────────────
                // checkcast: throw ClassCastException on type mismatch; pass
                // through on match. instanceof: replace top with bool.
                // Both consume the 2-byte CP class index. We resolve the
                // target class via classFqn() — when the PHP class doesn't
                // exist (heuristic mismatch), instanceof returns false and
                // checkcast throws TypeError, both observable as expected.
                case 0xC0: // checkcast
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $cls = $this->constantPool[$idx] ?? null;
                    $fqn = ($cls instanceof ClassInfo)
                        ? $this->classFqn($this->utf8At($cls->getClassIndex()))
                        : '\\stdClass';
                    $stmts[] = "        if (\$stack[\$sp - 1] !== null && !(\$stack[\$sp - 1] instanceof {$fqn})) throw new \\TypeError('checkcast failed for ' . " . var_export($fqn, true) . ");";
                    break;
                case 0xC1: // instanceof
                    $idx = $this->readUnsignedShort($bytes, $pc); $pc += 2;
                    $cls = $this->constantPool[$idx] ?? null;
                    $fqn = ($cls instanceof ClassInfo)
                        ? $this->classFqn($this->utf8At($cls->getClassIndex()))
                        : '\\stdClass';
                    $stmts[] = "        \$stack[\$sp - 1] = (\$stack[\$sp - 1] instanceof {$fqn}) ? 1 : 0;";
                    break;
                // ── synchronisation ───────────────────────────────────
                // monitorenter / monitorexit. PHP per-worker is single-
                // threaded; concurrency hooks come at the Swoole/Fiber
                // tier. Treat as no-op — pop the monitor reference. When
                // multi-process mode lands, swap this for a real lock.
                case 0xC2: // monitorenter
                case 0xC3: // monitorexit
                    $stmts[] = "        --\$sp; // monitor: no-op (single-threaded worker)";
                    break;
                // ── switch tables ─────────────────────────────────────
                // tableswitch: padded to 4-byte boundary, then default
                // (4 bytes), low (4), high (4), then (high-low+1) jump
                // offsets each 4 bytes.
                // lookupswitch: padded, default (4), npairs (4), then
                // npairs (key, offset) pairs each 8 bytes.
                case 0xAA: // tableswitch
                    $padBytes = (4 - ($pc & 3)) & 3;
                    $pc += $padBytes;
                    $defaultOff = $this->readSigned32($bytes, $pc); $pc += 4;
                    $low  = $this->readSigned32($bytes, $pc); $pc += 4;
                    $high = $this->readSigned32($bytes, $pc); $pc += 4;
                    $cases = [];
                    for ($k = $low; $k <= $high; $k++) {
                        $off = $this->readSigned32($bytes, $pc); $pc += 4;
                        $cases[$k] = $start + $off;
                    }
                    $defaultTarget = $start + $defaultOff;
                    $caseStr = '';
                    foreach ($cases as $k => $tgt) {
                        $tgtLabel = $labels[$tgt] ?? null;
                        if ($tgtLabel === null) { $labels[$tgt] = "L_{$tgt}"; $tgtLabel = $labels[$tgt]; }
                        $caseStr .= "{$k} => '{$tgtLabel}', ";
                    }
                    $defaultLabel = $labels[$defaultTarget] ?? null;
                    if ($defaultLabel === null) { $labels[$defaultTarget] = "L_{$defaultTarget}"; $defaultLabel = $labels[$defaultTarget]; }
                    $stmts[] = "        \$__sw = \$stack[--\$sp]; \$__t = [{$caseStr}][\$__sw] ?? '{$defaultLabel}'; goto \$__t;";
                    // PHP's `goto` doesn't accept variable targets; replace
                    // with a switch dispatch. The above emit is a placeholder —
                    // re-emit as a switch statement.
                    array_pop($stmts);
                    $caseLines = [];
                    foreach ($cases as $k => $tgt) {
                        $caseLines[] = "        case {$k}: goto {$labels[$tgt]};";
                    }
                    $caseLines[] = "        default: goto {$labels[$defaultTarget]};";
                    $stmts[] = "        switch (\$stack[--\$sp]) {\n" . implode("\n", $caseLines) . "\n        }";
                    break;
                case 0xAB: // lookupswitch
                    $padBytes = (4 - ($pc & 3)) & 3;
                    $pc += $padBytes;
                    $defaultOff = $this->readSigned32($bytes, $pc); $pc += 4;
                    $npairs     = $this->readSigned32($bytes, $pc); $pc += 4;
                    $cases = [];
                    for ($k = 0; $k < $npairs; $k++) {
                        $key = $this->readSigned32($bytes, $pc); $pc += 4;
                        $off = $this->readSigned32($bytes, $pc); $pc += 4;
                        $cases[$key] = $start + $off;
                        if (!isset($labels[$start + $off])) $labels[$start + $off] = 'L_' . ($start + $off);
                    }
                    $defaultTarget = $start + $defaultOff;
                    if (!isset($labels[$defaultTarget])) $labels[$defaultTarget] = 'L_' . $defaultTarget;
                    $caseLines = [];
                    foreach ($cases as $k => $tgt) {
                        $caseLines[] = "        case {$k}: goto {$labels[$tgt]};";
                    }
                    $caseLines[] = "        default: goto {$labels[$defaultTarget]};";
                    $stmts[] = "        switch (\$stack[--\$sp]) {\n" . implode("\n", $caseLines) . "\n        }";
                    break;
                // ── wide prefix ───────────────────────────────────────
                // 0xC4 wide modifies the next opcode to use a 2-byte
                // operand index instead of 1. Affects iload/lload/fload/
                // dload/aload/istore/.../astore/iinc/ret. Re-dispatch with
                // a flag would clutter the switch — emit a runtime check
                // for now, refine if a fixture surfaces.
                case 0xC4: // wide
                    $stmts[] = "        // WIDE PREFIX (0xC4) — not yet handled at AOT level";
                    // Skip the wide-modified opcode body; conservative skip.
                    $next = $bytes[$pc++] ?? 0;
                    $pc += ($next === 0x84) ? 4 : 2;
                    break;
                default:
                    $stmts[] = "        // UNIMPLEMENTED OPCODE 0x" . strtoupper(dechex($op));
                    $advance = $this->opcodeLength($op);
                    $pc += $advance;
                    break;
            }
        }

        // ── method signature + prelude ────────────────────────────────
        // Fixed-arity for JIT specialisation. Param count derived from
        // descriptor parse. JVM slot 0 = first arg (instance methods get
        // `this` at slot 0; static methods start at arg 0). Long/double
        // take 2 JVM slots but 1 PHP value — refine when a long-arg
        // fixture surfaces.
        // ── stack-erasure peephole pass ──────────────────────────────
        // Recognise common operand-stack patterns where pure loads /
        // consts feed directly into a consumer, and rewrite them as
        // direct PHP expressions. Reduces ~9 stmts/loop-iter to ~4
        // for BenchAdd-style code (per spike measurement).
        //
        // Pure-source restriction: only collapse pushes whose RHS is
        // a `$L[N]` load or numeric/null/string literal — anything
        // else (method calls, getstatic, getfield) may have side
        // effects whose ordering must be preserved.
        //
        // Earlier (2026-05-02) tried a single peephole on iload+ireturn
        // alone and saw cold-start regression; the JIT trace shape
        // change confused the planner. Hypothesis (rank 5): a single
        // isolated peephole leaves the trace partly the old shape; a
        // FULL erasure pass that touches every reducible site keeps
        // the trace consistent. Bench will tell.
        $stmts = $this->peepholeErase($stmts);

        // Register this method as inlinable if its body collapsed to a
        // single `return <expr>;` statement. The inline pass at the
        // end of compileFromGenericClass will substitute `self::<name>
        // (args)` calls with the parameterised expression, addressing
        // the 22× static-call cost the JIT-claims battery surfaced.
        $this->detectInlinable($name, $descriptor, $stmts);

        [$argTypes, ] = $this->parseMethodDescriptor($descriptor);
        $argc = count($argTypes);
        $params = [];
        for ($i = 0; $i < $argc; $i++) $params[] = "\$__a{$i}";
        $paramStr = implode(', ', $params);
        $signature = $isStatic
            ? "public static function {$this->mangleMethod($name)}({$paramStr})"
            : "public function {$this->mangleMethod($name)}({$paramStr})";

        // $L pre-init: sealed-shape array literal. For static methods,
        // slots [0..argc-1] hold params; for instance methods, slot 0
        // is `$this` and slots [1..argc] hold the explicit args. The
        // rest are zero-filled up to the max slot the bytecode touches.
        // JIT trace is happier with a fixed-shape array than one that
        // grows on first istore. Long and double args take 2 JVM slots
        // but 1 PHP value — refine when a long-arg fixture surfaces a
        // slot-count mismatch.
        $thisSlot = $isStatic ? 0 : 1;
        $maxLocals = max($argc + $thisSlot, $maxSlot + 1);
        $initVals = [];
        for ($i = 0; $i < $maxLocals; $i++) {
            if (!$isStatic && $i === 0) {
                $initVals[] = '$this';
            } elseif ($i - $thisSlot >= 0 && $i - $thisSlot < $argc) {
                $initVals[] = "\$__a" . ($i - $thisSlot);
            } else {
                $initVals[] = '0';
            }
        }
        $localList = $maxLocals > 0 ? '[' . implode(', ', $initVals) . ']' : '[]';
        $prelude = "\$L = {$localList};\n        \$stack = []; \$sp = 0;";
        $body = $prelude . "\n" . implode("\n", $stmts);
        return "    // {$name} {$descriptor}\n    {$signature}\n    {\n{$body}\n    }";
    }

    // ─── helpers ──────────────────────────────────────────────────────

    /** Emit a single-operand if-branch (compares stack top against 0/null). */
    private function ifBranch1(array $bytes, int $pc, int $start, array $labels, string $cmp): string
    {
        $offset = $this->readSignedShort($bytes, $pc);
        $target = $start + $offset;
        return "        if (\$stack[--\$sp] {$cmp}) goto {$labels[$target]};";
    }

    /** Emit a two-operand if-branch (compares top two stack entries). */
    private function ifBranch2(array $bytes, int $pc, int $start, array $labels, string $cmp): string
    {
        $offset = $this->readSignedShort($bytes, $pc);
        $target = $start + $offset;
        return "        \$b = \$stack[--\$sp]; \$a = \$stack[--\$sp]; if (\$a {$cmp} \$b) goto {$labels[$target]};";
    }

    private function utf8At(int $idx)
    {
        $entry = $this->constantPool[$idx] ?? null;
        if ($entry instanceof Utf8Info) return $entry->getString();
        return '?';
    }

    private function resolveMethodRef(int $idx): array
    {
        $ref = $this->constantPool[$idx] ?? null;
        if (!($ref instanceof MethodrefInfo) && !($ref instanceof InterfaceMethodrefInfo)) {
            return ['?', '?', '?'];
        }
        $classInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
        $nat       = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        if (!($classInfo instanceof ClassInfo) || !($nat instanceof NameAndTypeInfo)) {
            return ['?', '?', '?'];
        }
        return [
            $this->utf8At($classInfo->getClassIndex()),
            $this->utf8At($nat->getNameIndex()),
            $this->utf8At($nat->getDescriptorIndex()),
        ];
    }

    private function resolveFieldRef(int $idx): array
    {
        $ref = $this->constantPool[$idx] ?? null;
        if (!($ref instanceof FieldrefInfo)) return ['?', '?', '?'];
        $classInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
        $nat       = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        if (!($classInfo instanceof ClassInfo) || !($nat instanceof NameAndTypeInfo)) {
            return ['?', '?', '?'];
        }
        return [
            $this->utf8At($classInfo->getClassIndex()),
            $this->utf8At($nat->getNameIndex()),
            $this->utf8At($nat->getDescriptorIndex()),
        ];
    }

    /**
     * Map a JVM binary class name to the PHP FQN at emit time.
     *
     * Per CONTRACTS.md §5 the runtime classloader picks invoker strategy
     * per class. Naive AOT commits at emit time by naming convention:
     *
     *   - JDK packages (java/, javax/, jdk/, sun/, com/sun/) → AOT-clean
     *     shim under \PHPJava\Aot\Runtime\<dotted> (separate from the
     *     interpreter's \PHPJava\Packages\* tree, which carries the
     *     ambiguous-arity dispatch quirks PATTERNS.md schedules for
     *     subtraction in Week 2).
     *   - User packages → \PHPJava\Aot\Generated\<mangled>
     *   - Same-class self-references → `self::` (no FQN, no autoload hop).
     */
    private function classFqn(string $binaryName): string
    {
        if ($binaryName === $this->currentClassBin) return 'self';
        $isJdk = (
            str_starts_with($binaryName, 'java/') ||
            str_starts_with($binaryName, 'javax/') ||
            str_starts_with($binaryName, 'jdk/') ||
            str_starts_with($binaryName, 'sun/') ||
            str_starts_with($binaryName, 'com/sun/')
        );
        $php = str_replace('/', '\\', $binaryName);
        return $isJdk
            ? '\\PHPJava\\Aot\\Runtime\\' . $php
            : '\\PHPJava\\Aot\\Generated\\' . str_replace('\\', '_', $php);
    }

    /** Parse `(args)Ret` → [argTypes, returnType]. JVMS §4.3.3. */
    private function parseMethodDescriptor(string $desc): array
    {
        if (!str_starts_with($desc, '(')) return [[], 'V'];
        $end = strpos($desc, ')');
        if ($end === false) return [[], 'V'];
        $args = $this->parseTypeList(substr($desc, 1, $end - 1));
        $ret  = substr($desc, $end + 1);
        return [$args, $ret];
    }

    private function parseTypeList(string $s): array
    {
        $out = [];
        $i = 0; $n = strlen($s);
        while ($i < $n) {
            $start = $i;
            while ($i < $n && $s[$i] === '[') $i++;
            if ($i >= $n) break;
            $c = $s[$i];
            if ($c === 'L') {
                $semi = strpos($s, ';', $i);
                if ($semi === false) break;
                $i = $semi + 1;
            } else {
                $i++;
            }
            $out[] = substr($s, $start, $i - $start);
        }
        return $out;
    }

    /** A pre-resolved CP entry → PHP literal. Variant of ldcLiteral
     *  that takes the entry directly (used by indy bootstrap-arg
     *  resolution where the entries are already typed). */
    private function cpEntryToLiteral($entry): string
    {
        if ($entry instanceof StringInfo) return var_export($this->utf8At($entry->getStringIndex()), true);
        if ($entry instanceof IntegerInfo) return (string)$entry->getBytes();
        if ($entry instanceof FloatInfo)   return is_finite($entry->getBytes()) ? (string)$entry->getBytes() : 'NAN';
        if ($entry instanceof LongInfo)    return (string)$entry->getBytes();
        if ($entry instanceof DoubleInfo)  return is_finite($entry->getBytes()) ? (string)$entry->getBytes() : 'NAN';
        return 'null';
    }

    /** Constant-pool entry → PHP literal expression. */
    private function ldcLiteral(int $idx): string
    {
        $entry = $this->constantPool[$idx] ?? null;
        if ($entry instanceof StringInfo) {
            return var_export($this->utf8At($entry->getStringIndex()), true);
        }
        if ($entry instanceof IntegerInfo) return (string)$entry->getBytes();
        if ($entry instanceof FloatInfo) {
            $v = $entry->getBytes();
            return is_finite($v) ? (string)$v : 'NAN';
        }
        if ($entry instanceof LongInfo) return (string)$entry->getBytes();
        if ($entry instanceof DoubleInfo) {
            $v = $entry->getBytes();
            return is_finite($v) ? (string)$v : 'NAN';
        }
        if ($entry instanceof ClassInfo) {
            return "'class:" . $this->utf8At($entry->getClassIndex()) . "'";
        }
        return 'null';
    }

    /**
     * INVOKESTATIC: pop N args, call \Class::method($a1, …). Args popped
     * into named temps in reverse so the call applies them LTR.
     */
    private function emitInvokeStatic(int $idx): string
    {
        [$cls, $method, $desc] = $this->resolveMethodRef($idx);
        [$argTypes, $ret] = $this->parseMethodDescriptor($desc);
        $argc = count($argTypes);
        $fqn = $this->classFqn($cls);
        $phpMethod = $this->mangleMethod($method);

        $popLines = [];
        $argRefs  = [];
        for ($i = $argc - 1; $i >= 0; $i--) {
            $popLines[] = "\$__a{$i} = \$stack[--\$sp];";
            $argRefs[$i] = "\$__a{$i}";
        }
        ksort($argRefs);
        $popPart = implode(' ', $popLines);
        $callExpr = "{$fqn}::{$phpMethod}(" . implode(', ', $argRefs) . ")";
        $line = $popPart === '' ? '' : ($popPart . ' ');

        return $ret === 'V'
            ? "        {$line}{$callExpr};"
            : "        {$line}\$stack[\$sp++] = {$callExpr};";
    }

    /**
     * Instance-method dispatch: pop N args + receiver, call $obj->method(...).
     * Mangle JVM-reserved names (`<init>` → `__construct`) so the emit is
     * PHP-syntactically valid; constructor calls on already-allocated
     * receivers (the `new + dup + invokespecial<init>` pattern) work via
     * direct `__construct(...)` invocation on the existing instance.
     */
    private function emitInvokeInstance(int $idx): string
    {
        [, $method, $desc] = $this->resolveMethodRef($idx);
        [$argTypes, $ret] = $this->parseMethodDescriptor($desc);
        $argc = count($argTypes);
        $phpMethod = $this->mangleMethod($method);

        $popLines = [];
        $argRefs  = [];
        for ($i = $argc - 1; $i >= 0; $i--) {
            $popLines[] = "\$__a{$i} = \$stack[--\$sp];";
            $argRefs[$i] = "\$__a{$i}";
        }
        ksort($argRefs);
        $popLines[] = "\$__obj = \$stack[--\$sp];";
        $popPart = implode(' ', $popLines);
        $callExpr = "\$__obj->{$phpMethod}(" . implode(', ', $argRefs) . ")";
        return $ret === 'V'
            ? "        {$popPart} {$callExpr};"
            : "        {$popPart} \$stack[\$sp++] = {$callExpr};";
    }

    /**
     * Stack-erasure peephole — rewrite operand-stack patterns to
     * direct PHP expressions where the popped value's source is pure
     * (load or constant). Iterated to fixpoint so cascaded patterns
     * fully reduce.
     *
     * "Pure" RHS = matches `$L[N]` or simple literal (int/float/
     * string/null). Anything else (function call, property access)
     * may have side effects whose order must be preserved.
     */
    private function peepholeErase(array $stmts): array
    {
        // Pattern: a "pure push" — `        $stack[$sp++] = <pure>;`
        // Captures the pure expression in group 1.
        $pushRe = '/^        \$stack\[\$sp\+\+\] = (\$L\[\d+\]|-?\d+(?:\.\d+)?|null|\'[^\']*\'|"[^"]*");$/';

        do {
            $changed = false;
            $n = count($stmts);
            $out = [];
            for ($i = 0; $i < $n; ) {

                // Pattern P1: push + ireturn → direct return
                //   $stack[$sp++] = X;  return $stack[--$sp];
                if ($i + 1 < $n
                    && preg_match($pushRe, $stmts[$i], $m)
                    && $stmts[$i + 1] === '        return $stack[--$sp];') {
                    $out[] = "        return {$m[1]};";
                    $i += 2; $changed = true; continue;
                }

                // P2: push + istore (slot 0..3 immediate or wide-byte)
                //   $stack[$sp++] = X;  $L[N] = $stack[--$sp];
                if ($i + 1 < $n
                    && preg_match($pushRe, $stmts[$i], $m)
                    && preg_match('/^        \$L\[(\d+)\] = \$stack\[--\$sp\];$/', $stmts[$i + 1], $m2)) {
                    $out[] = "        \$L[{$m2[1]}] = {$m[1]};";
                    $i += 2; $changed = true; continue;
                }

                // P3: push + push + iadd-style + istore
                //   $stack[$sp++] = X;  $stack[$sp++] = Y;
                //   $b = $stack[--$sp]; $stack[$sp - 1] OP= $b;
                //   $L[N] = $stack[--$sp];
                // → $L[N] = X OP Y;
                if ($i + 3 < $n
                    && preg_match($pushRe, $stmts[$i], $mx)
                    && preg_match($pushRe, $stmts[$i + 1], $my)
                    && preg_match('/^        \$b = \$stack\[--\$sp\]; \$stack\[\$sp - 1\] (\+|-|\*|\/|%|<<|>>|&|\||\^)= \$b;$/', $stmts[$i + 2], $mop)
                    && preg_match('/^        \$L\[(\d+)\] = \$stack\[--\$sp\];$/', $stmts[$i + 3], $mst)) {
                    $op = $mop[1];
                    $out[] = "        \$L[{$mst[1]}] = {$mx[1]} {$op} {$my[1]};";
                    $i += 4; $changed = true; continue;
                }

                // P6: push + push + iadd-style + ireturn
                //   $stack[$sp++] = X;  $stack[$sp++] = Y;
                //   $b = $stack[--$sp]; $stack[$sp - 1] OP= $b;
                //   return $stack[--$sp];
                // → return X OP Y;
                if ($i + 3 < $n
                    && preg_match($pushRe, $stmts[$i], $mx)
                    && preg_match($pushRe, $stmts[$i + 1], $my)
                    && preg_match('/^        \$b = \$stack\[--\$sp\]; \$stack\[\$sp - 1\] (\+|-|\*|\/|%|<<|>>|&|\||\^)= \$b;$/', $stmts[$i + 2], $mop)
                    && $stmts[$i + 3] === '        return $stack[--$sp];') {
                    $op = $mop[1];
                    $out[] = "        return {$mx[1]} {$op} {$my[1]};";
                    $i += 4; $changed = true; continue;
                }

                // P4: push + push + if_icmp*
                //   $stack[$sp++] = X;  $stack[$sp++] = Y;
                //   $b = $stack[--$sp]; $a = $stack[--$sp]; if ($a CMP $b) goto L;
                if ($i + 2 < $n
                    && preg_match($pushRe, $stmts[$i], $mx)
                    && preg_match($pushRe, $stmts[$i + 1], $my)
                    && preg_match('/^        \$b = \$stack\[--\$sp\]; \$a = \$stack\[--\$sp\]; if \(\$a (===|!==|<|<=|>|>=) \$b\) goto (L_\d+);$/', $stmts[$i + 2], $mc)) {
                    $out[] = "        if ({$mx[1]} {$mc[1]} {$my[1]}) goto {$mc[2]};";
                    $i += 3; $changed = true; continue;
                }

                // P5: push + if-single-op (vs 0 or null)
                //   $stack[$sp++] = X;  if ($stack[--$sp] CMP 0|null) goto L;
                if ($i + 1 < $n
                    && preg_match($pushRe, $stmts[$i], $mx)
                    && preg_match('/^        if \(\$stack\[--\$sp\] (===|!==|<|<=|>|>=) (0|null)\) goto (L_\d+);$/', $stmts[$i + 1], $mc)) {
                    $out[] = "        if ({$mx[1]} {$mc[1]} {$mc[2]}) goto {$mc[3]};";
                    $i += 2; $changed = true; continue;
                }

                // P6: push + push + iadd-style (no immediate store) — leave for future
                //     This case appears when the iadd result is used by a non-store
                //     consumer (e.g. another arith op). Erasing requires real
                //     abstract-stack tracking; punt for now.

                $out[] = $stmts[$i];
                $i++;
            }
            $stmts = $out;
        } while ($changed);

        return $stmts;
    }

    private function readSignedByte(array $bytes, int $pc): int
    {
        $v = $bytes[$pc];
        return ($v & 0x80) ? $v - 0x100 : $v;
    }

    private function readSignedShort(array $bytes, int $pc): int
    {
        $v = ($bytes[$pc] << 8) | $bytes[$pc + 1];
        return ($v & 0x8000) ? $v - 0x10000 : $v;
    }

    private function readUnsignedShort(array $bytes, int $pc): int
    {
        return ($bytes[$pc] << 8) | $bytes[$pc + 1];
    }

    /**
     * Emit an invokedynamic call site. Currently handles
     * `StringConcatFactory.makeConcatWithConstants` (Java 9+ string
     * concatenation, the most common indy use). Lambda metafactory
     * and arbitrary bootstraps are deferred — they emit a fallback
     * comment.
     */
    private function emitInvokeDynamic(int $idx): string
    {
        $idy = $this->constantPool[$idx] ?? null;
        if (!($idy instanceof InvokeDynamicInfo)) {
            return "        // UNRESOLVED INVOKEDYNAMIC (idx {$idx})";
        }
        $bsmIdx = $idy->getBootstrapMethodAttrIndex();
        $bsm = $this->bootstrapMethods[$bsmIdx] ?? null;
        $nat = $this->constantPool[$idy->getNameAndTypeIndex()] ?? null;
        if (!$bsm || !($nat instanceof NameAndTypeInfo)) {
            return "        // UNRESOLVED INVOKEDYNAMIC (no bootstrap)";
        }
        $callSiteName = $this->utf8At($nat->getNameIndex());
        $callSiteDesc = $this->utf8At($nat->getDescriptorIndex());

        // Resolve the bootstrap method to find which factory it is.
        $mh = $this->constantPool[$bsm->getBootstrapMethodRef()] ?? null;
        $bsmClass = '?'; $bsmMethod = '?';
        if ($mh instanceof MethodHandleInfo) {
            $ref = $this->constantPool[$mh->getReferenceIndex()] ?? null;
            if (($ref instanceof MethodrefInfo) || ($ref instanceof InterfaceMethodrefInfo)) {
                $clsInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
                $refNat  = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
                if ($clsInfo instanceof ClassInfo) {
                    $bsmClass = $this->utf8At($clsInfo->getClassIndex());
                }
                if ($refNat instanceof NameAndTypeInfo) {
                    $bsmMethod = $this->utf8At($refNat->getNameIndex());
                }
            }
        }

        // makeConcatWithConstants: first bootstrap arg is the recipe
        // string (CP_String → utf8); remaining args are constants
        // referenced by \x02 in the recipe. Dynamic args (\x01) come
        // from the operand stack — count is fixed by the call site
        // descriptor's argument list.
        if (($bsmClass === 'java/lang/invoke/LambdaMetafactory')
            && in_array($bsmMethod, ['metafactory', 'altMetafactory'], true)) {
            return $this->emitLambdaMetafactory($idy, $bsm, $callSiteName, $callSiteDesc);
        }

        if ($bsmClass === 'java/lang/invoke/StringConcatFactory'
            && $bsmMethod === 'makeConcatWithConstants') {
            // PHPJava pre-resolves bootstrap arguments — getBootstrapArguments()
            // returns typed CP entries (StringInfo/IntegerInfo/etc), not raw
            // CP indices. The first entry is the recipe (StringInfo).
            $bsmArgs = $bsm->getBootstrapArguments();
            $recipe = '';
            if (isset($bsmArgs[0]) && $bsmArgs[0] instanceof StringInfo) {
                $recipe = $this->utf8At($bsmArgs[0]->getStringIndex());
            }
            // Constant args after the recipe — convert each to a PHP
            // literal expression (StringInfo → 'str', IntegerInfo → int, …).
            $constArgs = [];
            for ($i = 1; $i < count($bsmArgs); $i++) {
                $constArgs[] = $this->cpEntryToLiteral($bsmArgs[$i]);
            }
            [$argTypes, ] = $this->parseMethodDescriptor($callSiteDesc);
            $argc = count($argTypes);
            return $this->emitStringConcat($recipe, $constArgs, $argc);
        }

        // Other bootstraps (LambdaMetafactory, custom indy) — defer.
        return "        // UNIMPLEMENTED INVOKEDYNAMIC bootstrap: {$bsmClass}.{$bsmMethod}";
    }

    /**
     * LambdaMetafactory: generate a synthetic PHP class wrapping the
     * lambda's underlying static method, then emit a `new` of that
     * class with captures.
     *
     * Bootstrap arguments (per JDK LambdaMetafactory.metafactory spec):
     *   [0] samMethodType (MethodType)        — the SAM signature
     *   [1] implMethod    (MethodHandle)      — the actual lambda body
     *   [2] instantiatedMethodType (MethodType) — for generic erasure
     * altMetafactory has additional flags + extra interfaces; treat
     * the same as metafactory for the simple case.
     *
     * Call-site contract:
     *   - Stack: [capture0, capture1, …]
     *   - After: [lambda_instance]
     *   - The lambda's SAM method, when invoked, calls back into
     *     implMethod with (captures..., samArgs...).
     */
    private function emitLambdaMetafactory(InvokeDynamicInfo $idy, $bsm, string $samName, string $callSiteDesc): string
    {
        $bsmArgs = $bsm->getBootstrapArguments();

        // implMethod is the second bootstrap arg, a MethodHandle.
        $implHandle = $bsmArgs[1] ?? null;
        if (!($implHandle instanceof MethodHandleInfo)) {
            return "        // UNRESOLVED LAMBDA — implMethod not a MethodHandle";
        }
        $ref = $this->constantPool[$implHandle->getReferenceIndex()] ?? null;
        if (!(($ref instanceof MethodrefInfo) || ($ref instanceof InterfaceMethodrefInfo))) {
            return "        // UNRESOLVED LAMBDA — impl ref not a Methodref";
        }
        $implClassInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
        $implNat       = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        if (!($implClassInfo instanceof ClassInfo) || !($implNat instanceof NameAndTypeInfo)) {
            return "        // UNRESOLVED LAMBDA — impl class/nat missing";
        }
        $implClass  = $this->utf8At($implClassInfo->getClassIndex());
        $implMethod = $this->utf8At($implNat->getNameIndex());
        $implDesc   = $this->utf8At($implNat->getDescriptorIndex());

        // SAM signature is the call site descriptor's RETURN's interface
        // method — but for naive emit we use the SAM method *name* (from
        // the call site's NameAndType) and reach the SAM descriptor from
        // the first bootstrap arg (a MethodType, which PHPJava resolves
        // to a Utf8 holding the descriptor string).
        $samDesc = '()V';
        if (isset($bsmArgs[0])) {
            // MethodType info: in PHPJava, looks like Utf8Info or has
            // a getDescriptorIndex(). Best-effort.
            $mt = $bsmArgs[0];
            if (method_exists($mt, 'getDescriptorIndex')) {
                $samDesc = $this->utf8At($mt->getDescriptorIndex());
            } elseif ($mt instanceof Utf8Info) {
                $samDesc = $mt->getString();
            }
        }
        [$samArgTypes, $samRet] = $this->parseMethodDescriptor($samDesc);
        [$callSiteArgTypes, ]   = $this->parseMethodDescriptor($callSiteDesc);

        // Capture count = arg count at the indy call site (the captures
        // are pushed onto the stack before invokedynamic). The SAM args
        // come at SAM-call time, not capture time.
        $captureCount = count($callSiteArgTypes);
        $samArgCount  = count($samArgTypes);

        // Generate the synthetic class.
        $idx = $this->lambdaCounter++;
        $ownerMangled = $this->mangle($this->currentClassBin);
        $lambdaClassName = "{$ownerMangled}__Lambda_{$idx}";
        // The lambda's body lives in the original class. From inside the
        // synthetic Lambda class we can't use `self::` (that'd resolve to
        // the Lambda class). Use the explicit FQN instead, bypassing the
        // self-class shortcut classFqn() applies.
        $implFqn = $implClass === $this->currentClassBin
            ? '\\PHPJava\\Aot\\Generated\\' . $this->mangle($implClass)
            : $this->classFqn($implClass);
        $implMethodPhp = $this->mangleMethod($implMethod);
        $samNamePhp = $this->mangleMethod($samName);

        // Constructor: assign captures into private fields $c0, $c1, …
        $ctorParams = [];
        $ctorAssigns = [];
        $fieldDecls = [];
        for ($i = 0; $i < $captureCount; $i++) {
            $ctorParams[] = "\$c{$i}";
            $ctorAssigns[] = "\$this->c{$i} = \$c{$i};";
            $fieldDecls[] = "    private \$c{$i};";
        }

        // SAM method: forwards (captures..., samArgs...) to the impl.
        $samParams = [];
        $callArgs = [];
        for ($i = 0; $i < $captureCount; $i++) $callArgs[] = "\$this->c{$i}";
        for ($i = 0; $i < $samArgCount; $i++) {
            $samParams[] = "\$a{$i}";
            $callArgs[] = "\$a{$i}";
        }
        $samCall = "{$implFqn}::{$implMethodPhp}(" . implode(', ', $callArgs) . ")";
        $samBody = $samRet === 'V' ? "        {$samCall};" : "        return {$samCall};";

        $fieldsBlock = $fieldDecls ? implode("\n", $fieldDecls) . "\n" : '';
        $ctorBlock   = $captureCount > 0
            ? "    public function __construct(" . implode(', ', $ctorParams) . ") {\n        " . implode("\n        ", $ctorAssigns) . "\n    }"
            : '';
        $samBlock    = "    public function {$samNamePhp}(" . implode(', ', $samParams) . ") {\n{$samBody}\n    }";

        $lambdaSrc = "namespace PHPJava\\Aot\\Generated;\n\nfinal class {$lambdaClassName}\n{\n{$fieldsBlock}{$ctorBlock}\n{$samBlock}\n}\n";
        $this->lambdaClasses[] = [
            'fqn' => "\\PHPJava\\Aot\\Generated\\{$lambdaClassName}",
            'php' => $lambdaSrc,
        ];

        // Emit at the call site: pop captures, instantiate the lambda.
        $popLines = [];
        $argRefs = [];
        for ($i = $captureCount - 1; $i >= 0; $i--) {
            $popLines[] = "\$__c{$i} = \$stack[--\$sp];";
            $argRefs[$i] = "\$__c{$i}";
        }
        ksort($argRefs);
        $popPart = $popLines ? implode(' ', $popLines) . ' ' : '';
        $newExpr = "new \\PHPJava\\Aot\\Generated\\{$lambdaClassName}(" . implode(', ', $argRefs) . ")";
        return "        {$popPart}\$stack[\$sp++] = {$newExpr};";
    }

    /**
     * Translate a StringConcatFactory recipe + arg counts into a PHP
     * concat expression. Recipe escape codes:
     *   \x01  — placeholder for next dynamic stack arg
     *   \x02  — placeholder for next bootstrap-constant arg
     * Anything else is a literal character.
     *
     * Stack contract: pop $argc dynamic args (LTR), build the result
     * string by interpolating recipe placeholders, push the string.
     */
    private function emitStringConcat(string $recipe, array $constArgs, int $argc): string
    {
        // Pop dynamic args in reverse so we can apply them LTR in the recipe.
        $popLines = [];
        $dynArgs = [];
        for ($i = $argc - 1; $i >= 0; $i--) {
            $popLines[] = "\$__a{$i} = \$stack[--\$sp];";
            $dynArgs[$i] = "\$__a{$i}";
        }
        ksort($dynArgs);
        $popPart = $popLines ? implode(' ', $popLines) . ' ' : '';

        $parts = [];
        $literal = '';
        $constIdx = 0;
        $dynIdx = 0;
        $n = strlen($recipe);
        for ($i = 0; $i < $n; $i++) {
            $c = $recipe[$i];
            if ($c === "\x01") {
                if ($literal !== '') { $parts[] = var_export($literal, true); $literal = ''; }
                $parts[] = '(string)' . ($dynArgs[$dynIdx++] ?? "''");
            } elseif ($c === "\x02") {
                if ($literal !== '') { $parts[] = var_export($literal, true); $literal = ''; }
                $parts[] = $constArgs[$constIdx++] ?? "''";
            } else {
                $literal .= $c;
            }
        }
        if ($literal !== '') $parts[] = var_export($literal, true);
        $expr = $parts ? implode(' . ', $parts) : "''";
        return "        {$popPart}\$stack[\$sp++] = {$expr};";
    }

    private function readSigned32(array $bytes, int $pc): int
    {
        $v = ($bytes[$pc] << 24) | ($bytes[$pc + 1] << 16) | ($bytes[$pc + 2] << 8) | $bytes[$pc + 3];
        return ($v & 0x80000000) ? $v - 0x100000000 : $v;
    }

    /** Operand-byte count after the opcode itself. */
    private function opcodeLength(int $op): int
    {
        return match ($op) {
            0x10, 0x12, 0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0xA9, 0xBC => 1,
            0x11, 0x13, 0x14, 0x84, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5, 0xA6, 0xA7, 0xA8, 0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xBB, 0xBD, 0xC0, 0xC1, 0xC6, 0xC7 => 2,
            0xB9, 0xBA, 0xC5, 0xC8, 0xC9 => 4,
            default => 0,
        };
    }

    private function mangle(string $classPath): string
    {
        return str_replace(['.', '/', '\\', '$'], '_', $classPath);
    }

    private function mangleMethod(string $name): string
    {
        if ($name === '<init>') return '__construct';
        if ($name === '<clinit>') return '__staticConstruct';
        // PHP method names follow [A-Za-z_][A-Za-z0-9_]*; JVM allows
        // additional chars including `$` (used pervasively in synthetic
        // lambda$run$0-style names). Mangle: $ → _S_, < → _LT_, > → _GT_.
        return str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name);
    }

    /** name → [desc, desc, ...] for the current class. Set per-compile. */
    private array $overloadIndex = [];

    /**
     * Per-class overload index. Group methods by their mangled-simple
     * name; record the descriptor list. A name with multiple descriptors
     * is overloaded and triggers descriptor-mangled emission + dispatcher.
     *
     * @param array $methods raw method-info array from JCC
     * @return array<string, string[]>  simple-mangled-name → list of descriptors
     */
    private function buildOverloadIndex(array $methods): array
    {
        $index = [];
        foreach ($methods as $method) {
            $name = $this->utf8At($method->getNameIndex());
            $desc = $this->utf8At($method->getDescriptorIndex());
            $simpleMangled = $this->mangleMethod($name);
            $index[$simpleMangled][] = $desc;
        }
        // Drop entries with only one descriptor — those aren't overloaded.
        return \array_filter($index, fn($descs) => \count($descs) > 1);
    }

    /**
     * Mangle a method name for the AOT-emitted PHP, accounting for
     * overloads in the current class. Names with multiple descriptors
     * receive a descriptor suffix; non-overloaded names stay simple.
     *
     * Static helper so the IR Builder can mirror the same logic via
     * its own overload index (set by the Compiler).
     */
    public static function mangleMethodForOverload(string $name, string $desc, array $overloadIndex): string
    {
        $simple = $name === '<init>' ? '__construct'
            : ($name === '<clinit>' ? '__staticConstruct'
                : \str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name));
        if (!isset($overloadIndex[$simple])) {
            return $simple;
        }
        return $simple . '_' . self::mangleDescriptorForOverload($desc);
    }

    /**
     * JVM descriptor → PHP-identifier-safe suffix for overload mangling.
     * Strips `(`, `)` and the return type (Java overloads by params
     * only); maps `[` → `a`, `/` → `_`, `;` → `_`, `$` → `_`.
     *
     * Examples:
     *   `([Ljava/lang/String;)V`  →  `aLjava_lang_String_`
     *   `([I)V`                   →  `aI`
     *   `(IJ)Z`                   →  `IJ`
     */
    public static function mangleDescriptorForOverload(string $desc): string
    {
        $rparen = \strpos($desc, ')');
        $params = $rparen !== false ? \substr($desc, 1, $rparen - 1) : $desc;
        return \str_replace(['[', '/', ';', '$'], ['a', '_', '_', '_'], $params);
    }

    /**
     * Generate the dispatcher PHP for an overloaded name. Picks by
     * arg-count first; ties broken by arg-shape inspection (array vs
     * scalar; array element type; scalar PHP type).
     *
     * @param string $simpleName  e.g. 'main'
     * @param string[] $descriptors  list of overload descriptors
     */
    private function emitOverloadDispatcher(string $simpleName, array $descriptors, bool $isStatic): string
    {
        // Group by arg count; build the candidate list for each count.
        // Each candidate carries its mangled-method-name and arg-type list.
        $byArgc = [];
        foreach ($descriptors as $desc) {
            $argTypes = $this->parseDescriptorArgTypes($desc);
            $argc = \count($argTypes);
            $byArgc[$argc][] = [
                'mangled'  => $simpleName . '_' . self::mangleDescriptorForOverload($desc),
                'argTypes' => $argTypes,
                'desc'     => $desc,
            ];
        }

        $branches = [];
        foreach ($byArgc as $argc => $candidates) {
            if (\count($candidates) === 1) {
                $branches[] = "        if (\$argc === {$argc}) return "
                    . ($isStatic ? 'self::' : '$this->')
                    . "{$candidates[0]['mangled']}(...\$args);";
                continue;
            }
            // Multiple candidates at this arg count — emit a
            // shape-discriminating chain.
            $sub = ["        if (\$argc === {$argc}) {"];
            foreach ($candidates as $c) {
                $check = $this->buildArgShapeCheck($c['argTypes']);
                $sub[] = "            if ({$check}) return "
                    . ($isStatic ? 'self::' : '$this->')
                    . "{$c['mangled']}(...\$args);";
            }
            $sub[] = '        }';
            $branches[] = \implode("\n", $sub);
        }

        $sigQual = $isStatic ? 'public static function' : 'public function';
        $body = \implode("\n", $branches);
        return "    {$sigQual} {$simpleName}(...\$args)\n"
            . "    {\n"
            . "        \$argc = \\count(\$args);\n"
            . "{$body}\n"
            . "        throw new \\PHPJava\\Packages\\java\\lang\\NoSuchMethodException("
            . "'No matching overload for {$simpleName}/' . \$argc);\n"
            . "    }";
    }

    /**
     * Build a PHP boolean expression checking whether $args matches
     * the given JVM argument types. Conservative — false negatives
     * are acceptable (the dispatcher will throw NoSuchMethodException
     * which mirrors JDK reflective behaviour); false positives mean
     * the wrong overload runs.
     */
    private function buildArgShapeCheck(array $argTypes): string
    {
        $parts = [];
        foreach ($argTypes as $i => $t) {
            $parts[] = $this->argShapePredicate($t, "\$args[{$i}]");
        }
        return $parts ? \implode(' && ', $parts) : 'true';
    }

    private function argShapePredicate(string $type, string $expr): string
    {
        // Primitive types
        switch ($type) {
            case 'I': case 'J': case 'S': case 'B':
                return "\\is_int({$expr})";
            case 'D': case 'F':
                return "\\is_float({$expr})";
            case 'Z':
                return "\\is_bool({$expr})";
            case 'C':
                return "(\\is_string({$expr}) && \\mb_strlen({$expr}) === 1)";
        }
        // Reference types
        if (\str_starts_with($type, 'L')) {
            // Ljava/lang/String; → check is_string. Other refs:
            // is_object — could refine via instanceof, but that requires
            // resolving the AOT class; conservative is_object suffices
            // for most overload disambiguation.
            if ($type === 'Ljava/lang/String;') return "\\is_string({$expr})";
            return "\\is_object({$expr})";
        }
        if (\str_starts_with($type, '[')) {
            $elem = \substr($type, 1);
            // Empty arrays match any element type — only check is_array,
            // and if non-empty, the first element's predicate.
            $elemPred = $this->argShapePredicate($elem, "{$expr}[\\array_key_first({$expr})]");
            return "(\\is_array({$expr}) && (empty({$expr}) || {$elemPred}))";
        }
        return 'true';
    }

    /**
     * Parse a JVM method descriptor's argument-type list. Returns an
     * array of single-character primitive descriptors plus L…; / [… ;
     * reference forms. Mirrors the Builder's parseDescriptor but
     * exposed at compile-emit-time scope.
     */
    private function parseDescriptorArgTypes(string $desc): array
    {
        if (!\str_starts_with($desc, '(')) return [];
        $rparen = \strpos($desc, ')');
        $params = \substr($desc, 1, $rparen - 1);
        $types = [];
        $i = 0;
        $len = \strlen($params);
        while ($i < $len) {
            $c = $params[$i];
            if ($c === '[') {
                $start = $i;
                while ($i < $len && $params[$i] === '[') $i++;
                if ($i < $len && $params[$i] === 'L') {
                    while ($i < $len && $params[$i] !== ';') $i++;
                    $i++;
                } else {
                    $i++;
                }
                $types[] = \substr($params, $start, $i - $start);
            } elseif ($c === 'L') {
                $start = $i;
                while ($i < $len && $params[$i] !== ';') $i++;
                $i++;
                $types[] = \substr($params, $start, $i - $start);
            } else {
                $types[] = $c;
                $i++;
            }
        }
        return $types;
    }
}
