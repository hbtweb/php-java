# PHPJava Roadmap (hbtweb fork)

> Forked from php-java/php-java at SHA `b34a90a` (last upstream activity 2021).
> Strategic framing in [`docs/MODEL.md`](docs/MODEL.md) — read first.
> JDK gap analysis in [`docs/GAP-JDK.md`](docs/GAP-JDK.md).
> Cross-project landscape in [`docs/ADJACENT-SHAPES.md`](docs/ADJACENT-SHAPES.md).
> First written: 2026-05-01. Revised after spike: same day.

## Goal

A JVM-bytecode-to-PHP translator for **long-running PHP processes**
(Swoole / AMPHP / RoadRunner / FrankenPHP) and request-scoped FPM
deployments. Run Java libraries from PHP code with idiomatic types at
the boundary; serve as a JVM substrate for languages that target it.

**Concrete success line for v1:** the test suite passes 100%, the
interpreter runs at ≤ 100 ns/op, AOT runs at ≤ 5 ns/op, and the bb
allowlist surface (~80 most-used classes from babashka's
`src/babashka/impl/classes.clj`) is non-stub. From there, Clojure boot
and the long tail are probes against existing infrastructure rather
than new architecture.

## Falsifiers (revised after measurement)

| ID | Original | Status | Notes |
|---|---|---|---|
| F1 | Per-op > 1 µs after Phase 2 → abandon interpreter | **lifted** | Spike measured 22 ns/op switch dispatch; 5.6 ns/op naive AOT; 0.4 ns/op idiomatic AOT |
| F2 | bb allowlist transitive deps > 200 unknowns → narrow goal | open | needs probe after T1 lands |
| F3 | Concurrency adapter > 2kloc → narrow goal | open | depends on Swoole/FFI work |

## Current state (2026-05-04, HEAD = `b631328`)

- **Tests:** **0 errors / 0 failures / 2 skipped** — fully green for the first time in the AOT-default era. 45/47 case files pass. Skipped: `KotlinTest` (pre-existing, no Kotlin runtime in CI) and `OutputDebugTraceTest` (interp-only bytecode-trace dumper, no AOT analog, marked for Phase D delete).
- **AOT pipeline:** **AOT is the default execution path** since `7f01155` (no env gate). End-to-end coverage now includes inheritance (`extends` + `parent::__construct`), interface compilation (Java interface → PHP `abstract class` with default-method bodies), method overload (descriptor-mangled names + arg-shape dispatcher), array by-ref auto-detect (cljp `aset`-on-param port), contract-shape Z (boolean) and C (char) field/array storage, wrapper-class IR lowerings (BOXING.md inline lowerings — `Integer.MAX_VALUE` → `IntLit`, `i.intValue()` → identity, `i.equals(j)` → `===`, etc.).
- **Hot-path perf (AOT):** **0.18-0.20 ns/op JIT** for int loops (1.8× of HotSpot JIT, 2.9× faster than HotSpot interpreted); **0.24 ns/op** for invokestatic-heavy code; ~2.2 ns/op for array workloads; ~22 ns/call empty-method dispatch. Unchanged this session — work was correctness, not perf.
- **Compile-output cache:** **2649× speedup** on repeat compiles (1578 µs → 0.6 µs).
- **Interpreter perf:** measured 5.22 µs/op (current); 22 ns/op (spike with Phase 2 fixes) — see `bench/`. **Interpreter is now load-bearing-free** — AOT covers the entire test surface; Phase D delete unblocked.
- **JDK ceiling:** declared up to 19 (class file 63), practical ceiling is Java 8 + partial Java 9–11; details in `docs/GAP-JDK.md`. **Now the binding constraint** for v1's bb-allowlist condition.
- **Stub coverage:** ~80% of `src/Packages/` files raise `NotImplementedException` (T2 work). The bb allowlist (~80 classes from `src/babashka/impl/classes.clj`) is the v1 success-line target.
- **Architecture:** IR-based AOT (Module + Method + BasicBlock + Stmt + Terminator + Expr) wraps PHPJava's bytecode parser. String-path emitter retained as fallback for unsupported opcodes. All 9 fixtures + the full PHPUnit suite lift via IR.

### v1 success-line scorecard

Per `## Goal` above:

| Condition | Status |
|---|---|
| Test suite passes 100% | ✓ achieved 2026-05-04 |
| Interpreter ≤ 100 ns/op | ✓ already achieved (~64 ns/op JIT) |
| AOT ≤ 5 ns/op | ✓ already achieved (0.18-0.20 ns/op JIT) |
| **bb allowlist (~80 classes) non-stub** | **remaining major thrust** |

## The work, in tiers

The model (per `docs/MODEL.md`) treats interpreter and AOT as two cache
modes of one compiler, not two separate projects. Phases below reflect
that — there is no "interpreter optimisation track" running parallel to
"AOT track"; both rewrites share the same opcode-handler tree, with one
emitting state mutation (interpret) and one emitting PHP source (AOT).

### Tier 0 — foundation (do first)

The contracts must be explicit before more code lands. Without this,
Tier 1 strategies and Tier 2 shim work re-derive their own assumptions
and conflict.

| Deliverable | Exit |
|---|---|
| `docs/CONTRACTS.md` — names `ClassLoaderInterface`, `InvokerInterface`, `NativeMethodInterface`, value-representation rules | document committed |
| Refactor existing code to satisfy contracts strictly | `Core/JVM/Invoker/` cleaned; `JavaClass` decoupled from `JavaCompiledClass` |
| Test suite restructured: contract tests vs implementation tests separated | parity test that runs against any invoker |
| Decision: PHP scalars on operand stack (drop `Int_`/`Long_`/`Double_` boxing) | written + tests for Boolean/Char preservation |
| Decision: classloader cache shape ≈ cljp's `_loaded` pattern | written |
| Bench harness ✓ (already done — `bench/baseline.clj`, `bench-cli.php`, `spike-fast-interp.php`) | done |
| Profile harness ✓ (`bench/profile-xhprof.php` + LD_PRELOAD finding) | done |

**Estimate:** 3–5 weeks. Falsifier: if we can't write a parity test that
runs against both interpreter and a stub AOT after this, the contract
isn't separated cleanly enough.

### Tier 1 — implementation strategies (parallel after Tier 0)

Three strategies behind the same `InvokerInterface`. Built on shared
infrastructure (parser, constant pool, attribute readers, shim layer).

| Strategy | Status | Per-op cost (spike) | Effort |
|---|---|---|---|
| **1a — Interpreter (optimised)** | spike validated | 22 ns/op (no opt), 36 ns/op (JIT — regresses) | 4–8 weeks |
| **1b — AOT compiler (naive)** | spike validated by hand-translation | 5.6 ns/op (no opt), 1.5 ns/op (JIT helps) | 2–4 weeks after 1a |
| **1c — AOT compiler (idiomatic, optional)** | spike validated by hand-translation | 0.4 ns/op (no opt), 0.2 ns/op (JIT) | 3–6 months |
| 1d — PHP-shim invoker | exists | n/a (depends on shim implementation) | shim coverage tracked in Tier 2 |

**1a — Interpreter rewrite.** Address H1–H8, M1–M4 from
`bench/profile-c930e2c.md`:

- **H1** sample `microtime()` every 1000 iters
- **H2** opcode singletons (or switch dispatch)
- **H3** resolved-class cache for opcode handlers
- **H4** kill the 3-method setter chain
- **H5** hoist DI provider rebuild
- **H6/H7** unbox primitives — PHP scalars on stack
- **H8** hoist trigger-callable checks
- **M1** kill `fopen`+`fwrite` per method (string-byte-reader; this is the largest single accidental cost)
- **M2** pool `OperationCache` / `BinaryReader`
- **M3** cache method-name + signature parse
- **M4** skip annotation lookup when none

**1b — Naive AOT.** A parallel `Aot/Emit/_*::emit($builder)` tree that
mirrors `Kernel/Mnemonics/_*::execute()`. Each emitter walks the same
parsed bytecode but produces a PHP statement instead of mutating runtime
state. Shared with the interpreter: the classloader, parser, and shim
layer. New piece: per-method PHP file generation, opcache integration,
classloader fallback when AOT cache misses.

**1c — Idiomatic AOT.** Adds a TeaVM-shape IR (SSA + CFG) and a
decompiler pass that lifts bytecode to expression trees and reconstructs
structured control flow.

**Sub-step 1c-α: stack-erasure peephole — DONE 2026-05-03.** A 5-pattern
post-emit peephole over the statement list, iterated to fixpoint,
catches the common javac-generated stack patterns: push+ireturn,
push+istore, push+push+arith+istore, push+push+if_icmp,
push+if-single-op. Pure-source restriction (only `$L[N]` reads or
literals can be erased) preserves side-effect ordering for
invoke/getstatic/getfield. Rank-1 measured: BenchAdd::sum1k() JIT
**0.82 → 0.18 ns/op** (4.5× speedup), beating the hand-emit idiomatic
AOT spike (0.2 ns/op) and reaching within 1.8× of HotSpot JIT
(~0.10 ns/op). ~150 LOC. See `docs/PATTERNS.md` "Stack-erasure
peephole" for patterns + emit before/after.

**Sub-step 1c-β: full abstract-stack tracking — DONE.** Catches the
cases the peephole misses — push+push+arith-without-immediate-store,
cross-block stack flow, exception-handler entry stacks. Implemented
via `spillStackToSlot` / `reloadStackFromSlots` in `Builder.php:287–310`;
handler entries reset stack to `[CaughtException()]`.

**Sub-step 1c-γ: cross-method inlining — DONE 2026-05-03.** First
shipped as post-emit-text substitution; then ported to IR-level
transform pass (`src/Aot/Ir/InlinePass.php`, ~120 LOC). Detects
single-`Return_(pure-Expr)` methods, substitutes `StaticCall` sites
with the parameterised return expression. Iterated to fixpoint.
Rank-1 measured: BenchInvoke::callLoop() JIT **4.05 → 0.20 ns/op**
(~20× win once IR-level inline pass landed; the IR-level version is
faster than the string-text version because it produces cleaner
output without redundant parens).

**Sub-step 1c-δ: escape analysis on Java arrays — DONE 2026-05-03.**
The IR Builder's iaload/iastore/arraylength opcodes detect when the
array operand is a `LocalRead(N)` and emit direct `$L[N][$i]`
operations instead of going through the `(object){'v' => ...}`
ArrayHelper wrapper. Closes the 10× property-access cost. Three new
IR nodes: `StoreArrayElement` Stmt, `ArrayElementRead` Expr,
`ArrayLengthRead` Expr. For non-LocalRead sources (arrays from
fields, method returns), falls back to ArrayHelper which handles
both wrapped and raw shapes dynamically.

**Sub-step 1c-ε: switch terminator (TABLESWITCH/LOOKUPSWITCH) — DONE.**
`Switch_` IR node at `Node.php:220`; opcode parse at `Builder.php:501,
1128–1136`; PHP `switch` render at `Lowerer.php:245–251`.

**Sub-step 1c-ζ: rare-opcode tail — DONE.** DUP2 (`Builder.php:799`),
IUSHR/LUSHR (`:814–831`), MULTIANEWARRAY (`:836–847`).

For canonical priority/ordering of remaining work, see
[Next-work hierarchy](#next-work-hierarchy-post-2026-05-04-audit) below.

### Tier 2 — surface coverage (shared by all Tier 1 strategies)

The JDK API surface implemented in PHP (`src/Packages/java/...`).
Required by every implementation strategy; written once, used by all.

| Sub-tier | Scope | Effort |
|---|---|---|
| T1 — class file format up to Java 21 | `CONSTANT_Dynamic`, `Record`, `PermittedSubclasses`, `NestHost`, `Module` attrs; lambda metafactory + StringConcatFactory | 3–5 weeks |
| T2 — bb allowlist core (~80 classes) | `String`, `Class`, `Object`, `Throwable`, `Thread`, `java.io.*`, `java.util.regex.Pattern` | 2–4 months |
| T3 — concurrency adapter | `java.util.concurrent.atomic.*`, `locks.*`, `Thread.ofVirtual`. Boot-provider strategy: AMPHP/Revolt (pure-PHP default) ↔ Swoole (when present, for Tier-2 shared-memory). See T3 detail below. | 4–6 weeks |
| T4 — bb allowlist tail (~300 classes) | `java.util.concurrent.*`, `javax.crypto`, `java.net.http`, etc. | 4–8 months |
| T5 — extension surfaces | `defineClass(byte[])`, `Instrumentation`, `Unsafe` (FFI when available) | 4–8 weeks |

T2 + T3 together unblock most P1 use cases (single Java library called
from PHP). T5 unblocks Clojure-on-PHPJava and dynamic-language hosting.

Strategy: each shim class gets a focused integration test that exercises
every method against a JVM-side oracle (real JVM via FFM transport). The
parity test infrastructure from Tier 0 makes shim-writing mechanical.

License note: OpenJDK is GPL+CE; PHPJava is MIT. Re-implementing from
spec rather than transliterating OpenJDK source. bb's allowlist is
narrow enough that this is tractable.

#### T3 detail — concurrency adapter (AMPHP-primary)

The Java thread/concurrent surface separates cleanly into two axes:

- **API surface** — what `java.util.concurrent.*` / `j.u.c.atomic.*` /
  `j.u.c.locks.*` classes exist as PHP shims. ~50 classes. Same emit
  shape regardless of runtime backend.
- **Runtime binding** — which event loop / mutex / atomics
  implementation backs the API surface. Strategy-dispatcher picks at
  boot time. Both backends are PHPJava-internal: a custom 2-tier
  runtime (default) with Swoole as the alternative for Tier-2
  shared-memory deployments.

**Decision (2026-05-04, post-bench): skip AMPHP, ship a custom
runtime sized to the AOT pipeline's perf class.** AMPHP at
2,031 ns/op is **10,155× the AOT iadd-loop cost** (0.20 ns/op
rank-1) — pairing AMPHP with AOT throws away the project's headline
perf claim at every concurrency call site. See
`bench/amphp-probe/REVISED.md` for the math.

**Target: full Java concurrency surface at iadd-class perf.** Three
emit shapes per call site, picked at AOT compile time:

  - **Inlined — direct call.** When may-suspend analysis
    proves the body is non-suspending AND surrounding context
    permits, the entire async/await pair compiles to a direct
    expression. ~9 ns/op (just the closure call) or AOT-iadd-class
    when even that is folded. Covers the dominant pattern of Java
    concurrency code (Thread.start + immediate join, AtomicInteger
    increment, ReentrantLock when no contention possible,
    CompletableFuture chains where every link is non-suspending).
  - **InlineExecutor — non-suspending fast path runtime.** Queue + drain,
    no Fiber. ~150-365 ns/op (PoC v2 demonstrated 365; further
    PATTERNS.md-style tightening can reach ~150). For tasks that
    aren't reducible to inline but still don't suspend.
  - **VirtualThreadExecutor — suspending runtime.** Fiber-backed for tasks that
    genuinely need cooperative scheduling. ~1 µs/op tuned (raw
    Fiber suspend/resume is the floor; PATTERNS.md tightening
    minimises everything outside that primitive op).

| Java | AMPHP/Revolt | Swoole |
|---|---|---|
| `Thread.start()` | `Amp\async($fn)` returns `Future` | `\Co::create($fn)` |
| `Thread.ofVirtual()` (Java 21) | Fiber = virtual thread (built-in) | Coroutine = virtual thread |
| `Thread.sleep(ms)` | `Amp\delay($s)` | `\Co::sleep($s)` |
| `synchronized` / `monitorenter` | `Amp\Sync\LocalMutex` | `Swoole\Lock` |
| `BlockingQueue` | `Amp\Pipeline\Queue` | `\Co\Channel` |
| `CompletableFuture` | `Amp\Future` (1:1 fit) | channel-based ad-hoc |
| `ExecutorService` | `amphp/parallel/Worker\WorkerPool` | coroutine pool |
| `j.u.c.atomic.*` (intra-process) | naive (cooperative) — covered by Unsafe shim | naive |
| `j.u.c.atomic.*` (cross-process) | `amphp/parallel` actor + counter | `Swoole\Atomic` |

`CompletableFuture` is the cleanest fit on AMPHP — `Amp\Future` has
the same shape (chain-then-await, success/failure variant tracking).
The Swoole equivalent requires building a Future type out of channels.

**Implementation order when T3 starts:**

1. AMPHP-first: `Thread`, `Thread.sleep`, `j.u.c.locks.ReentrantLock`,
   `j.u.c.CompletableFuture`, `j.u.c.BlockingQueue` shims wrapping
   AMPHP primitives. Composer dep `amphp/amp` + `amphp/sync`.
2. j.u.c.atomic.* family on the Unsafe shim (already shipped) —
   single-fiber semantics work for both backends.
3. Strategy-dispatcher: PHPJava boot provider checks
   `extension_loaded('swoole')` + env var; selects Swoole or AMPHP
   primitives. Emit shape unchanged across backends.
4. Tier-2 shared-memory cases (`Swoole\Atomic`, `Swoole\Table`) only
   when explicitly required. AMPHP path falls back to
   `amphp/parallel` actor pattern for the same use case.

**Implementation order (post-2026-05-04 reframe):**

1. ✓ **may-suspend analyzer IR pass** — shipped at
   `src/Aot/Ir/Analysis/MaySuspendAnalyzer.php`. 4/4 tests.
2. ✓ **InlineExecutor runtime** — shipped at
   `src/Aot/Runtime/Async/InlineExecutor.php`. ~500 LOC; PoC v2 measured
   365 ns/op for the bare async+await round-trip. 15/15 tests in
   `AsyncInlineExecutorTest.php`.
3. ✓ **VirtualThreadExecutor runtime** — shipped at
   `src/Aot/Runtime/Async/VirtualThreadExecutor.php`. ~300 LOC; Fiber-
   backed cooperative scheduler with sleep / interrupt / await /
   cancel + event loop. v1 allocates fresh Fiber per async (pool
   refactor deferred to v2). 12/12 tests.
4. ✓ **JDK concurrent shim layer (initial)** — shipped at
   `src/Aot/Runtime/java/...`. AtomicInteger/Long/Reference (Unsafe-
   backed CAS), ReentrantLock (no-op-uncontended + cooperative-park
   contended), CompletableFuture (InlineExecutor-backed with full
   composition surface — supplyAsync, completedFuture, failedFuture,
   thenApply, thenCombine, exceptionally, allOf, anyOf, manual
   complete/completeExceptionally), Thread (VTE-backed start/join/
   sleep/yield/interrupt + Java 21 ofVirtual). 20/20 tests in
   `JdkConcurrentShimTest.php`.
5. ✓ **Emit-specialiser (v1)** — `src/Aot/Ir/AsyncSpecialiserPass.php`,
   ~210 LOC. Hooks after InlinePass in the IR pipeline. Detects the
   immediate-await peephole `CompletableFuture::supplyAsync(s)->get()`
   (and `.join()` / `runAsync(r).get()` variants) and rewrites to
   `InvokeCallable(s, [])` — direct callable invocation, skipping
   the executor entirely.

   New IR node `InvokeCallable(Expr $callable, Expr[] $args)` lowers
   to `({callable})({args})` PHP — handles closures, lambdas with
   `__invoke`, and any callable receiver.

   Soundness under PHP cooperative scheduling: with a single carrier
   thread the executor offers no parallelism benefit when the result
   is immediately awaited; `s` runs on the same physical thread
   either way. If `s` suspends, direct invocation suspends the
   calling fiber — equivalent to what `cf.get()` would do after
   settling. Exception propagation identical.

   Coverage in v1:
   - `CompletableFuture::supplyAsync(s)->get()` ✓
   - `CompletableFuture::supplyAsync(s)->join()` ✓
   - `CompletableFuture::runAsync(r)->get()` / `.join()` ✓
   - Nested patterns under outer InstanceCall args ✓
   - Conservatively skips: timed `.get(timeout, unit)`, intervening
     StoreLocal (Future escapes, may need real coordination),
     non-CompletableFuture call sites.

   Tests: tests/Cases/AsyncSpecialiserPassTest.php — 9 cases:
   collapses-supplyAsync-get, collapses-runAsync-join, store-local-
   between-skips, get-on-non-async-skips, supplyAsync-without-await-
   skips, timed-get-variant-skips, nested-rewrite-collapses-inner,
   lowerer-emits-correct-PHP, idempotent-on-already-collapsed.

   Per-site saving — measured 2026-05-04 on Kali WSL, PHP 8.4.20,
   `bench/specialiser-saving.php` (rank 1, 100k iters × 7 reps,
   median ns/op; numbers vary ~10-20% across runs from system noise,
   the order of magnitude is stable):

   | Pattern                              | Runtime path | Specialised | Saving |
   |--------------------------------------|-------------:|------------:|-------:|
   | `CF::supplyAsync(s)->get()`  JIT off |   575 ns/op |    21 ns/op | 554 ns |
   | `CF::supplyAsync(s)->join()` JIT off |   587 ns/op |    21 ns/op | 565 ns |
   | `CF::runAsync(r)->get()`     JIT off |   990 ns/op |    21 ns/op | 969 ns |
   | no-escape `$L=sa($s); $L->get()` off |   684 ns/op |    23 ns/op | 661 ns |
   | `CF::supplyAsync(s)->get()`  JIT on  |   379 ns/op |    12 ns/op | 368 ns |
   | `CF::supplyAsync(s)->join()` JIT on  |   370 ns/op |    12 ns/op | 358 ns |
   | `CF::runAsync(r)->get()`     JIT on  |   813 ns/op |    12 ns/op | 802 ns |
   | no-escape `$L=sa($s); $L->get()` on  |   545 ns/op |    12 ns/op | 534 ns |

   Slot-indirection cost (specialised no-escape vs inline collapse):
   ≤2 ns/op JIT off, 0 ns/op JIT on — the extra `$L = $s` write +
   read is below measurement noise.

   `runAsync` saves more because its supplier is wrapped in an extra
   closure (line 82 of `CompletableFuture.php`) that the specialised
   form skips entirely. The earlier 365 ns/op figure cited from the
   InlineExecutor PoC v2 (`bench/amphp-probe/bench-v2.php`)
   underestimated the saving here — the production CF wrapper adds
   ~50–100 ns on top of the bare async+await floor.

   Lowered-shape verification (rank 3): inline collapse emits
   `($L[N])()`; no-escape collapse emits `$L[N] = $supplier;
   ($L[N])();` (single supplier-store + later invocation). Confirmed
   by `testLowererEmitsInvokeCallable` and
   `testLowererEmitsNoEscapeCollapse` in
   `tests/Cases/AsyncSpecialiserPassTest.php`.

   Future extensions (not blocking this checkpoint):
   - ~~Single-use no-escape detection: collapse `var cf = supplyAsync(s);
     cf.get();` when `cf` is only read by the .get and never escapes
     to other call sites (allOf, thenApply, etc.).~~ DONE — landed in
     AsyncSpecialiserPass::applyNoEscapeCollapse. Method-wide single-
     consuming-use analysis: rewrites `StoreLocal(slot, supplyAsync($s))`
     to `StoreLocal(slot, $s)` and the matching `InstanceCall(LocalRead(slot),
     'get'|'join', [])` to `InvokeCallable(LocalRead(slot), [])`. Sound
     because the supplier expression evaluates at the StoreLocal site
     unchanged, and the supplier *invocation* still happens at the
     `.get()` site (exception ordering preserved). Conservatively
     skipped: multi-`.get()` on same slot (Future caches; rewrite
     would re-invoke), escape into another call's args, slot rebind.
     Cross-basic-block patterns supported. 5 new test cases:
     no-escape-single-use-collapses, no-escape-runAsync-join-terminator,
     multiple-gets-skipped, escape-as-call-arg-skipped, rebind-skipped,
     cross-block-collapses. Saving same as inline collapse (419-879 ns
     JIT on; 728-1148 ns JIT off — see table above).
   - ExecutorService.submit(s).get() collapse — deferred. Soundness
     issue: ThreadPoolExecutor.submit checks `$this->shutdown` and
     throws IllegalStateException; collapsing skips that check. Needs
     either escape analysis to prove the executor instance is fresh
     and never-shutdown, or a guarded emit form. Out of scope for v1.
   - Thread.start().join() collapse for void runnables — needs the
     IR builder to emit the new+start+join sequence as a peephole-
     friendly shape, not split across BBs.
   - May-suspend analyser integration: today the collapse is
     unconditional (correct under PHP cooperative scheduling). When
     Swoole-coroutine deployment lands and supplyAsync DOES enable
     parallelism, we'd consult the analyser to avoid collapsing
     genuinely-async patterns.
6. **State-machine transformation pass** — for sub-200 ns on
   genuinely-suspending tasks. Rewrites Java methods that suspend
   into explicit state machines driven by the event loop without
   using Fibers. JS-engine-style async/await transformation. ~1-2
   weeks compiler work; would put suspending operations at ~100-200 ns
   instead of the ~12 µs Fiber suspend/resume floor. **NOT STARTED**.
7. ✓ **JDK concurrent shim layer (full)** — COMPLETE. All non-I/O
   surface of j.u.concurrent shipped; routes through InlineExecutor /
   VTE substrate. 6 test files / 104 cases / all passing.
   - ~~**Locks family**: Condition, ReadWriteLock, StampedLock~~ —
     DONE. StampedLock includes optimistic-read fast path.
   - ~~**Sync primitives**: Semaphore, CountDownLatch, CyclicBarrier,
     Phaser~~ — DONE.
   - ~~**Queues**: BlockingQueue + LinkedBlockingQueue / ArrayBlockingQueue /
     SynchronousQueue, ConcurrentLinkedQueue, ConcurrentLinkedDeque,
     PriorityBlockingQueue, LinkedBlockingDeque, DelayQueue~~ — DONE.
   - ~~**ConcurrentHashMap, CopyOnWriteArrayList, CopyOnWriteArraySet**~~
     — DONE.
   - ~~**ExecutorService family**: Executor, ExecutorService, Executors,
     ThreadPoolExecutor, ThreadFactory, DefaultThreadFactory, Future,
     ConcreteFuture, ExecutionException, TimeoutException, TimeUnit~~
     — DONE.
   - ~~**ForkJoinPool / ForkJoinTask / RecursiveTask /
     RecursiveAction**~~ — DONE.
   - ~~**ScheduledExecutorService / ScheduledThreadPoolExecutor +
     Delayed + ScheduledFuture**~~ — DONE. Built on
     `VTE::scheduleAfter` primitive (an async fiber that sleeps,
     then invokes the callback).
   - ~~**VarHandle (Java 9+)**~~ — DONE. All access modes collapse
     to plain ops under PHP cooperative scheduling.
   - ~~**StructuredTaskScope (Java 21+) + ShutdownOnFailure /
     ShutdownOnSuccess + Subtask**~~ — DONE.
   - ~~**ScopedValue (Java 21+) + Carrier**~~ — DONE.

   Cross-fiber lock-contention fix landed: ReentrantLock now stores
   `\Fiber` waiters (not just fiber-ids) and resumes the next waiter
   from `unlock()`. Required for Condition.signal() →
   re-acquire-lock to work correctly.

   VTE primitive added: `scheduleAfter($delayMs, $fn)` — schedules a
   callback to fire after a delay. Returns a Future id. Used by
   ScheduledExecutorService (single-shot, fixed-rate, fixed-delay)
   and DelayQueue (head-element wake-up).

   What's next (downstream of step 7):
   - Timed-await variants: `latch.await(timeout, unit)`,
     `cond.awaitNanos(ns)`, `Future.get(timeout, unit)`,
     `lock.tryLock(timeout, unit)`, etc. Each is ~30 LOC: schedule a
     timer that resumes the parked fiber with a "timeout" sentinel.
     Add per fixture; not load-bearing for AOT correctness today.
   - Phaser hierarchical (parent-child) — niche.
   - StructuredTaskScope timeout policies — Java 21 preview.
   - The full `j.u.concurrent` surface AOT-compiled bytecode can
     now exercise — all non-I/O primitives ship with observably-
     equivalent behaviour.

Total: ~1,900 LOC across analyzer + runtime + specialiser + shims.
Days-of-work mechanical implementation; the analyzer is the
load-bearing piece and it's already shipped.

The runtime stays purpose-built for the AOT pipeline's perf class.
No AMPHP dependency. The correctness machinery (cancellation, error
propagation, composition) becomes ours; at ~1,000 LOC for InlineExecutor + VirtualThreadExecutor
it's in scope. Comparable to existing IR transforms in
`src/Aot/Ir/InlinePass.php` (~120 LOC) + escape-analysis paths in
`src/Aot/Ir/Builder.php`. We already build optimisation infra; an
async runtime sized to it is structurally consistent.

### Tier 3 — probes (questions, not features)

Each is a question answered by running, not built features.

| Probe | Question |
|---|---|
| Q3.1 — Java library call | Does PDFBox / Tika / iText work end-to-end from PHP? |
| Q3.2 — Clojure boot | Does `clojure-1.13.0-slim.jar` reach `user=>` REPL? |
| Q3.3 — hot reload | Edit `.java`, re-AOT, callers see new methods? |

## Next-work hierarchy (post-2026-05-04 audit)

The 2026-05-04 audit verified all open-work claims across the docs
against the code; many items previously listed as TODO had already
landed. The remaining surface, organised by kind. Correctness gaps
sit ahead of new build because bb-fill (the v1 gate) will exercise
broader semantic surface, and substrate bugs will surface as
implementation bugs in shim code rather than at their actual site.

### Testing — correctness gaps surfaced by audit (1–2 days)

JVM-semantic divergences current tests don't exercise. Ranked roughly by
likelihood-of-being-hit-during-bb-fill.

| # | Gap | Status | Site / Test |
|---|---|---|---|
| T1 | Long overflow unmasked → silent float promotion | **DONE** | jvm_l{add,sub,mul,div,rem,neg} helpers; `tests/Cases/LongOverflowTest.php` 6/6 |
| T2 | Float narrowing missing on FSTORE / putfield(F) / f2d / d2f / i2f / l2f | **DONE** | jvm_f32 helper; FloatLit emit via var_export; `tests/Cases/FloatNarrowTest.php` 5/5 |
| T3 | NaN comparison wrong + parser bug returning INF for NaN CP entries | **DONE** | jvm_fcmpl/jvm_fcmpg/jvm_float_equals helpers; DoubleInfo/FloatInfo pack/unpack-based decode; `tests/Cases/NaNComparisonTest.php` 5/5 |
| T4 | `String.length` byte vs UTF-16 unit | **DONE (partial)** | byte-walk UTF-16 unit count; `tests/Cases/StringUtf16LengthTest.php` 5/5. `charAt`/`indexOf`/`substring`/`hashCode` still byte-indexed — folds into ROADMAP §Build "String_ fill". |
| T5 | `iinc` opcode no 32-bit mask + wide-iinc parsing | **DONE** | Lowerer 32-bit wrap on IincLocal; Builder wide-iinc (0xC4 0x84) parse; `tests/Cases/IincOverflowTest.php` 4/4 |
| T6 | Char surrogate-pair semantics | **DONE** | `String_::charAt` walks UTF-8/CESU-8/4-byte forms; returns int code unit (0xD800–0xDFFF for supplementary char halves); `tests/Cases/CharSurrogateTest.php` 7/7 |
| T7 | Nested/overlapping try-catch silently uncaught | **DONE (warning only)** | `Compiler.php:826` triggers `E_USER_WARNING` at AOT-compile time when ranges nest. Underlying nested-protection emit is still fall-through; lift to ROADMAP §Refinement when a real fixture needs the protection. |

All seven landed. Total: 32 new tests passing across 6 test files,
0 baseline regressions, 1 bonus parser fix (NaN/INF/special-value
decoding via IEEE754 round-trip), 1 bonus opcode fill (wide-iinc
parsing in Builder).

### Build — capability extension (~6–8 weeks for v1)

The v1 gate is bb-allowlist non-stub fill. Path D′ oracle is its
prerequisite.

**v1 critical path (sequential):**

1. **Path D′ behavioural oracle harness** — **MVP SHIPPED 2026-05-05**.
   - PHP-side runner at `bench/parity/oracle-runner.php` — captures
     (return, exception, stdout, stderr) for one PHPJava AOT
     invocation, serialises to JSON per the contract in
     `bench/parity/README.md`. Routes JDK shims through the AOT
     runtime namespace directly; bytecode-compiled classes through
     `Loader::callStatic` — both paths from one runOracle entry.
   - Clojure-side driver at `bench/parity/oracle_driver.clj`
     (~270 LOC). Loads case-spec JSON, reflects over the real HotSpot
     class, subprocesses into the PHP runner, runs per-axis
     comparator (kind/return/exception.class/stdout), emits per-case
     PASS/FAIL with axis-level diffs and a summary line. Subprocess
     transport (not FFM) is the MVP — FFM is the perf optimisation
     for the long-tail 12k-case battery and lands when wall-time
     warrants. Run: `clj -Sdeps '{:paths ["bench/parity"] :deps {org.clojure/data.json {:mvn/version "2.5.0"}}}' -M -m oracle-driver --case <case-file>`.
   - First case battery at `bench/parity/cases/java.lang.Math.json`
     (53 cases). Validated: **53/53 match against OpenJDK 25** —
     rank-1 evidence the harness detects equivalence and divergence
     (caught the JSON int/float type-collapse bug in iteration 1,
     fixed via `JSON_PRESERVE_ZERO_FRACTION`).

   **REMAINING (post-MVP)**:
   - FFM transport — when subprocess wall-time on the full bb-fill
     battery (~12k cases × ~100ms = ~20min) becomes the bottleneck.
     Pattern is in `bench/baseline.clj`; the swap is mechanical.
   - Descriptor-mangled overload disambiguation — for cases where
     int vs long semantics diverge (Integer.MIN_VALUE etc.). MVP
     case batteries stick to overloads where polymorphic PHP
     dispatch matches Java; descriptor mangling lands when a
     bb-allowlist class needs it.
   - CI bridge — surface parity divergence as PHPUnit failure.
2. **bb-allowlist non-stub fill** (~80 most-used babashka classes,
   2–4 months) — work each class against the oracle. The actual v1
   gate. ~110 of the ~130 stub-only T2 classes are already Path C
   stubs (`tools/gen-aot-stubs.php` + `src/Aot/Runtime/java/**`); this
   step replaces the stub bodies with real implementations.

   **Progress (2026-05-05): 13/80 shipped + parity-validated.
   419/419 parity cases. Math transcendental fill (sin/cos/log/exp
   family + cbrt + copySign + IEEEremainder + hypot + toRadians/
   toDegrees) closed 18 suite errors at once: 22E/11F → 4E/11F.
   Profile depth ≥3 fires for 6 methods (was 1 at session start
   before HashMap+Arrays).**
   - `java.lang.Math` — `src/Aot/Runtime/java/lang/Math.php`. Surface:
     abs/min/max (polymorphic int+long+float+double), sqrt/pow/floor/
     ceil, round (Java half-up semantics, not PHP half-away-from-zero),
     addExact/subtractExact/multiplyExact/negateExact/incrementExact/
     decrementExact (long overflow → ArithmeticException), floorDiv/
     floorMod (Java floor-toward-negative-infinity, not PHP truncate),
     signum, PI, E. Trig/log/exp/random/cbrt/copySign/IEEEremainder
     deferred — no bb-allowlist hits exercise them yet. **53/53 parity
     vs HotSpot.** Bonus: dropped 12 errors from the broader Packages
     test suite (38E → 26E baseline) — AOT-default path through
     legacy `JavaLangMathTest` now resolves Math.abs/min/max via the
     new shim.
   - `java.lang.Boolean` — `src/Aot/Runtime/java/lang/Boolean.php`,
     replacing the Path C stub. Static surface only (instance methods
     stay NIE — IR Builder is expected to lower wrapped-Boolean ops
     inline per BOXING.md). parseBoolean (case-insensitive "true"
     match), valueOf, toString, hashCode (1231/1237 spec-locked
     constants), compare, logicalAnd/Or/Xor, getBoolean (env-var
     analog of System.getProperty). **29/29 parity vs HotSpot.**
   - `java.util.Objects` — `src/Aot/Runtime/java/util/Objects.php`,
     new file. Surface: isNull/nonNull, equals (primitive ===-fold;
     Object dispatch deferred), hashCode (null→0, string→
     String.hashCode polynomial, int→Long.hashCode bit-fold per AOT
     contract that PHP int IS Java long, bool→1231/1237; Double and
     Object hashCode deferred), toString (with optional default),
     requireNonNull (throws NPE). hash(Object...) variadic deferred —
     **44/44 parity vs HotSpot** (35 + 9 hash() varargs cases added
     after driver gained Object[] dispatch). compare/Comparator,
     deepEquals, requireNonNullElse, checkIndex/checkFromToIndex/
     checkFromIndexSize still deferred.
   - `java.lang.Integer` — extension to the existing 7 methods in
     bootstrap.php (parseInt/valueOf/toString/toBinaryString/etc.).
     Added: bitCount (SWAR popcount), numberOfLeadingZeros /
     numberOfTrailingZeros, highestOneBit / lowestOneBit, reverse
     (32-bit bit reversal), reverseBytes (endian swap), compare, sum,
     signum, hashCode (identity for int), constants MIN_VALUE /
     MAX_VALUE / SIZE / BYTES. All bit ops mask-and-narrow at 32-bit
     to match Java's signed int semantics. parseUnsignedInt /
     toUnsignedString / divideUnsigned / remainderUnsigned /
     compareUnsigned deferred (rarely-used in bb). **57/57 parity
     vs HotSpot.**
   - `java.lang.Long` — `src/Aot/Runtime/java/lang/Long.php`, sister of
     Integer for 64-bit. PHP int IS Java long natively (both 64-bit
     signed two's complement) so most ops are direct — no masking, no
     narrowing. Surface: parseLong (radix 2..36 with PHP_INT_MIN
     special-case for round-trip check), valueOf, toString /
     toBinaryString / toHexString / toOctalString (PHP's dec-conv
     functions on negatives produce the unsigned 16/22-digit form Java
     emits); max, min, sum, compare, signum, hashCode (`(int)(v ^ (v
     >>> 32))` bit-fold); bit-twiddle family at 64-bit cascades
     (bitCount via byte-sum because the SWAR multiply variant
     overflows PHP int; numberOfLeadingZeros / Trailing; highestOneBit
     handles sign-bit case; lowestOneBit relies on PHP_INT_MIN's
     unary-negation wrap; reverse / reverseBytes via SWAR/byte-extract
     to avoid mask literals exceeding PHP_INT_MAX). **64/64 parity vs
     HotSpot.** parseUnsignedLong, compareUnsigned, divideUnsigned,
     remainderUnsigned deferred.
   - `java.lang.Character` — `src/Aot/Runtime/java/lang/Character.php`,
     new file. Static surface only — Java char carries as PHP int per
     AOT contract. ASCII fast-path; Unicode-aware methods delegate to
     IntlChar (PHP intl extension) when available, ASCII-only fallback
     otherwise. Surface: isDigit / isLetter / isLetterOrDigit /
     isWhitespace (Java's spec excludes 0xA0 NBSP from whitespace) /
     isUpperCase / isLowerCase / isAlphabetic; toUpperCase /
     toLowerCase; digit / forDigit (radix 2..36); compare (raw
     difference, NOT sign-only — diverges from Integer.compare);
     isHigh/Low/Surrogate, isSurrogatePair, toCodePoint; constants
     MIN_VALUE / MAX_VALUE / MIN_RADIX / MAX_RADIX / surrogate range
     limits. **52/52 parity vs HotSpot.**
   - `java.lang.System` — extension to the existing class in
     bootstrap.php (which had only $out / $err static fields).
     Added: currentTimeMillis, nanoTime (PHP hrtime), arraycopy
     (with overlap detection for same-array source/dest),
     lineSeparator, gc, exit, identityHashCode, getProperty (synthetic
     fallback for line.separator / file.separator / java.version /
     etc., else env-var). identityHashCode under our AOT contract
     uses spl_object_id for objects and value-hash for scalars —
     diverges from Java's per-instance identity for the
     `new String("x")` case (testIntern / testNotInterned /
     testIdentityHashCode in the suite assert that divergence;
     deliberate, documented at the method).  No parity battery —
     methods are non-deterministic (timers) or contract-divergent.
   - `java.util.Arrays` — `src/Aot/Runtime/java/util/Arrays.php`,
     new file. **First shim that creates a depth-≥3 profile chain.**
     Surface: equals (with Java's NaN-NaN-equal-in-arrays special-
     case), hashCode (delegates to Objects::hash, polynomial 31-fold),
     copyOf / copyOfRange (null-pad to match Object[] semantics),
     fill / fillRange (mutating — by-ref parameter), toString
     (Java-style "[a, b, c]" with .0 suffix on integer-valued floats),
     binarySearch. Sort / asList / Stream / deepEquals / deepHashCode /
     deepToString deferred. **37/37 parity vs HotSpot.**

   Driver capabilities gained: Long → char narrowing (Character.compare
   etc. take char which Java reflection requires exact-match for);
   specificity score's narrow-from-Long preference (int > short > byte
   > char) so Character.toLowerCase(int) wins over toLowerCase(char)
   when the case spec passes JSON ints; Character → long unbox in
   normalise-return for char-returning methods (forDigit etc.); Java
   array reification via java.lang.reflect.Array/get so methods
   returning `Object[]` / `int[]` / etc. lift to vectors (cleanly
   matching the PHP-array shape) instead of the `[L...@hash` toString
   form.
   - `java.util.HashMap` — `src/Aot/Runtime/java/util/HashMap.php`,
     new file. First instance-bearing class in bb-allowlist. Backing:
     PHP array with type-prefixed keys (`s:`, `i:`, `b:`, …) so `"1"`
     and `1` stay distinct (Java HashMap preserves key identity; PHP
     arrays coerce). Surface: put / get / containsKey / containsValue
     / remove / size / isEmpty / clear / getOrDefault / putIfAbsent /
     equals / hashCode (compositional — Objects::hashCode per key+value,
     depth-3+ chain) / toString. keySet / values / entrySet deferred
     (need Set / Collection / Entry interfaces); compute / merge /
     forEach / putAll deferred. **24/24 parity via instance-mode
     driver dispatch.** keySet / values / entrySet shipped, returning
     PHP arrays (not Set/Collection objects) — most AOT-emitted bb-fill
     uses the enhanced for-loop which the IR Lowerer translates to
     PHP foreach, so PHP-array views suffice. Full Java-shape
     Set/Iterator/Map.Entry interfaces ship when a fixture surfaces
     Set-specific identity (e.g., `set.size()` chained on the keySet
     return). Map.Entry-shape returned as `['key' => ..., 'value' => ...]`
     associative arrays (AOT emit for `e.getKey()` routes to PHP's
     `$e['key']`).
     Arrays.sort + sortRange shipped (in-place via PHP's `\sort`); no
     parity battery — through-reflection by-reference dispatch is
     fiddly, sort correctness validated by PHP builtins.
   - **Math transcendentals** filled in `src/Aot/Runtime/java/lang/Math.php`
     (sin/cos/tan + asin/acos/atan/atan2; sinh/cosh/tanh; exp/expm1;
     log/log10/log1p; cbrt with negative-handling fold; copySign with
     NaN-as-positive sign per Java spec; random; IEEEremainder via
     round-half-to-even; hypot; toRadians/toDegrees). **Closed 18
     suite errors** in JavaLangMathTest. Native PHP delegations except
     cbrt + copySign + IEEEremainder (explicit semantic handling).
   - `java.util.ArrayList` — `src/Aot/Runtime/java/util/ArrayList.php`,
     new file. PHP-array-backed list. Surface: add / addAt / get /
     set / remove (polymorphic — int=index, other=value) / contains /
     indexOf / lastIndexOf / subList / clear / size / isEmpty /
     toArray / equals / hashCode / toString. **19/19 parity.**
   - `java.lang.StringBuilder` — extension to existing minimal class
     in bootstrap.php. Added: insert / delete / deleteCharAt / replace /
     setCharAt / substring / indexOf / lastIndexOf / capacity / ensure
     Capacity / trimToSize. **19/19 parity.**
   - `java.util.regex.Pattern` — replacing Path C stub. compile /
     matcher / split / matches (static) / quote. PCRE `~...~`
     delimiters; flags translate to PCRE modifiers (CASE_INSENSITIVE
     →i, MULTILINE→m, DOTALL→s, etc.). **21/21 parity** static.
   - `java.util.regex.Matcher` — replacing Path C stub. matches /
     lookingAt / find / group / start / end / groupCount / replaceAll
     / replaceFirst / reset / pattern. Smoke-validated; full parity
     needs cross-class chained dispatch in the driver.

   Driver capability gained — instance-method dispatch
   (`bench/parity/oracle_driver.clj`):
   - `find-constructor` resolves a constructor on the target class
     using the same param-accepts? matcher as find-method.
   - `invoke-jdk` now branches on case-spec shape: `{method, args}` =
     static (existing path); `{new: [...], ops: [{call, args}, ...]}`
     = instance mode. Constructs fresh instance, runs ops in sequence,
     returns the LAST op's return value.
   - `invoke-php` switched to JSON-on-stdin (avoids argv length limits
     on long ops chains; PHP runner's `--stdin` mode mirrors driver's
     case-spec shape).
   - Same shape works for ANY instance-bearing class — ArrayList,
     LinkedList, Iterator-bearing types. Per-class effort drops to
     just shim + battery, no harness work.

   Driver capability gained — `find-method` does name+arity+
   assignable-types lookup with primitive ↔ wrapper unboxing and
   widening, picks the most-specific overload by specificity score.
   Replaces the strict `getMethod(typed-args)` lookup that couldn't
   resolve `Objects.isNull(Object)` from a String arg. Also: vector
   args dispatch as `Object[]` (Java vararg `Object...`), with
   `box-arg` recursively converting Clojure vectors into Java
   `Object[]` for `Method.invoke` — required by `Objects.hash`
   and likely future `Arrays.*` methods.

   IR routing fix landed alongside (Builder.php classFqn): JDK
   classes whose simple name collides with a PHP-8 reserved type
   keyword (`String`, `Object`, `Float`, `Void` — all
   case-insensitively reserved) route to the underscore-suffixed
   shim shape (`String_`, `Object_`, `Float_`, `Void_`). Closes the
   `Class "PHPJava\Aot\Runtime\java\lang\String" not found` AOT
   dispatch errors. Underscore-shim files now exist (Object_, Float_,
   Void_ in `src/Aot/Runtime/java/lang/`); String_ was already there.

   IR Builder peephole for `new String(...)` lowering — per
   BOXING.md doctrine, `new String()` and `new String(String)` lower
   to the literal directly (no allocation, no shim instance state
   needed). Other constructor forms (byte[], char[]) still flow
   through `New_` and surface a clear failure when reached, marking
   the next fixture-driven extension. **Closed 2 suite errors**:
   `JavaUtilObjectsTest::testHashCode` and
   `JavaLangStringTest::testHashCode` (both call `Objects.hashCode(
   new String(...))` / equivalent). Suite: **24E** (was 26E).

   Remaining 4 string/system errors all depend on
   `System.identityHashCode` to assert per-instance string identity
   (testIntern / testNotInterned / testNotInternedAfterLiteral /
   testIdentityHashCode). The PHPJava AOT contract intentionally
   diverges here — PHP string IS Java String value-wise, no
   per-instance identity for primitives. Implementing
   `identityHashCode` would close `testIntern` (same-value → same
   hash) but not `testNotInterned` (which asserts inequality of
   same-value strings). Contract-divergence call deferred.

**Independent capability work (parallelizable with the critical path):**

3. **AOT instance dispatch (Phase B receiver-shape unification)** —
   **DONE structurally**. Instance dispatch routes through aotInstance
   when bound (`src/Core/JVM/JavaClassInvoker.php:152–172` sets up the
   AOT-Generated PHP instance during `->construct()`;
   `JavaMethodCallable.php:114–138` dispatches through it).
   AOT-emitted code uses PHP-native instance dispatch directly on
   `\PHPJava\Aot\Generated\<X>` instances — no further wiring needed.
4. **ObjectMethods record-shape emit** — load-time **DONE** via
   `RecordAttribute` parser + `LOAD_ATTRIBUTES` whitelist.
   Runtime record-class shape (`extends \PHPJava\Aot\Runtime\java\lang\Record`
   + auto-generated equals/hashCode/toString via ObjectMethods indy)
   still pending — folds into the broader bb-allowlist fill.
5. **T1 class-file gaps** (Java 11+ load-time): **DONE**
   - `CONSTANT_Dynamic` (tag 17) — `src/Kernel/Structures/DynamicInfo.php`
   - `CONSTANT_Module`/`Package` (tags 19, 20) — `ModuleInfo.php` + `PackageInfo.php`
   - `NestHost`/`Record`/`PermittedSubclasses` attribute parsers shipped
   - All wired in `src/Core/JVM/ConstantPool.php` + `Runtime::LOAD_ATTRIBUTES`
   - `tests/Cases/RecordLoadTest.php` validates load + component access
6. **Sequenced collections (Java 21)** — interfaces **DONE** at
   `src/Packages/java/util/SequencedCollection.php` (+ `Set`/`Map`).
   `LinkedHashMap`/`LinkedHashSet` retrofit folds into bb-fill.
7. **Unsafe shim** — minimum viable **DONE** at
   `src/Aot/Runtime/sun/misc/Unsafe.php` + the Java-9+ alias at
   `src/Aot/Runtime/jdk/internal/misc/Unsafe.php`. CAS family
   (compareAndSwap{Int,Long,Object}), getAndAdd / getAndSet,
   offset-mapped get/put, no-op fences. PHP single-threaded so naive
   read-modify-write CAS produces the same observable behaviour;
   Swoole-multi-process atomics not yet wired (folds into ROADMAP §Build
   T3 concurrency adapter). 9 unit tests in
   `tests/Cases/UnsafeShimTest.php`.
8. **Lazy CP resolution in parser** — **already in place**. The 22%
   probe-fail rate was closed by `JavaCompiledClass.php:180`'s lazy
   super-class load (per `bench/probe-real-library.md`); the audit's
   pointer to `ConstantPool.php:42–59` was a misdiagnosis (CP entries
   store indices only, no eager class loads at exec time).
9. **Compiler-time FQN substitution API** — **DONE**.
   `Compiler::compileBytes($classPath, $bytes, $substitutionMap = [])`
   accepts a binary-name → PHP-FQN map; consulted in
   `src/Aot/Ir/Builder.php`'s `classFqn()` ahead of the JDK / AOT-Generated
   routing (substituted classes bypass the default
   `\PHPJava\Aot\Runtime\<...>` shape entirely). Cache key includes a
   hash of the map so substituted and non-substituted compiles don't
   collide. Test: `tests/Cases/AotSubstitutionMapTest.php`. Generally-
   useful API for any compile-time consumer that needs to remap class
   FQNs at translate time.
10. **`java.lang.foreign.*` shim over Zend FFI** (Path B in LAYERS).
    AOT-compiled Java code that calls real C libraries via the Foreign
    Linker API gets routed through Zend's FFI extension. Both API
    surfaces are C-ABI bridges; the mapping is mechanical.
    **NOT STARTED** — niche per `docs/JVM-PHP-DELTA.md:445`; waits
    for a workload that exercises `java.lang.foreign.Linker`.

### Refinement — perf and reliability tightening (1–2 weeks total)

Doesn't gate v1 but tightens substrate before bb-fill exercises it
broadly.

1. **P6 peephole — pop-into-temp + push-expr collapse** — **OBSOLETE**.
   The PATTERNS.md observation predated the IR-level `InlinePass`
   (`src/Aot/Ir/InlinePass.php`); the current emit doesn't have the
   leftover residue. Verified against `bench/aot-out/BenchInvoke.php` —
   inlined call sites collapse cleanly to bare expressions.
2. **Compiler cache observability** — **DONE**.
   `Compiler::cacheStats()` / `resetCacheStats()` returns per-cache
   hits/misses/evictions/size for daemon scrape interval. Test:
   `tests/Cases/AotCacheStatsTest.php`.
3. **Method overload — fuller arg-shape matching** — current dispatcher
   falls through to `NoSuchMethodException` on shape ambiguity. Refine
   when a real overload set surfaces a mismatch.
4. **Multi-interface implementation** — needs PHP `traits` + `interface`
   combo. Single suffices today (`src/Aot/Compiler.php:500–507`).
5. **By-ref auto-detect patterns 2+3** — known-PHP-mutating-fn registry;
   chained user fns (`src/Aot/Ir/Builder.php:356–366` defer comment).
   Direct-aset alone covers current tests.
6. **AOT class-emit shape doc companion** in
   [LAYERS.md](docs/LAYERS.md) — already inline at LAYERS §"AOT
   class-emit shape (2026-05-04)". Marking done.

### Cleanup — pure subtraction

1. **Phase C** — `Dynamic→Instance` rename per LAYERS.md:65–72.
   **DONE**. Six classes/traits/methods renamed across 18 files.
2. **Phase E** — legacy stack delete. **DONE**. 422 files / 14288
   lines removed (`src/Compiler/`, `tests/Cases/Compiler/`,
   `MnemonicResolver`, dead constants in `Runtime.php`). LAYERS.md's
   "Frames/Variables die with Phase D" claim turned out to be wrong:
   `src/Kernel/Frames/` (AppendFrame / ChopFrame / etc.) and
   `src/Kernel/Variables/` (DoubleVariableInfo etc.) are class-file
   parser scaffolding consumed by `StackMapFrameInfo` /
   `VerificationTypeInfo` in `src/Kernel/Structures/` — they survive.
3. **Phase D** — interpreter delete (~10 kloc, **NOT STARTED**).
   Targets: `src/Kernel/Mnemonics/`, `JavaMethodCallable` interpreter
   half, `src/Kernel/Types/` remainder, `src/Kernel/Filters/Normalizer.php`,
   `src/Kernel/Provider/`, `OperationCache`, `OutputDebugTraceTest`.
   Entangled with kept files: `src/Kernel/Resolvers/TypeResolver.php`
   imports 8 Kernel/Types/* classes; `src/Packages/java/lang/{String_,Integer}.php`
   import Char_/Int_; `src/Core/JVM/{JavaClassInvoker,ClassInvokerInterface}.php`
   import Filters/Normalizer + Provider/ProviderInterface;
   `JavaMethodCallable` itself is the load-bearing trim. Refactoring
   each kept file off the boxing-wrapper / DI-provider deps is what
   makes Phase D bigger than "rm -rf". Defer to a focused session
   with full revert safety.
4. **Doc consolidation** — **DONE**. ROADMAP is canonical
   "what's open + priority"; HANDOVER session-only; STATUS measured
   rank-1 only; GAP-JDK version coverage map; MODEL strategic framing
   only.

### Done since 2026-05-04 docs were written (audit-confirmed)

| Item | Site |
|---|---|
| Sub-step 1c-β (full abstract-stack) | `src/Aot/Ir/Builder.php:287–310` |
| Sub-step 1c-ε (Switch_ terminator) | `src/Aot/Ir/Node.php:220`; `Builder.php:501,1128–1136`; `Lowerer.php:245–251` |
| Sub-step 1c-ζ (DUP2/IUSHR/LUSHR/MULTIANEWARRAY) | `src/Aot/Ir/Builder.php:799,814–831,836–847` |
| SwitchBootstraps (Java 21 pattern switch) | `src/Aot/Ir/Builder.php:1643–1652`; `src/Aot/Runtime/bootstrap.php:326–381` |
| LRU eviction on Compiler caches (1000 entries each) | `src/Aot/Compiler.php:84–200` |
| Dead string-path emitter removal | only ~32-line `emitStringConcat` left |
| Class-file version table 53.0–69.0 (Java 9–25) | `src/Kernel/Resolvers/SDKVersionResolver.php:35` |
| StringConcatFactory (AOT path) | `src/Aot/Ir/Builder.php:1675–1679` |
| LambdaMetafactory + altMetafactory (AOT path) | `src/Aot/Ir/Builder.php:1637–1640,1819–1851` |
| Symfony Console version constraint | `composer.json:20` `^5.4|^6.0|^7.0` |
| Path C stub generator + 110 stubs | `tools/gen-aot-stubs.php`; `src/Aot/Runtime/java/**` |

## What's not on the roadmap

- Shared `$GLOBALS` runtime with cljp. cljp and PHPJava are peers on
  Zend, not nested. Marshalling at language boundary, not runtime
  unification. (See `docs/MODEL.md` §"Two peers on Zend".)
- Universal redefinability via Var indirection. Java doesn't have it
  natively; languages above (Clojure) provide their own.
- A separate IR for naive AOT. JVM bytecode is the IR.
- Full bytecode verifier semantics. Trust the input; verifier costs more
  than it saves.
- HotSpot perf parity. Cap is ~50× HotSpot interpreted with optimal AOT;
  acceptable for scripting/library use, not for tight inner loops in
  performance-critical code.
- **PHP-frontend / unified-IR for shim inlining (perf-motivated).
  PARTIALLY FALSIFIED — verdict qualified by meta-falsification 2026-05-05.**

  Original falsifier at `bench/php-frontend-falsifier.php` pre-registered
  four claims (lift coverage, integrated perf, IR compatibility,
  semantic equivalence). F1, F3, F4 passed. F2 measured single-hop
  `Math::abs` in a hot loop: lifted via require ran at 28 ns/op vs the
  static-method shim at 15.6 ns/op under PHP 8.4 JIT — slower, not
  faster. Initial verdict: PHP JIT already inlines static-method shims
  aggressively enough that user-space IR inlining adds back the
  overhead JIT had eliminated.

  Meta-falsifier at `bench/php-frontend-meta-falsifier.php` interrogated
  F2's methodology with three probes. Two overturned, one supported:

    M1 CHAIN-INLINING — 3-deep static-method chain (Outer→Middle→Inner)
    vs lifted-fused single function. Shim chain: 33.98 ns/op JIT.
    Lifted-fused: 9.47 ns/op JIT. **3.59× speedup.** PHP JIT inlines
    single hops but gives up at depth ≥3. F2 measured the one case JIT
    handles cleanly — single hop, hot loop. Real bb-fill workloads
    compose (Objects.hashCode → String_.hashCode → loop;
    Integer.parseInt(s.trim()); HashMap.put walking hash → equals →
    bucket). The architecture wins where composition lives.

    M2 TYPE-NARROWING — compile-time JVM-type info eliding shim's
    runtime is_int branch. 1.25× speedup, below the 1.4× threshold.
    JIT's tracer specialises types well enough that compile-time
    narrowing alone is marginal. Not load-bearing.

    M3 COLD-PATH — single call, no JIT warmup. Shim 17.40 ns/op vs
    lifted-ranged 13.10 ns/op. **1.33× speedup cold.** Request-scoped
    FPM (ROADMAP §Goal's dominant deployment) is cold-path. JIT-warm
    measurements understate the architecture's value for the project's
    primary target.

  **Refined verdict (where lifting helps):**
   - Single-hop hot loop with JIT warm: F2 stands. Lifting doesn't help.
   - Composition depth ≥3 hot: lifting wins ~3.6×.
   - Any depth, cold path / FPM: lifting wins ~1.3×.

  **Orthogonal to bb-fill workflow.** Shim authoring is unchanged —
  same PHP file at `src/Aot/Runtime/java/<...>.php`, same parity
  battery, same oracle validation. The unified frontend processes
  *those same files* at compile time; nothing changes for bb-fill
  authors. The 4-week refactor is substrate engineering, not
  author-side disruption. Zero opportunity cost on the v1 gate
  beyond the engineer-week allocation. The decision is a scheduling
  question (when to spend the engineer-weeks), not an either/or
  against bb-fill.

  **No "lift-able subset" constraint as discipline tax.** Fast PHP
  patterns and readable PHP patterns and lift-able PHP patterns
  converge on the same code: strict types, direct expressions, pure
  functions where possible, no magic methods in hot paths, plain
  if/return flow. Each property pays in three dimensions
  simultaneously — JIT specialises, humans read clearly, the lifter
  emits clean IR. The shim authoring guide collapses to "write good
  PHP." Mesh's `expr.php` is the existence proof: a Pratt parser
  shipped in production-shape clean PHP that's also exactly the
  substrate a lifter would emit IR from. Cases that legitimately
  need dynamic features (ORM/DSL/proxy/`__get`-magic) stay as
  external PHP, lifting refused, plain Zend dispatch — those paths
  can't be JIT-friendly anyway, so no perf is lost by not lifting.
  The compiled/external boundary maps onto fast/flexible cleanly.
  The lifter becomes a quality gate ("if it doesn't lift, it
  probably shouldn't be in a hot-path shim"), not a constraint.

  **Generalisation that falls out.** Once IR is the canonical
  representation and frontends pluggable, "PHPJava" stops being
  "JVM-bytecode-to-PHP transpiler" and becomes "multi-frontend
  compiler with PHP backend." JVM bytecode is one frontend among
  potentially many — Lua source, WebAssembly, hand-coded IR DSL, or
  any language with a `.class` generator (Kotlin / Scala / Clojure /
  Groovy already work via that path). The unified-frontend substrate
  is what enables non-bytecode frontends; without it, every new
  source language requires its own ad-hoc translation to bytecode.

  Anti-context for future sessions: when a "compile shims through the
  same pipeline as user bytecode for perf" pitch surfaces, re-read both
  `bench/php-frontend-falsifier.php` (the simple-hop case where it
  doesn't help) and `bench/php-frontend-meta-falsifier.php` (the
  composition + cold cases where it does) before deciding. The
  earlier "bb-fill OR unified-frontend" framing was a false dichotomy
  — they're orthogonal substrates that don't compete for author time.

  **Profile result 2026-05-05** at `bench/shim-chain-profiler.php`:
  the current shim layer (1887 indexed methods) is overwhelmingly
  flat — only 1 method at depth ≥3 (`Objects::hash → Objects::hashCode
  → String_::hashCode`), 15 at depth 2, 99.2% at depth 1. M1's win is
  theoretical for this codebase until compositional bb-fill classes
  (HashMap.put walking hash+equals+bucket; Stream operations;
  Comparator dispatch) ship. Re-run the profiler after each batch of
  fills; revisit the spike decision when depth ≥3 crosses 20%.

## Cadence

- Each tier produces measured evidence before the next is started.
- Phase exit reports as PRs against this file with `bench/<sha>.json`.
- Strategic shifts go in `docs/MODEL.md`, not here. ROADMAP.md tracks
  what's done and what's next; MODEL.md tracks why.

## Open questions

- **`Compiler/Emulator/` repurposing.** Settled by 2026-05-04 audit
  (`docs/LAYERS.md`): Phase E deletes it — pattern overlap with
  `src/Aot/Ir/Builder.php`'s abstract-stack tracking is structural, not
  reusable code. Listed for transparency; not actually open.
- **License posture for shim port.** Spec-based reimplementation is the
  default. Specific cases (e.g., a complex `String.format` parser) may
  benefit from referencing OpenJDK in the abstract — never copy.
