# Layers — what's where, what each piece does

> Decomplecting concerns. Read this before refactoring or deleting code.
> Companion to `docs/MODEL.md` (strategic) and `docs/PATTERNS.md`
> (subtraction targets). This doc is descriptive — what currently exists
> and what each piece does — written 2026-05-04 against HEAD `888591e`.

## TL;DR

Two pipelines live in the codebase:

```
                  PHP-syntax source                    .class file
                        │                                  │
              (nikic/php-parser)                  (PHPJava parser)
                        ↓                                  ↓
             Compiler/Lang/Assembler                 .class in memory
              (with Compiler/Emulator                       │
               for abstract-stack                  ┌────────┴────────┐
               type checking) ────────┐            ↓                 ↓
                                      ↓     Kernel/Mnemonics    src/Aot/Ir/
                            Compiler/Builder    (interpreter:    Builder + Lowerer
                             (ClassFile          on cut list)     (translator:
                              data model +                         survives)
                              serializer)                            │
                                      ↓                              ↓
                                 .class bytes                    PHP source
                                                                     │
                                                                  eval()
                                                                     ↓
                                                              Generated\<X> PHP class
                                                                     │
                                                                  PHP execution
                                                                  (Zend + JIT)
```

- **Stack 1 (left):** PHP source → JVM bytecode. Upstream PHPJava's
  "write Java semantics in PHP syntax" toy. Three subsystems:
  `Compiler/Lang/Assembler/` (5.8 kloc), `Compiler/Builder/` (3.2 kloc),
  `Compiler/Emulator/` (3.5 kloc). Not on the AOT execution path. On
  the cut list — no production use.

- **Stack 2 (right):** JVM bytecode → execution. Two parallel paths:
  - Interpreter — walks opcodes at runtime via `Kernel/Mnemonics/_*`
    (200 files, 6.8 kloc) and `JavaMethodCallable::call`. **On the
    cut list per `docs/PATTERNS.md`.**
  - AOT — translates to PHP, runs natively. `src/Aot/*` (4.8 kloc).
    Strategic direction.

Post-flip, only the right side of stack 2 survives.

## Naming axis: when "dynamic" means what

Three meanings collide on the word "dynamic" in PHPJava:

| Meaning | Files | Decision |
|---|---|---|
| **A. Instance method** (PHPJava-internal: non-static, has `$this`) | `Core/JVM/Invoker/JavaClassDynamicMethodInvoker.php`, `PHPClassDynamicMethodInvoker.php`, `InvokerInterface::isDynamic()`, `Core/JVM/Field/JavaDynamicField.php`, `PHPDynamicField.php`, `Core/JVM/Extended/DynamicAccessorProvidable.php`, comments throughout | **Rename to "Instance"** |
| **B. invokedynamic** (JVM opcode 0xBA — bootstrap-resolved call site) | `Kernel/Mnemonics/_invokedynamic.php`, `Kernel/Structures/InvokeDynamicInfo.php`, `Kernel/Maps/ConstantPoolTag.php` (CONSTANT_InvokeDynamic + CONSTANT_Dynamic), `Compiler/Emulator/Mnemonics/_invokedynamic.php`, all of `Packages/java/lang/invoke/*` | **Keep "dynamic"** — JVM term |
| **C. Virtual dispatch** (Java semantics — runtime resolution by receiver class) | comments in `_invokevirtual.php`, `_invokeinterface.php`, scattered call-site comments | **Rewrite to "virtual" or "instance"** in comments |

Concrete renames in active code (files getting deleted under the AOT-only
flip don't need renaming):

| From | To |
|---|---|
| `JavaClassDynamicMethodInvoker` | `JavaClassInstanceMethodInvoker` |
| `PHPClassDynamicMethodInvoker` | `PHPClassInstanceMethodInvoker` |
| `InvokerInterface::isDynamic()` | `isInstanceMethod()` |
| `JavaDynamicField` | `JavaInstanceField` |
| `PHPDynamicField` | `PHPInstanceField` |
| `DynamicAccessorProvidable` | `InstanceAccessorProvidable` |
| `->getDynamic()` (public API) | keep — external API, churn cost > clarity gain. Document the meaning instead. |

**Do not touch** (legitimately about invokedynamic, not about
instance methods): `_invokedynamic` mnemonic, `InvokeDynamicInfo`,
`CONSTANT_InvokeDynamic`, `CONSTANT_Dynamic`, all of
`Packages/java/lang/invoke/*`.

## What each subsystem does

### `src/Aot/` — bytecode → PHP source (4.8 kloc, 9 files)

| File | Lines | Role |
|---|---|---|
| `Aot/Compiler.php` | ~2,040 | Top-level: parse a class via PHPJava parser, build IR per method, lower IR to PHP, emit fields + classes + lambda classes, eval. Contains the legacy string-path emitter (~1,500 lines) as fallback for opcodes the IR Builder doesn't yet cover. **2026-05-04**: per-class overload index + descriptor-mangled-name dispatcher generator, interface-as-`abstract class` emit (with `abstract` stubs for body-less methods), `extends` clause + parent preload via `Loader::loadClass`, `aotSuperClassBin` falls back to first interface when super is Object. |
| `Aot/Ir/Builder.php` | ~2,070 | JVM bytecode → IR walker. Bakes operand-stack erasure into the IR via abstract-stack tracking during construction. ~150 opcodes covered. **2026-05-04**: binary-name threading on `New_`/`StaticCall` (closes inner-class `Outer$Inner` autoload ambiguity); super-init peephole emits `parent::__construct` when class extends; wrapper-class IR lowerings per BOXING.md (getstatic constants, `valueOf`/`compare`/`intValue`/`equals` etc); contract-shape Z/C narrow at putfield/putstatic/castore + widen at getfield/getstatic/caload; `boolean[]` slot tracker (disambiguates from `byte[]` at the shared bastore opcode); array by-ref auto-detect (cljp port — `StoreArrayElement` on a param slot marks `byrefParamIndices` on the Method); descriptor-aware mangle for self-class invokes when the name is overloaded. |
| `Aot/Ir/Lowerer.php` | ~270 | IR → PHP. Live-label elision, redundant-goto elision, try/catch wrapping. **2026-05-04**: `&$__aN` in signature + `&` alias in `$L` prelude for byref params (without the prelude alias the array literal value-copies and the signature `&` is lost on the first $L write); INF/-INF distinct from NAN in FloatLit emit; `BoolLit` rendering. |
| `Aot/Ir/Node.php` | ~330 | IR node hierarchy: `Module`, `Method`, `BasicBlock`, `Stmt` (`StoreLocal`, `IincLocal`, `ExprStmt`, `StoreStaticField`, …), `Terminator` (`Goto_`, `CondGoto`, `Return_`, `Throw_`, `Switch_`), `Expr` (literals, `LocalRead`, `BinOp`, `StaticCall`, `StaticFieldRead`, …). Pure-vs-impure flag at the Expr level. **2026-05-04**: optional `binaryName` on `New_`/`StaticCall` (cross-class AOT routing); `byrefParamIndices` on `Method`; `BoolLit`. |
| `Aot/Ir/InlinePass.php` | 163 | IR-level cross-method inlining. Single-`return <expr>` methods substituted into `StaticCall` sites. Fixpoint. |
| `Aot/Ir/Flat.php` | 155 | Alternate flat-array IR shape (PoC). F-IR5 falsified the 10× speedup hypothesis (1.29× actual); kept for reference. |
| `Aot/Ir/ArrayHelper.php` | 43 | Java-array-wrapper adapter for non-LocalRead-source array operations (escape analysis falls back here). |
| `Aot/Loader.php` | ~210 | Public AOT entry: `loadClass($cp)`, `defineClass($cp, $bytes)`, `callStatic(...)`, `tryCallStatic(...)`. Reuses `$loaded` / `$failed` registries. **2026-05-04**: `newInstance($cp, ...$args)` mirror of `callStatic` for `New_` IR routing; `tryCallStatic` validates arity via `ReflectionMethod` and throws JVM-shaped `NoSuchMethodException` (instead of letting PHP's `ArgumentCountError` leak). |
| `Aot/Runtime/bootstrap.php` | ~480 | Runtime helpers required by AOT-emitted code. **The AOT-routed JDK shim namespace** (per `Builder::classFqn` mapping `java/`/`javax/`/`jdk/`/`sun/`/`com/sun/` to `\PHPJava\Aot\Runtime\…`). Currently: `\PHPJava\Aot\Runtime\java\lang\{System, String_, Integer, StringBuilder, Throwable_, +11 exception subclasses}`, `java\io\PrintStream`, `IndyRegistry`, `jvm_lushr`/`jvm_typeswitch`/`jvm_multianewarray` helpers, autoloader registration. Per CONTRACTS.md §1: raw PHP scalars, no boxing wrappers. |

#### AOT class-emit shape (2026-05-04)

What an AOT-emitted PHP class looks like, with the conventions
established this session:

```php
<?php
namespace PHPJava\Aot\Generated;

// Pre-load the parent before the class declaration. PHP autoloads
// `extends` targets at class-decl time; the AOT autoloader's `_ → /`
// heuristic can't reverse Outer$Inner. Same root-cause as the
// New_/StaticCall binary-name routing.
\PHPJava\Aot\Loader::loadClass('Outer$Inner');

#[\AllowDynamicProperties]
class Outer_1 extends \PHPJava\Aot\Generated\Outer
{
    public $field_S_0 = null;  // mangled `this$0` (synthetic outer ref)

    // __construct ()V
    public function __construct()
    {
        $L = [$this];
        $stack = []; $sp = 0;
        L_0:
        parent::__construct();   // emitted by super-init peephole
        // ... field initialisers, etc.
        return;
    }

    // ascQuickSort ([III)[I — array param mutated → byref
    public static function ascQuickSort(&$__a0, $__a1, $__a2)
    {
        // & on the param signature; & on the $L alias too — without
        // both, the array literal value-copies and the signature `&`
        // is lost on the first $L[0][i] = ... write.
        $L = [&$__a0, $__a1, $__a2, 0, 0, 0, 0];
        // ...
    }

    // main(String[]) — overloaded with main(int[]); descriptor-mangled
    public static function main_aLjava_lang_String__V($__a0) { ... }
    public static function main_aI_V($__a0) { ... }

    // Runtime dispatcher for the overloaded name
    public static function main(...$args)
    {
        $argc = \count($args);
        if ($argc === 1) {
            if (\is_array($args[0]) && (empty($args[0]) || \is_string($args[0][\array_key_first($args[0])])))
                return self::main_aLjava_lang_String__V(...$args);
            if (\is_array($args[0]) && (empty($args[0]) || \is_int($args[0][\array_key_first($args[0])])))
                return self::main_aI_V(...$args);
        }
        throw new \PHPJava\Packages\java\lang\NoSuchMethodException('No matching overload for main/' . $argc);
    }
}

// Trailing __staticConstruct() trigger if Java had <clinit>
\PHPJava\Aot\Generated\Outer_1::__staticConstruct();
```

Java interfaces emit similarly but as `abstract class` with default-
method bodies + `abstract` stubs for body-less methods. Implementers
`extends` the interface-as-abstract-class.

Cross-class invokes — `new Foo()`, `Foo::bar(...)` — for non-current,
non-JDK targets emit as `\PHPJava\Aot\Loader::newInstance('Foo', ...)`
or `\PHPJava\Aot\Loader::callStatic('Foo', 'bar', ...)`, threading the
exact JVM binary name to bypass the autoloader heuristic.

JDK-routed class refs (e.g. `System.out.println`) emit directly as
`\PHPJava\Aot\Runtime\java\lang\System::$out->println(...)` — no
loader hop, the bootstrap.php shim is required-once at eval time.

Z (boolean) and C (char) field/array values are stored in their
contract shape (PHP bool / 1-char UTF-8 string per CONTRACTS.md §1)
and round-tripped to JVM-stack int at the field/array boundaries via
`\intval`/`(\$x !== 0)` and `\mb_ord`/`\mb_chr` respectively.

### `src/Core/` — class loading + dispatch polymorphism (4.4 kloc, 74 files)

Survives the AOT-only flip (modulo the interpreter half of
`JavaMethodCallable::call`).

```
Core/
├── JavaClass.php              ─── load($cp), of($instance), construct(...)
│                                  user-facing entry
├── Extended/                  ─── 6 traits (InvokerProvidable etc.)
│                                  shared by JavaClass, JavaSimpleClass,
│                                  JavaCompiledClass, JavaArchive
├── JavaCompiledClass.php      ─── parsed-from-bytes class wrapper
├── JavaSimpleClass.php        ─── builtin-package class wrapper
├── JavaArchive.php            ─── JAR loader
└── JVM/
    ├── ConstantPool.php       ─── runtime constant pool view
    ├── JavaClassInvoker.php   ─── per-class invoker holder; provides
    │                              getStatic() and getDynamic()
    ├── PHPClassInvoker.php    ─── analogue for native-PHP-implemented Java classes
    ├── Cache/, Field/,
    │   Extended/, Parameters/,
    │   Stream/, Validations/  ─── supporting infrastructure
    └── Invoker/
        ├── InvokerInterface.php
        ├── JavaClassMethodInvoker.php           ──┐
        ├── JavaClassDynamicMethodInvoker.php    ──┤  4 invoker classes
        ├── PHPClassMethodInvoker.php            ──┤  on 2 axes:
        ├── PHPClassStaticMethodInvoker.php      ──┤  source × kind
        ├── PHPClassDynamicMethodInvoker.php     ──┘
        └── Extended/
            ├── JavaMethodCallable.php   ←── 372 lines; hosts the
            │                                interpreter dispatch loop
            │                                (~250 lines of which die
            │                                under AOT-only)
            ├── PHPMethodCallable.php
            ├── JavaMethodFindable.php
            ├── ... (12 traits total)
```

**Dispatch polymorphism axes** — orthogonal:
- **Source axis:** `Java*` (loaded from bytecode) vs `PHP*` (Java
  classes implemented as native PHP under `Packages/java/*`).
- **Kind axis:** static methods vs instance methods.

Cross product = 4 invoker classes. Each pairs with a Field accessor
(`JavaStaticField`, `JavaDynamicField`, `PHPStaticField`,
`PHPDynamicField`). Both axes survive the AOT-only flip; the
"Dynamic → Instance" rename is along the kind axis only.

### `src/Kernel/` — interpreter substrate (12 kloc, 322 files)

| Subdir | Lines | Files | Role | Survives AOT-only flip? |
|---|---|---|---|---|
| `Mnemonics/` | 6,823 | 205 | Per-opcode interpreter classes, one per JVM opcode. The bytecode walker dispatches into these at runtime. | **No — cut entirely** |
| `Attributes/` | 838 | 24 | ClassFile attribute parsers (Code, ExceptionTable, LineNumberTable, …). | Yes — used by parser |
| `Structures/` | 1,239 | 30 | ClassFile data structures (FieldInfo, MethodInfo, ConstantPool entries — InvokeDynamicInfo, ClassInfo, …). | Yes — used by parser |
| `Resolvers/` | 756 | 8 | ClassResolver, AttributionResolver, TypeResolver, SuperClassResolver, MnemonicResolver. | Most yes; `MnemonicResolver` cuts with interpreter. |
| `Maps/` | 370 | 8 | OpCode, ConstantPoolTag, AccessFlag, etc. — enum-like maps. | Yes |
| `Types/` | 568 | 13 | Runtime boxing wrappers: `Int_`, `Long_`, `Double_`, `Char_`, etc. Per CONTRACTS.md §1, partial #12 wrapper-removal already landed; the rest cuts under AOT-only. | **No — cut** |
| `Filters/` | 122 | 1 | `Normalizer.php` — autobox normaliser, paired with the wrappers. | **No — cut** |
| `Frames/` | 229 | 8 | Interpreter call-frame state. | **No — cut** |
| `Variables/` | 191 | 10 | Local-variable type tags for the interpreter. | **No — cut** |
| `Provider/` | 50 | 3 | DependencyInjectionProvider — per-call DI rebuild for the interpreter dispatch loop. | **No — cut** |
| `Internal/` | 267 | 5 | Internal helpers used across both interpreter and AOT. | Mostly yes |
| `Core/` | 525 | 7 | Accumulator (interpreter operand-stack), Operation, ConstantPool internals. | Mixed — Accumulator cuts; ConstantPool stays |

### `src/Packages/java/*` — JDK shims (54 kloc, 366 files)

The Java standard-library surface implemented in PHP. Used by both
interpreter and AOT. ~80% raise `NotImplementedException` — Tier 2
fill is the long mechanical run (~233 classes per
`docs/CLOJURE-BOOT-ANALYSIS.md`).

Survives AOT-only flip unchanged. AOT-emitted code calls into these
classes the same way the interpreter does, with one exception: the
hot ones (`System.out`, exception classes) have raw-scalar versions
in `src/Aot/Runtime/bootstrap.php` per CONTRACTS.md §1, overriding
the wrapped versions for AOT use.

### `src/Compiler/` — legacy PHP→bytecode compiler (12.7 kloc, 377 files)

**Direction:** opposite of our AOT. Takes a PHP-syntax source file
(parsed via `nikic/php-parser`), assembles into JVM bytecode. Three
subsystems:

| Subdir | Lines | Files | Role | Status |
|---|---|---|---|---|
| `Lang/Assembler/` | 5,847 | 86 | PHP-AST → bytecode assembler. Top-level: `ClassAssembler`, processes `\PhpParser\Node\Stmt\Class_`. Walks the PHP AST, emits opcodes, builds ConstantPool, etc. Uses `Compiler/Builder` for the in-memory class file model and `Compiler/Emulator` for compile-time type checking. | **Cut.** Educational artifact (per `PATTERNS.md`:545). Only used by `tests/Cases/Compiler/*`. |
| `Builder/` | 3,228 | 84 | ClassFile data model + serializer. Class hierarchy mirroring the JVM class-file format (`ClassFileStructure`, `ConstantPool`, `MethodInfo`, `Attributes`, `Operation`, …) plus a writer that serializes to bytes. Used as a library by `Lang/Assembler` and tests. | **Was "keep but freeze"** per `PATTERNS.md`:546. Post-cut of Lang/Assembler and the legacy tests, **no remaining users** — revisit to cut entirely. |
| `Emulator/Mnemonics/` | 3,529 | 200 | Parallel mnemonics tree — one file per opcode, but does **abstract type tracking at compile time** instead of runtime execution. Pops/pushes type tags to validate the operand stack while the Assembler emits code, computes max-stack / max-locals for frame info. | **Was "repurpose"** per `PATTERNS.md`:544. Pattern overlap with `Aot/Ir/Builder.php`'s abstract-stack tracking — but our Builder already does this work. **No remaining role; cut.** |
| `Compiler.php` | ~150 | 1 | Legacy front-door: `ClassFileStructure → bytes`. Different namespace from `src/Aot/Compiler.php` (`PHPJava\Compiler` vs `PHPJava\Aot`). | Cut with the rest. |

**External users** of these legacy subsystems:

| Caller | What it imports | Fate |
|---|---|---|
| `src/Kernel/Resolvers/MnemonicResolver.php` | `Compiler/Builder/Generator/Operation/Operation` (opcode type lookup) | dies with interpreter (used only by `Kernel/Mnemonics/`) |
| `tests/Cases/Compiler/HelloWorldTest.php` | most of Lang/Assembler + Builder | dies with the legacy stack |
| `tests/Cases/Compiler/FinderTest.php` | Builder finders | dies with the legacy stack |
| `tests/Cases/Compiler/JavaRunnable.php` | base for the above | dies with them |

Net: post-AOT-only flip + post-cut, **none of the three legacy
subsystems has any user**.

### Are any of the three salvageable into AOT hot paths?

| Subsystem | Subsume into AOT? | Future-capability? |
|---|---|---|
| `Lang/Assembler/` | No — opposite direction. AOT consumes bytecode; Assembler produces it. | No — use case (write Java in PHP syntax) is off-strategy. |
| `Emulator/Mnemonics/` | **Pattern already in `Aot/Ir/Builder.php`** (abstract-stack tracking). Code doesn't subsume; it's redundant rather than reusable. | No incremental value. |
| `Builder/` | No fit today — AOT emits PHP source, not bytecode. | The **capability** (write .class bytes from a programmatic model) may matter eventually. **One** future-case worth listing. |

**Future capability worth tracking, NOT a Builder revival:**

If AOT ever needs to generate `.class` bytes itself — concrete cases
include `java.lang.reflect.Proxy.newProxyInstance` synthesis, ASM-
equivalent dynamic class generation, or round-trip transformations
— the right path is **not** to revive `Compiler/Builder/`. It's to
add a thin writer to `src/Kernel/Structures/*`, round-tripping what
the reader already consumes. ~300–500 LOC of clean code; no legacy
inheritance from the entangled Assembler.

The Builder is 3.2 kloc of hand-rolled debt; the round-trip writer
is ~500 LOC of new code that mirrors existing parser structures.
The clean replacement is the right answer when the use case surfaces;
keeping Builder alive in anticipation costs more than rebuilding.

Defer until a concrete use case surfaces.

### The bigger picture: bidirectional IR pipeline

`Aot/Ir` is the natural choke point for *any* language-to-language
translation that has either PHP or JVM as a side. Today we have one
input (JVM bytecode) and one output (PHP source). Two future
capabilities, both as separate builders/lowerers off the existing IR:

- **PHP source → JVM bytecode** ("PHP → Java compilation"). Use
  `nikic/php-parser` for the PHP AST, build a PHP-AST → IR builder
  parallel to `Aot/Ir/Builder.php`, emit JVM bytecode via the
  Kernel/Structures round-trip writer above. ~1.5 kloc of clean
  IR-based code. Closes the bridge in the other direction (PHP code
  packaged as `.jar`, runnable on real HotSpot/Graal). **Not** a
  `Compiler/Lang/Assembler/` revival — that subsystem was non-IR
  and entangled with its own data model. Build fresh against IR.

- **Other JVM languages → PHP** (Scala, Kotlin, Groovy, JRuby,
  Clojure-via-JVM-bytecode). Their compiled `.class` files run
  through the existing `Aot/Ir/Builder.php` path; should "just work"
  modulo Tier 2 shim coverage and per-language `invokedynamic`
  handlers via `IndyRegistry`. Subject to the same license posture
  per-input-language: only redistribute outputs of bytecode the
  caller has rights to transform.

Decision frame: every language-pair conversation is "what builder
or lowerer do we add to the IR?", not "what runtime do we add?".
The runtime is PHP (Zend) or JVM, both already exist; the IR is
the substrate that connects them.

This **strengthens** the case for cutting `Compiler/Lang/Assembler/`,
`Builder/`, `Emulator/` now. Reviving them locks us into their non-IR
architecture; cutting them clears the path for the IR-based
bidirectional pipeline when the use case surfaces.

### License posture for the JDK surface

**Important correction (2026-05-04):** an earlier draft of this doc
suggested AOT-compiling OpenJDK bytecode through our pipeline as a
bulk-port mechanism. **That is not legally safe** for redistribution.

OpenJDK is GPLv2 + Classpath Exception. The CPE permits *linking*
without GPL infection but does **not** permit *transformation* and
redistribution under a different license. Bytecode → PHP is a
structural translation; copyright follows program structure, not
syntax. The output of running OpenJDK `.class` files through our AOT
pipeline is a derivative work, GPL-encumbered.

**The five legally-safe paths for the JDK surface:**

| Path | Approach | Legality |
|---|---|---|
| A | Hand-written thin PHP shims (delegate to PHP primitives) | clean |
| B | Wrap PHP extensions (PCRE, hash, zlib, openssl, mbstring, …) | clean — wrapping PHP extensions, not OpenJDK |
| C | Auto-generate stubs from JDK class-file *signatures* (no implementation copy) | clean — API signatures aren't copyrightable |
| **D′** | Behavioral oracle — run JDK as a reference, capture I/O traces, reimplement PHP from observed behavior | clean — JDK as test oracle, not source |
| E | Clean-room implementation against JLS/Javadoc spec; OpenJDK source as reference *only* with care | clean if the implementer hasn't read OpenJDK source for the specific class being implemented |

**The path D not on this list (AOT-compile OpenJDK bytecode → ship as
MIT) creates a derivative work and would force GPLv2 on the entire
distribution.** Internal experimentation is fine; redistribution is
not.

**Working plan:** combine A + B + C + D′ + E. The oracle harness
(D′) is the leverage move — it gives rank-1 behavior tests for
every class we reimplement, without copyright contamination. PHPJava's
existing FFM-based JVM-side parity test infrastructure is the
foundation; extending it to per-method-level I/O capture across the
233-class T2 surface is ~1 week of harness work and unblocks the
verifiable reimplementation of every class thereafter.

### `src/IO/Standard/` (50 lines, 1 file)

`Output::getHeapspace()` / `Output::clearHeapspace()` — captures stdout
written from inside Java code (e.g., `System.out.println`) for tests
to assert on. Used by both interpreter and AOT paths via the
`Packages/java/io/PrintStream` shim. Survives.

### `src/Exceptions/` (252 lines, 36 files)

PHPJava-internal exception types. Most survive — the AOT path also
throws these. A few (`UndefinedOpCodeException`, `IllegalJavaClassException`
in interpreter-specific contexts) die with the interpreter.

### `src/Utilities/` (413 lines, 4 files)

`Formatter`, `DebugTool`, `BinaryTool`. `DebugTool` is interpreter-only
(per-opcode debug logging) — cuts with interpreter. Others survive.

## Stack 2 — execution divergence map

For Stack 2 (bytecode → execution), the exact concerns where AOT and
interpreter diverge:

| Concern | AOT (survives) | Interpreter (cuts) |
|---|---|---|
| Bytecode → executable | `Aot/Compiler.php` + `Aot/Ir/{Builder,Lowerer}` (~3.7 kloc) emit PHP, eval | `Kernel/Mnemonics/_*` (205 files, 6.8 kloc) — opcode-by-opcode classes |
| Method dispatch | PHP-native: `Generated\X::method` or `$instance->method` | `Core/JVM/Invoker/Extended/JavaMethodCallable.php` (~250-line dispatch loop) |
| Receiver shape | `\PHPJava\Aot\Generated\<X>` PHP instance | `JavaClass` PHP object with internal field map |
| Field state | PHP property (`$obj->fieldName`) | Internal map via `JavaDynamicField::get/set` |
| Operand stack | doesn't exist (lowered to direct expressions, or `$stack`/`$sp` flat array) | `$stacks` array manipulated by mnemonic execute methods |
| Local storage | `$L` PHP array, fixed-shape from descriptor | `$localStorage` PHP array, dynamically grown |
| Static initializer trigger | emit explicit `__staticConstruct` call at first use | `callStaticInitializerIfNotInstantiated` lazy on `getStatic()` |
| Type wrapping | none — raw PHP scalars per CONTRACTS.md §1 | `Char_`, `Long_`, `Double_` boxing; `convertPHPTypeToJavaType` |
| Exception handling | try/catch lowered into PHP control flow | ExceptionTable walker inside the dispatch loop |
| invokedynamic | compile-time emit for whitelist + IndyRegistry runtime hook | per-invocation `_invokedynamic` mnemonic |

## Total deletable surface, post-AOT-only

| Subsystem | Lines | Reason |
|---|---|---|
| `src/Kernel/Mnemonics/` | 6,823 | interpreter opcodes |
| `src/Kernel/Types/` (remainder of #12) | 568 | runtime boxing wrappers |
| `src/Kernel/Filters/Normalizer.php` | 122 | autobox normaliser |
| `src/Kernel/Frames/`, `Variables/`, `Provider/` + `JVM/Cache/` | ~520 | interpreter scaffolding |
| `src/Core/JVM/Invoker/Extended/JavaMethodCallable.php` interpreter loop | ~250 | the 372-line dispatch loop's interp half |
| `src/Compiler/Lang/Assembler/` | 5,847 | PHP→bytecode toy |
| `src/Compiler/Emulator/` | 3,529 | type tracker for the toy |
| `src/Compiler/Builder/` | 3,228 | ClassFile data model — no remaining users post-cut |
| `src/Compiler/Compiler.php` (legacy front-door) | ~150 | legacy compile entry |
| `tests/Cases/Compiler/` (3 tests + base) | ~500 | tests for the legacy stack |
| `tests/Cases/OutputDebugTraceTest.php` + template | ~100 | interpreter-trace test |
| **Total** | **~21,600 lines** |  |

Matches `docs/PATTERNS.md`:557 estimate ("~20kloc removed, ~5kloc
added"). The "5 kloc added" is the AOT pipeline — already landed
(`src/Aot/` = 4.8 kloc).

## Order of cuts (operationally)

1. **Phase A — flip routing.** `JavaClass::load` always routes through
   `Loader::loadClass`. Drop `PHPJAVA_AOT_MODE=lazy` env gate.
   `Loader::tryCallStatic` becomes `callStatic` — no silent fallback.
   Run suite. Triage failures (each is an AOT bug, an interpreter-
   internal test, or a real architectural gap).
2. **Phase B — receiver-shape unification.** `->construct()` returns
   `\PHPJava\Aot\Generated\<X>` instances. `->getDynamic()->getMethods()
   ->call(...)` and `->getFields()->get/set(...)` route via PHP-native
   dispatch on those instances.
3. **Phase C — rename pass.** "Dynamic → Instance" in active code per
   the table above.
4. **Phase D — interpreter delete.** `Kernel/Mnemonics/`, the
   interpreter half of `JavaMethodCallable::call`, `Kernel/Types/`
   (remainder), `Filters/Normalizer.php`, `Frames/`, `Variables/`,
   `Provider/`, `OperationCache`, `MnemonicResolver`,
   `OutputDebugTraceTest`. Single PR, single revert if anything
   surfaces.
5. **Phase E — legacy stack delete.** `Compiler/Lang/Assembler/`,
   `Compiler/Emulator/`, `Compiler/Builder/`, `Compiler/Compiler.php`,
   `tests/Cases/Compiler/*`. Independent of the AOT path; can land
   in parallel or after.

Each phase produces a green suite before the next starts. Phases C–E
are independent and parallelisable in practice.
