# JDK Version Gap Analysis

> Analysis date: 2026-05-01.
> PHPJava ceiling: class file version 63 (Java 19), per `src/Kernel/Resolvers/SDKVersionResolver.php:11–29`.
> Target horizon: Java 21 LTS (class 65) → Java 25 LTS (class 69).
> All claims below are rank 3 (read from PHPJava source) or rank 4 (recalled from JVMS / JEPs); marked accordingly.

---

## 1. What "supports up to Java 19" actually means

The ceiling is enforced in one place: `SDKVersionResolver::VERSION_MAP` only knows class file versions 45.3 → 63.0. A `.class` file with major version 64+ throws `UnknownVersionException` at load (the failure we saw in the test suite when JDK 25's `javac` produced version 69 fixtures).

But that ceiling is a **declaration** of support, not a guarantee. The actual feature surface PHPJava handles is narrower than "Java 19" suggests:

| Surface | What's implemented | Realistic ceiling |
|---|---|---|
| Bytecodes | 202 of 205 standard opcodes (rank 3, count from `src/Kernel/Mnemonics/_*.php`) | Java 7+ (post-`invokedynamic`) |
| Constant pool tags | 14 of 17 (rank 3, see §3 below) | Java 9 partial — Module/Package declared but `throw NotImplementedException` |
| Attributes | 24 handler classes (rank 3, `src/Kernel/Attributes/`) | Java 8 era — see §4 |
| Default attributes loaded | **5 only**: Code, Exceptions, SourceFile, InnerClasses, BootstrapMethods (rank 3, `Runtime.php:27–32`) | Other attributes are silently skipped |
| Standard library | ~80% of `Packages/java/*` is `NotImplementedException` (rank 3) | Java 8 era for what's filled in |

Reading the source carefully, **the practical ceiling is Java 8 with partial Java 9–11 features**, gated by a version table that says "19." Class files compiled by `javac --release 11` are the highest version that load and run reliably; everything between 12 and 19 likely loads but uses no features beyond Java 8.

---

## 2. Bytecode delta: Java 7 → Java 25

`[rank 4 — recalled from JVMS, not measured]`

The opcode set is essentially **frozen since Java 7** when `invokedynamic` (0xBA) was added. Subsequent JDKs have added **zero new bytecodes**. Every Java version since has added language features, class file metadata, and JVM runtime semantics — but the 256-entry opcode table is unchanged.

Implication: PHPJava already has the right opcodes (202 of 205 — the missing three are likely `breakpoint`/`impdep1`/`impdep2`, all reserved for VM internal use). **Bytecode is not the gap.**

---

## 3. Constant pool gaps

`[rank 3, from src/Kernel/Maps/ConstantPoolTag.php and src/Core/JVM/ConstantPool.php:73–107]`

Tags PHPJava handles:

| Tag | Name | Added in | Status |
|---|---|---|---|
| 1 | Utf8 | 1.0 | ✓ |
| 3 | Integer | 1.0 | ✓ |
| 4 | Float | 1.0 | ✓ |
| 5 | Long | 1.0 | ✓ |
| 6 | Double | 1.0 | ✓ |
| 7 | Class | 1.0 | ✓ |
| 8 | String | 1.0 | ✓ |
| 9 | Fieldref | 1.0 | ✓ |
| 10 | Methodref | 1.0 | ✓ |
| 11 | InterfaceMethodref | 1.0 | ✓ |
| 12 | NameAndType | 1.0 | ✓ |
| 15 | MethodHandle | 7 | ✓ (constant entry parsed; resolution incomplete — see §6) |
| 16 | MethodType | 7 | ✓ (same) |
| 17 | **Dynamic** | **11** | ✗ **Not declared in tag map; throws `Entry tag … is not defined`** |
| 18 | InvokeDynamic | 7 | ✓ (entry parsed; bootstrap resolution incomplete) |
| 19 | Module | 9 | △ Declared but `ConstantPool.php:105` throws `not implemented` |
| 20 | Package | 9 | △ Same — declared, throws |

**Three load-time blockers for modern class files:**

1. **`CONSTANT_Dynamic` (tag 17, JEP 309, Java 11).** Used by lambda metafactory generated code (and increasingly by record component descriptors, switch desugaring, etc.). Any class compiled with `--release 11` or higher can contain these, though `javac` only emits them in specific patterns. Class files for libraries that use modern lambda-rich code (most Clojure-compiled output, all Kotlin output) hit this immediately.
2. **`CONSTANT_Module` / `CONSTANT_Package` (tags 19, 20, Java 9).** Required to load any `module-info.class`. Without these, modular JARs can't be opened. Most modern libraries ship as modular JARs.
3. The constant pool reader's "is not defined" path means an unrecognized tag is fatal, not skippable. Even tags reserved for future use (Java has unused slots 2, 13, 14) trip this.

**Effort to fix:** ~200–500 LOC of PHP. The structure classes follow a uniform pattern (see `src/Kernel/Structures/InvokeDynamicInfo.php` as template); adding `DynamicInfo`, `ModuleInfo`, `PackageInfo` is mechanical. The hard part is what to *do* with `CONSTANT_Dynamic` resolution — it requires bootstrap method invocation, which loops back to invokedynamic completeness.

---

## 4. Class-file attribute gaps

`[rank 3, from src/Kernel/Attributes/*.php and JVMS attribute tables, recalled]`

PHPJava implements 24 attribute handlers. Default-loaded list is 5: `Code`, `Exceptions`, `SourceFile`, `InnerClasses`, `BootstrapMethods` (`Runtime.php:27–32`).

**Critical missing attributes for Java 11 → 25:**

| Attribute | Added in | Used by | Severity |
|---|---|---|---|
| **NestHost** | 11 | Nested class private access | High — affects any nested class with private cross-access |
| **Record** | 16 | Records (final since Java 16) | High — load-time fatal for any record class via the dynamic class-name lookup at `AttributeInfo.php:51` (`new $classAttributeName(...)` → class-not-found unless `LOAD_ATTRIBUTES` filters it out) |
| **PermittedSubclasses** | 17 | Sealed classes (final since Java 17) | High — same |
| **RuntimeVisibleTypeAnnotations** | 8 | JSR 308 type annotations (`@Nullable List<@NonNull String>`) | Medium — most code doesn't use these heavily |
| **RuntimeInvisibleTypeAnnotations** | 8 | Same | Medium |
| **Module** | 9 | `module-info.class` | High for modular JARs |
| **ModulePackages** | 9 | `module-info.class` | Same |
| **ModuleMainClass** | 9 | Modular runnable JARs | Medium |
| **LoadableDescriptors** | 25 (preview) | Project Valhalla preview | Low — preview only |
| **ImplicitCreation** | (preview) | Project Valhalla | Low |

**The dispatch model amplifies this:** `AttributeInfo.php:51` does `new $classAttributeName($this->reader)` where `$classAttributeName = '\\PHPJava\\Kernel\\Attributes\\' . $attributeName . 'Attribute'`. A class file with a `Record` attribute and no `RecordAttribute` PHP class triggers a fatal class-not-found unless that attribute name is filtered out via `LOAD_ATTRIBUTES`.

The default `LOAD_ATTRIBUTES = [Code, Exceptions, SourceFile, InnerClasses, BootstrapMethods]` (Runtime.php:27–32) protects against this for class files using only old features — anything outside the list is silently skipped, including `Record`/`PermittedSubclasses`. So a record class can *load* but its record-component metadata is invisible to reflection. Sealed classes have the same fate.

**Effort to fix:** Each new attribute is ~50–150 LOC (parse byte structure + accessors). NestHost, Record, PermittedSubclasses, Module trio = ~600–1200 LOC of PHP.

---

## 5. Standard library: API additions Java 11 → 25

`[rank 4 — JEP recall, not exhaustive]`

A class file compiled with `--release 21` may reference any of these classes/methods. PHPJava's `Packages/java/...` covers ~20% of Java 8 era surface; modern additions are 100% absent.

### Java 11 (LTS)
- `java.net.http.HttpClient` and friends (the new HTTP client) — `Packages/java/net/` exists but is partial; HttpClient is **absent**.
- `java.util.Optional.isEmpty()` — likely absent on existing `Optional_`.
- `String.repeat()`, `String.lines()`, `String.strip()`, `String.isBlank()` — almost certainly **absent** in `Packages/java/lang/String_.php` (which throws `NotImplementedException` for `indexOf`, the most basic method).
- `Files.readString()`, `Files.writeString()` — `java.nio.file` essentially absent.

### Java 14
- Records as preview (final in 16) — needs `java.lang.Record` base class + Record class file attribute.
- `instanceof` pattern matching (preview, final 16) — language feature, lowers to existing bytecodes mostly.
- Helpful NullPointerExceptions — JVM runtime detail; not a class-file or stdlib gap.

### Java 16
- `Stream.toList()` — terminal op shortcut.
- `Stream.mapMulti()` — added.
- DateRangeQuery, etc. in `java.time`.

### Java 17 (LTS)
- Sealed classes (final) — needs `Class.getPermittedSubclasses()`, `Class.isSealed()`.
- `RandomGenerator` API in `java.util.random` — entirely new package.

### Java 19–20
- Mostly preview features.
- `java.lang.foreign` (Foreign Function & Memory) — preview.

### Java 21 (LTS) — biggest stdlib delta after 11
- **Sequenced collections** (JEP 431): three new interfaces — `SequencedCollection`, `SequencedSet`, `SequencedMap`. Existing `List`, `Deque`, `LinkedHashSet`, `LinkedHashMap`, `SortedSet`, `SortedMap` retroactively implement them. Adds `addFirst`/`addLast`/`getFirst`/`getLast`/`reversed()` to many existing collections. **Most user code doesn't break, but library code that does generic-collection introspection might fail.**
- **Virtual threads** (JEP 444): `Thread.ofVirtual()`, `Thread.startVirtualThread()`, `Thread.isVirtual()`. Bytecode-level, virtual threads are still `Thread` instances; the difference is runtime scheduling.
- Pattern matching for switch (final).
- Record patterns (final).

### Java 22
- Foreign Function & Memory API (final) — `java.lang.foreign` package.
- Unnamed variables and patterns — language feature, lowers to existing bytecodes.
- Statements before `super(...)` — language feature.

### Java 23
- String templates removed (was preview, withdrawn).
- ZGC by default for collection.

### Java 24
- Class-File API (final) — new `java.lang.classfile.*` package.
- Stream gatherers (final) — `Stream.gather()`.
- Synchronize virtual threads without pinning — runtime.

### Java 25 (LTS, September 2025)
- Compact source files & instance main methods (final) — language/source-file change.
- Module import declarations (final) — language/source-file change.
- Flexible constructor bodies (final) — bytecode unchanged but constructor verifier rules relax.
- Scoped Values (final) — `java.lang.ScopedValue` class.
- PEM API (preview) — `java.security.spec.PEM*`.
- KDF API (final) — `javax.crypto.KDF`.
- Stable values (preview) — `java.lang.StableValue`.

---

## 6. invokedynamic / lambda metafactory

`[rank 3 + 4]`

This is the **single largest semantic gap**. Lambdas, method references, string concatenation (since Java 9!), record `equals`/`hashCode`/`toString`, and switch desugaring all funnel through `invokedynamic` with bootstrap methods. Without lambda metafactory support, almost no modern Java code runs.

PHPJava's state:
- `Kernel/Mnemonics/_invokedynamic.php` exists — opcode is parsed.
- `Kernel/Structures/InvokeDynamicInfo.php` exists — constant pool entry is parsed.
- `Kernel/Attributes/BootstrapMethodsAttribute.php` exists — bootstrap method table is read.
- `Packages/java/lang/invoke/` directory exists with `MethodHandles/`, `VarHandle/` subdirs, but the implementations are stubs.
- **Missing: actual bootstrap method invocation.** When `invokedynamic` is hit at runtime, PHPJava needs to invoke the bootstrap method (e.g., `LambdaMetafactory.metafactory`), which returns a `CallSite`, and then dispatch through that `CallSite`. The plumbing exists; the meat doesn't.

The `LambdaMetafactory` itself is non-trivial — it dynamically generates a class implementing the functional interface, with the lambda body as one method. In a real JVM that's done via `defineHiddenClass` + ASM. In PHPJava you can take a shortcut: synthesize a PHP closure or anonymous class on the fly.

**Effort to fix:** ~1–2kloc for a usable lambda metafactory in PHP, plus another ~500 LOC for `StringConcatFactory` (Java 9+ string `+` lowers to invokedynamic). This is a **prerequisite** for most things post-Java-8.

---

## 7. Runtime semantics gaps

`[rank 4]`

Beyond class-file loading, the JVM runtime semantics that newer code relies on:

| Feature | Added in | Status |
|---|---|---|
| `String` indy concat (`StringConcatFactory`) | 9 | ✗ — needs invokedynamic + factory |
| `Thread.ofVirtual` / virtual threads | 21 | ✗ — needs concurrency adapter (see ROADMAP Phase 4.5) |
| Sequenced collections | 21 | ✗ — collection interfaces need new methods |
| `ScopedValue` | 25 | ✗ — needs runtime support for thread-local-like scoping |
| `StructuredTaskScope` | 25 (preview) | ✗ |
| Records `equals`/`hashCode`/`toString` via indy | 16 | ✗ — same root issue as lambdas |
| Pattern matching switch | 21 | △ — desugars to existing bytecodes mostly; some `typeswitch` indy cases |

---

## 8. Pragmatic targets

### 8a. Java 11 LTS — the cheap win
This is what `javac --release 11` already produces and what the test suite is being moved to in Phase 1. **Already mostly works** for trivial class files. Gaps to close: `String.repeat`/`isBlank`/`strip`/`lines`/`indexOf` (and most String methods, currently stubbed). Realistic: 4–6 weeks after Phase 1 to make the standard JDK 11 surface run for non-lambda code.

### 8b. Java 21 LTS — the realistic ambition
Required to run any modern Clojure (1.12+ targets Java 11+ minimum and uses lambdas extensively for generated code). To load Java 21 class files:

1. Add `CONSTANT_Dynamic` (tag 17) — ~80 LOC.
2. Implement `CONSTANT_Module` and `CONSTANT_Package` parse + lookup — ~150 LOC.
3. Add `Record`, `PermittedSubclasses`, `NestHost`, `Module`, `ModulePackages`, `ModuleMainClass` attribute handlers — ~800 LOC.
4. Implement `LambdaMetafactory.metafactory` and `StringConcatFactory` — ~1500 LOC.
5. Update `SDKVersionResolver::VERSION_MAP` to add 53.0 → 65.0 — trivial.
6. Add `Thread.ofVirtual()` shim (deferred to concurrency adapter — Phase 4.5 of roadmap).
7. Fill `String_` to current JDK 21 surface — ~80 methods, ~1500 LOC.
8. Add sequenced collection interfaces (3 interfaces) and retrofit `LinkedHashMap`/`LinkedHashSet` — ~400 LOC.

**Total to load + run typical Java 21 code (excluding the 295 stub fills): ~5kloc of PHP.** This is a focused Phase 3 of the roadmap.

### 8c. Java 25 LTS — the stretch goal
Marginal additions on top of 21:
- Class file version 65 → 69 (table extension).
- Scoped Values runtime — likely needs Fiber-based context propagation when Swoole isn't present.
- KDF API, PEM API, Stable Values — each is a small class addition.
- Class-File API (`java.lang.classfile`) — large but not load-bearing for running existing code; only affects code that *itself* manipulates class files.

**Total marginal cost over Java 21: ~1.5–2kloc.**

The honest call is that **Java 21 LTS is the right target** for the project's stated goals. Java 25 LTS support follows once 21 is solid and stable. Skipping ahead to 25 buys little — the deltas are mostly preview-feature firms-up and runtime-internal improvements that don't affect what running code does.

---

## 9. Prioritized work list (HISTORICAL — superseded by ROADMAP §Next-work hierarchy)

> Preserved for prior context. Forward-work priority lives in
> [ROADMAP.md §Next-work hierarchy](../ROADMAP.md#next-work-hierarchy-post-2026-05-04-audit)
> as of the 2026-05-04 audit pass. Updates against this list:
>
> - Item 1 (version table) — **DONE** through Java 25 (class file 69.0).
> - Item 9 (StringConcatFactory) — **DONE in AOT path**
>   (`src/Aot/Ir/Builder.php:1675–1679`); interpreter stub remains.
> - Item 10 (LambdaMetafactory) — **DONE in AOT path**
>   (`src/Aot/Ir/Builder.php:1637–1640,1819–1851`); rank-1 verified
>   on `BenchLambda::run()=42` and `BenchLambda::withCapture(7)=107`;
>   interpreter stub remains.
> - Items 2–7 (constant/attribute parsers) — still open; tracked in
>   ROADMAP §Build T1 class-file gaps.
> - Item 8 (`String_` fill) — partial; AOT bootstrap.php has ~17
>   methods filled (`length`, `indexOf`, `charAt`, `substring`,
>   `equals`, `hashCode`, `trim`, `strip`, `startsWith`, `endsWith`,
>   `contains`, `replace`); missing `formatted`, `toLowerCase`,
>   `toUpperCase`, `format`, `join`, `intern`, etc.
> - Item 11 (sequenced collections) — still open.
> - Items 12–14 (Java 22–25 surface) — version table done; runtime
>   classes mostly absent.

Ordering by *enabling other work* + *probability of need*:

1. **Class file version table extension** — `SDKVersionResolver::VERSION_MAP` 53.0 → 65.0 (Java 9–21). 30 minutes.
2. **`CONSTANT_Dynamic` parsing** — new `DynamicInfo` structure + tag handler. 1–2 days.
3. **Module / Package constant parsing** — `ModuleInfo`, `PackageInfo`. 1 day.
4. **NestHost attribute** — required even for some Java 11 code. 1 day.
5. **Record attribute** — gates record class loading. 2 days.
6. **PermittedSubclasses attribute** — gates sealed class loading. 1 day.
7. **Module / ModulePackages / ModuleMainClass attributes** — gates module-info loading. 3 days.
8. **`String_` fill: indexOf, charAt, length, substring, equals, hashCode, isEmpty, contains, replace, split, trim, strip, repeat, lines, isBlank, formatted, toLowerCase, toUpperCase, startsWith, endsWith, valueOf, format, join, intern.** ~25 methods. 1 week.
9. **`StringConcatFactory.makeConcatWithConstants`** — for `+` on Java 9+ output. 3 days.
10. **`LambdaMetafactory.metafactory` + `altMetafactory`** — gates lambdas, method refs, switch typeswitch. 2 weeks.
11. **Sequenced collection interfaces + LinkedHashMap/Set retrofit.** 4 days.
12. **Class file version table 66.0 → 69.0** — Java 22–25.
13. **Scoped Values shim.** 1 week.
14. **Class-File API package — `java.lang.classfile.*`** — only if needed by deps. Defer.

Items 1–11 land us at Java 21 with running lambdas. That's the line where Clojure boot becomes possible (modulo concurrency adapter and stub fill from Phase 4 of the roadmap).

Items 12–13 take us to Java 25 LTS.

---

## 10. Caveats and unknowns

- `[rank 4]` All effort estimates are read-from-source intuition, not measured. Each item could be 2–3× larger if reflection, verifier, or class-loading edge cases bite.
- The 295 unfilled `Packages/` stubs (ROADMAP Phase 4) are orthogonal to this version-gap work but *both* are needed for Clojure boot. Version gap closes the **load-time** door; stub fill closes the **runtime resolution** door.
- The Java compiler's preview-class-file marker (minor version 65535) is unhandled. Class files with `--enable-preview` use that marker. PHPJava's `SDKVersionResolver` looks at major+minor as a string key (`"65.0"`); a preview file is `"65.65535"`, which won't match. Preview features are out of scope for this roadmap, so this is a tractable-and-explicit-error case rather than a silent miss.
- `[rank 5 — recalled, not verified against current OpenJDK 25 source]` Some Project Valhalla / value-class preview features use new attributes (`LoadableDescriptors`, `ImplicitCreation`, `Preload`). These are preview and outside this roadmap's target.

---

*This document is rank 3 + rank 4 evidence. Any claim here is a hypothesis pending rank 1 measurement. The Phase 0 baseline work is the next rank-1 producer; its results may revise estimates upward or downward.*
