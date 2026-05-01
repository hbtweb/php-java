# PHPJava Roadmap (hbtweb fork)

> Status: roadmap-stage. No code changes yet beyond unblocking the test suite on JDK 25.
> Forked from php-java/php-java at SHA `b34a90a` (last upstream activity 2021).
> Date written: 2026-05-01.

## Goal

A pure-PHP JVM implementation usable to run **Clojure** (initially) and a curated set of Java libraries on commodity PHP hosting (no extensions required at minimum, with optional acceleration via Swoole/opcache where available).

Concrete success line: a vanilla `clojure-1.13.0-slim.jar` boots to a `clojure.main` REPL prompt under PHPJava in under 60 seconds on a standard PHP-FPM host. Then: babashka-equivalent class surface (≈383 classes) covered well enough to run bb's compatibility list of pure-Clojure libraries.

## Falsifiers

If any of these prove true, the project re-scopes or stops:

1. **F1 — Perf floor.** After the dispatch-loop rewrite (Phase 2), per-bytecode cost remains > 1µs measured. At that floor a Clojure REPL boot stays > 30 minutes regardless of stub coverage. → re-scope to "Java-source AOT-compile to PHP" sibling project.
2. **F2 — Stub combinatorics.** After Phase 4, the stub-resolution dependency graph for `clojure.lang.RT.<clinit>` shows > 200 transitively required new classes beyond bb's 383 list. → narrow goal to "single-file Java utilities" tier and abandon Clojure target.
3. **F3 — Concurrency model mismatch.** `clojure.lang.Var`/`Atom`/`Agent` semantics cannot be reproduced on PHP's process model + Swoole atomics within a 2kloc concurrency adapter. → narrow goal as F2.
4. **F4 — Bridge cost.** A method call from cljp-compiled PHP into a PHPJava-loaded class measures > 50µs amortized after Phase 5. → keep PHPJava and cljp as independent runtimes, drop the bridge.

Each phase has its own exit gate. A failed gate triggers re-evaluation against the falsifiers above, not an automatic continuation.

## Current state (rank 3, from reading source 2026-05-01)

- Test suite: **476 tests, 813 assertions, 49 errors, 48 failures, 1 skipped** (~79% pass) after unblocking JDK 25 compatibility (`javac --release 11`) and disabling `stopOnFailure`.
- Failure clusters:
  - Type-wrapping (`Boolean`/`Char` returning `Int_`, `Float_` collapsed into `Double_`)
  - `Long` arithmetic (sub/mul wrong)
  - `OutputDebugTrace` constant-pool index drift (likely Java-version-related)
  - `java.io.PrintStream` char-printing prints int values
- Smoke test: `HelloWorld.class` (Java 11 release) prints "hello from phpjava" + `55` correctly. Trailing Symfony Console TypeError is a return-type API mismatch (`RunCommand::execute` returns null, Symfony 5 wants int) — cosmetic.
- Stub coverage: **295 of 366 files in `src/Packages/` reference `NotImplementedException`** — i.e., ~80% of the user-facing Java surface is unfinished.
- JDK ceiling: `Kernel/Resolvers/SDKVersionResolver.php:11–29` maps up to class-file version 63 = Java 19. Java 25 = class-file 69.
- Architecture: two-tier dispatch — bytecode interp for `.class` files (`Core/JVM/Invoker/JavaClass*`) and PHP-shim invoker for `Packages/java/...` (`Core/JVM/Invoker/PHPClass*` → `ReflectionMethod::invokeArgs`). Both reachable through one `InvokerInterface`. `INVOKEVIRTUAL` doesn't distinguish. Architecture is sound; execution is encrusted with accidental cost.

## Identified hot-loop accidental costs (rank 3, from reading)

Per JVM bytecode op in `Core/JVM/Invoker/Extended/JavaMethodCallable.php:174–260`:

| # | Cost | Location | Fix |
|---|---|---|---|
| H1 | `microtime(true)` every iteration for timeout safety | line 191 | Sample every N=1000 iterations |
| H2 | `new $fullName()` per opcode dispatch | line 226 | Pre-allocated opcode singletons keyed by opcode byte |
| H3 | `class_exists($fullName)` per iteration | line 213 | Resolved-class cache, populate once |
| H4 | 3-method setter chain on new opcode (`setConstantPool().setParameters().setDebugTool()`) | lines 229–245 | Pass via constructor or shared frame-state object |
| H5 | DI provider re-add of `OperandStacks`/`LocalStorages` per iteration | lines 175–178 | Set once outside the loop; mutate in place |
| H6 | `Int_::get()` / `Long_::get()` / `Double_::get()` boxing every arithmetic op | `Kernel/Mnemonics/_iadd.php:27` etc. | Keep PHP scalars on operand stack; box only when entering object slots |
| H7 | `Normalizer::getPrimitiveValue()` unbox every arithmetic op | mirror of H6 | Same fix |
| H8 | Two trigger-callable checks per iteration (`is_callable($beforeTrigger)` etc.) | lines 247–258 | Hoist outside loop when no triggers configured |

Per Java method invocation in `JavaMethodCallable::call`:

| # | Cost | Location | Fix |
|---|---|---|---|
| M1 | **`fopen(temp_file)` + `fwrite($code)` every method call** — bytecode written to disk so `BinaryReader` can stream it | lines 100–108 | Replace with byte-string pointer; keep `BinaryReader` interface or replace with `StringByteReader` |
| M2 | New `OperationCache`, new DI provider, new `BinaryReader` per call | lines 47, 121, 154 | Pool / reuse |
| M3 | Method-name beautification + signature reparse per call | line 80, `_invokevirtual.php:48` | Cache by method ref |
| M4 | Annotation lookup even when none exist | `_invokevirtual.php:79` | Skip when `getAnnotations()` returns empty cheaply |

M1 alone is the single largest accidental cost in the codebase. Every Java method invocation does real filesystem I/O.

## Phases

### Phase 0 — Validation baseline (next)

**Exit criterion:** measured per-op and per-method-call cost numbers in `bench/` for current `master`.

- Microbenchmarks: tight `iadd` loop (1M ops), tight `INVOKEVIRTUAL` to a no-op method (100k calls), HelloWorld end-to-end (already works).
- Profile under XDebug or vld to confirm the hot-cost ranking H1–H8 / M1–M4.
- Compare against HotSpot interpreted (`java -Xint`) on identical code for a reference ratio.
- Output: `bench/baseline-<sha>.json` with measured numbers, committed to the repo.

If actual baseline is meaningfully better than my read-only estimates predicted (~3–8µs/op), proceed. If it's worse, the cost model has unknowns to diagnose first.

### Phase 1 — Test suite to green

**Exit criterion:** 476/476 passing on JDK 11 release target, on PHP 8.4.

- Already done: `javac --release 11`, `stopOnFailure="false"`. To commit.
- Diagnose and fix:
  - `Boolean`/`Char` returning `Int_` (likely `TypeResolver::convertPHPTypeToJavaType` doesn't preserve boolean/char tags)
  - `Float_`/`Double_` collapse (likely `Normalizer` widens implicitly)
  - `Long` sub/mul (32-bit truncation somewhere)
  - `PrintStream.println(char)` printing int values (missing override)
  - `OutputDebugTrace` const-pool index drift (likely a Java-11 vs older bytecode shape)

These are bugs in the existing implementation — fixing them doesn't change the architecture. Each gets its own focused PR. Estimated 1–2 weeks.

### Phase 2 — Hot-loop rewrite

**Exit criterion:** per-bytecode op cost ≤ 200ns measured; HelloWorld ≤ 100ms total; test suite still green.

Address H1–H8, M1–M4 in order of measured contribution. Most impactful first (M1, then H2, then H6/H7 unboxing).

**Falsifier gate:** if after fixing all of H1–H8, M1–M4 the per-op cost is > 1µs measured (F1 above), pause and investigate. The interpreter model may be more expensive than analysis predicted; rewriting the dispatch as a single `switch` statement (no per-op class objects at all) is the next thing to try before declaring failure.

Estimated 4–8 weeks.

### Phase 3 — Modern JDK support

**Exit criterion:** loads class files compiled with `--release 17` and `--release 21`. Class-file versions 53–65 in `SDKVersionResolver`.

- Extend `SDKVersionResolver::VERSION_MAP`.
- Constant pool entries added in 7+ (`CONSTANT_MethodHandle`, `CONSTANT_MethodType`, `CONSTANT_InvokeDynamic`, `CONSTANT_Module`, `CONSTANT_Package`, `CONSTANT_Dynamic`).
- Records (16+), sealed classes (17+), pattern matching attributes — most are metadata, the bytecode shape is stable.
- The big one: `invokedynamic` with bootstrap methods. `Packages/java/lang/invoke/` exists but is mostly stub. Real lambda metafactory is needed for any code compiled with lambdas (most modern Java/Clojure). Order of magnitude: 1–2kloc of lambda metafactory + bootstrap method handling.

Estimated 3–5 weeks.

### Phase 4 — Stub fill: bb-equivalent surface

**Exit criterion:** the 383 classes/javax classes from babashka's `src/babashka/impl/classes.clj` allowlist are non-stub in `Packages/`. Coverage gauged by integration tests, not method-count.

Strategy:
1. Generate a shared class allowlist from bb's source.
2. For each class, port behavior from OpenJDK 21 source (LGPL→MIT compatibility check first; alternative is reading specs and writing fresh).
3. Order by Clojure boot dependency: `String`, `Class`, `Object`, `Throwable`, `System`, `Thread`, `ThreadLocal` first; then `java.io.*` Reader/Writer/PrintStream chain; then `java.util.*` collections; then `java.util.regex.Pattern`; then `java.util.concurrent.*`; then `java.lang.invoke` if not already done in Phase 3; then `javax.crypto`/`javax.net.ssl`/`java.net.http`.
4. Each class gets a focused integration test that exercises every method against a JVM-side oracle.

This is the bulk of the work. Realistic estimate: **6–18 person-months**, dominated by `java.io`, `java.util.regex`, `java.util.concurrent`.

### Phase 4.5 — Concurrency adapter

**Exit criterion:** `java.util.concurrent.atomic.AtomicReference` + `java.util.concurrent.locks.ReentrantReadWriteLock` work correctly under both pure-PHP fallback (process-local locking) and Swoole atomics (when extension present). `Thread.sleep`, `Thread.start`, `Thread.join` work on Fibers as a fallback.

Falsifier F3 lives here. If the adapter exceeds 2kloc or correctness can't be proven, narrow scope.

### Phase 5 — Clojure boot probe

**Exit criterion:** `clojure-1.13.0-slim.jar` reaches the `user=>` REPL prompt within 60s.

This is a probe, not a phase of work — it tests whether Phases 1–4.5 are sufficient. Failure here triggers stub-list expansion (back to Phase 4 for the missing classes) or perf re-evaluation (back to Phase 2).

### Phase 6 — cljp bridge

**Exit criterion:** cljp-compiled PHP can call methods on PHPJava-loaded classes and vice versa, with arg/return marshaling cost < 5µs per crossing. Falsifier F4 lives here.

The bridge: `Packages/PHPJava/cljp/` PHP classes that expose cljp PersistentVector/PersistentHashMap/Keyword as `clojure.lang.IPersistentVector`/`IPersistentMap`/`Keyword` to bytecode-running code. Likely shared underlying representation between cljp and PHPJava — the bridge becomes nominal.

### Phase 7 — Babashka-equivalent

**Exit criterion:** bb's compatibility list of pure-Clojure libraries runs unmodified on the PHPJava+cljp combo (with cljp AOT-compiling Clojure source where available, PHPJava interpreting `.class` files where not).

This is the long tail. Library-by-library validation, perf tuning, surface gap-fills.

## Open questions (require investigation, not commitment)

- **Q1: Compiler/Emulator/ tree.** `src/Compiler/Lang/Assembler/` is a Java-source-to-bytecode compiler in PHP, ~200 files. Useful as a fallback when the user only has source? Or scope ballast? Decision deferred until Phase 4.
- **Q2: Direct switch dispatch.** Replacing the `new $opcodeClass()` model with a single `switch ($opcode)` statement in the loop body — eliminates dispatch class allocation entirely. Possibly a Phase 2 follow-on if H2 alone doesn't bring per-op cost low enough.
- **Q3: AOT-compile bytecode → PHP.** A `.class` → `.php` compiler that emits one PHP method per Java method. This is the path that would actually approach native speed (vs ~10–50× HotSpot for an optimal interpreter). Architecture sibling of cljp. Likely the right answer for hot-path libraries but explicitly out of scope for this roadmap — would be a separate project that consumes PHPJava's class loader and surface emulation but skips the interpreter.
- **Q4: License compatibility for OpenJDK porting.** OpenJDK is GPL+CE, PHPJava is MIT. Porting OpenJDK source directly is an issue. Need to either re-implement from spec or get explicit clarification.
- **Q5: Symfony Console version.** PHPJava uses `symfony/console: ^5.2`; current 6.x and 7.x require `int` return from `Command::execute()`. Cosmetic but visible.

## Non-goals

- **Full OpenJDK parity.** No `java.awt`, `javax.swing`, `java.beans`, `org.w3c.dom`, JNDI, JAXB, javax.management.
- **Performance parity with HotSpot.** Best-case interpreted target is ~10–50× HotSpot interpreted. Acceptable for scripting/library use; unacceptable for hot loops.
- **Heavyweight Java frameworks.** Spring, Hibernate, Netty are out — wrong shape for PHP request lifecycle, perf-prohibitive.
- **JVM bytecode verification.** PHPJava trusts class files. Out of scope.

## Cadence

- Roadmap is a hypothesis. Each phase produces measured evidence; phases that fail their exit gate trigger re-evaluation against falsifiers above.
- Public progress: phase exit reports posted as PRs against this file with the relevant `bench/<sha>.json` numbers attached.
- Estimated minimum to Phase 5 (Clojure boot probe): 6–12 person-months, assuming Phases 1–4 land cleanly and no falsifiers fire.

---

*This document supersedes any earlier informal estimates ("30–50 classes", etc.) made before the architecture analysis on 2026-05-01.*
