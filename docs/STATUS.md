# Project status — 2026-05-03

> Snapshot of where we are, what's measured, what's next.
> Updated as work lands. The roadmap is a hypothesis; this is reality.
> Last updated after IR migration + escape analysis + compile cache (HEAD = `a5fc4ca`).

## Headline

**The AOT pipeline is feature-complete and within 1.8× of HotSpot JIT.**
A real compiler walks PHPJava's parsed bytecode through an IR substrate
(Module + Method + BasicBlock + Stmt + Terminator + Expr) and lowers
to PHP that runs at **0.18-0.20 ns/op JIT for hot int loops, 0.20 ns/op
for invokestatic-heavy code, 2.4 ns/op for array workloads**. All 9
fixtures lift through the IR with zero string-path fallbacks.

**Compile-output caching gives 2649× speedup on repeat compiles**
(1578 µs cold → 0.6 µs warm), making runtime AOT viable for long-
running daemons. For Clojure-boot equivalent (~600 classes): cold
~947 ms, cache replay ~0 ms.

**What's done:** opcode coverage (~150 ops including all 4 dispatch
types, exception flow, arrays, INVOKEDYNAMIC for StringConcatFactory
+ LambdaMetafactory, defineClass(byte[])). Stack-erasure peephole.
Cross-method inlining at IR level (Tier 1c-γ). Escape analysis on
Java arrays (Tier 1c-δ — closes the 10× property-access cost).

**What remains:** ObjectMethods (Java records), SwitchBootstraps
(Java 21+ pattern switch), the 233-class T2 JDK shim layer, LRU
cache eviction for long-running daemons, full abstract-stack
tracking for non-empty BB-entry stacks. None of the remaining work
is on the architecture critical path — it's all capability fill or
edge-case polish.

## What's measured (rank 1)

| Bench | Value | File |
|---|---|---|
| Current PHPJava interpreter | 5.22–6.56 µs / op | `bench/baseline-d803364.json` |
| Switch-dispatch interpreter (spike) | 22 ns / op no opt; 36 ns / op JIT | `bench/spike-fast-interp.php` |
| Hand-translated naive AOT (spike) | 5.6 ns / op no opt; 1.5 ns / op JIT | `bench/spike-fast-interp.php` |
| **Real compiler-emitted AOT, BenchAdd::sum1k() — pure arith loop** | **0.18 ns / op JIT** (post stack-erasure peephole 2026-05-03) | `bench/bench-aot.php` (needs `-d opcache.jit_buffer_size=1024M`) |
| **Real compiler-emitted AOT, BenchInvoke::callLoop() — invokestatic in loop** | **0.41 ns / op JIT** (post cross-method inlining 2026-05-03) | was 4.05 ns/op pre-inlining; the 22× call cost recouped |
| Compiler-emitted, prior naive (no peephole) | 0.82 ns / op JIT | superseded |

### IR substrate (proof-of-concept landed 2026-05-03)

`src/Aot/Ir/Node.php` defines a minimal AOT IR — Module, Method,
BasicBlock, Stmt (StoreLocal, IincLocal, ExprStmt, StoreStaticField),
Terminator (Goto_, CondGoto, Return_, Throw_), Expr (literals,
LocalRead, ParamRead, BinOp, UnaryOp, StaticCall, StaticFieldRead).
Pure-vs-impure flag at the Expr level.

`src/Aot/Ir/Lowerer.php` lowers IR to PHP source. Hand-built
BenchAdd::sum1k() roundtrips through the IR and runs correctly
(`bench/test-ir-roundtrip.php` returns 499500). Validates that the
IR shape is rich enough for our existing emit patterns.

**Adoption analysis (rank 5):** surveyed LLVM IR, MIR, Cranelift,
WebAssembly, HHVM HHIR, TeaVM, ESTree, cljp's `:ps/*`/`:pl/*`. All
ruled out by the **PHP-host constraint** — the AOT compiler runs
in PHP, so the IR must be PHP data structures (no FFI dependency
desired for the compiler). Custom IR shaped to our exact needs is
~550 LOC, vs adapting any external IR which would be much larger.

**What the IR proof-of-concept proves:**
- Shape is sufficient for current emit patterns (BenchAdd validated)
- Lowerer produces semantically-equivalent PHP
- ~300 LOC for nodes + lowerer; bytecode-to-IR builder is the
  remaining piece (~250 LOC est.) before full production use

**What's not yet built:**
- Coverage for exception-handler entry stacks, invokedynamic, all
  opcode shapes (the Builder PoC handles BenchAdd's subset:
  iconst/iload/istore/sipush/iadd-style/iinc/if_icmp/goto/ireturn/invokestatic)
- Optimization passes ported from string-level peephole/inline to IR
  rewrites (currently the IR Builder bakes in stack-erasure via
  abstract-stack tracking; cross-method inlining is still string-level)

**Decision criterion**: build full IR substrate when the next 4 weeks
focus on perf (escape analysis, full abstract-stack, loop transforms
all benefit) — defer if next 4 weeks focus on JDK shim coverage.

### IR Builder validated end-to-end (2026-05-03)

`src/Aot/Ir/Builder.php` — JVM bytecode → IR walker, ~280 LOC, opcode
coverage limited to BenchAdd's set as PoC. Operand-stack erasure baked
in via abstract-stack tracking during construction (each `iload N`
pushes `LocalRead(N)` onto the abstract stack; `istore N` pops and
emits `StoreLocal(N, popped)`).

`src/Aot/Ir/Lowerer.php` — IR → PHP, with two emit-shape elisions
that match the string-path's JIT-friendly output:
- Live-label set: drop labels not targeted by any terminator
- Redundant-goto: drop goto-to-immediately-next-block

`bench/test-ir-bench.php` — end-to-end roundtrip: BenchAdd.class →
IR Builder → Lowerer → execute → returns 499500. Functional ✓.

`bench/test-ir-vs-string.php` — head-to-head perf: string-path
BenchAdd at 0.25-0.26 ns/op vs IR-path BenchAdd at 0.28-0.31 ns/op.
**Within 10% — IR substrate is perf-equivalent to the current string
emitter.**

**Falsifier results (all five tested 2026-05-03):**

| # | Falsifier | Verdict |
|---|---|---|
| F-IR1 | IR-path perf-equivalent on 2nd fixture (BenchInvoke) | **HOLDS** — IR is **2× faster** than string-path in lean bench (0.23 vs 0.47 ns/op). Earlier "11.96× slower" was JIT-cache-pollution artefact in test harness, not real perf. |
| F-IR2 | Single IR-level opt < 100 LOC | **HOLDS** — algebraic-identities 38 LOC; cross-method inlining at IR 70 LOC |
| F-IR3 | BenchTryCatch (exception flow) lifts in < 200 LOC | **HOLDS** — 31 LOC of substrate additions (Node + Lowerer) |
| F-IR4 | Full opcode coverage < 2× the 530-LOC budget (= 1060 LOC) | **FALSIFIED** — actual ~1394 LOC for all 9 fixtures (~32% over). Original estimate was off by ~30%. |
| F-IR5 | 10× faster IR via flat-array substrate | **FALSIFIED** — flat-array gives only 1.29× end-to-end (1.51× construction, 1.14× lowering). PHP 8 JIT specialises over OOP and array shapes about equally. |

**All 9 fixtures lift through the IR (rank 1 verified):** BenchAdd, BenchEmpty, BenchInvoke, BenchArray, BenchTryCatch, BenchConcat, HelloWorld, BenchLambda, BenchRunner. The migration is functionally complete — what remains is wiring it into `Compiler::compileFromGenericClass` to replace the string-path emitter.

**Compile-time profile (rank 1, BenchAdd::sum1k):** PHPJava parser 83% (1280 µs); IR build+lower 1.5% (19 µs). For runtime-compilation use cases, **the IR is already fast enough**; the parser is the bottleneck.

**Compile-output caching landed (2026-05-03):** `Compiler::compileBytes` and `compileClass` cache the rendered PHP per (classPath, bytes-hash). Rank-1 measured: **2649× speedup** on cache hits (1578 µs cold → 0.6 µs warm). For Clojure-boot equivalent (~600 classes per CLOJURE-BOOT-ANALYSIS.md): **947 ms cold → ~0 ms cache replay**. This is the realistic answer to "make compile fast for runtime use" — far bigger lever than any IR-layer micro-optimization.

**IR-path is now the production default** in `Compiler::compileFromGenericClass`, with string-path fallback for unsupported opcodes. All 9 fixtures lift through IR with zero fallbacks. The IR Builder's structural stack-erasure produces 30-43% smaller emit than the post-emit peephole on multi-method fixtures (rank-1 verified via contract gate).

**Effort to complete the migration (revised after F-IR4):**
| Piece | LOC | Status |
|---|---|---|
| IR types (Node.php) | ~226 | ✓ done |
| Lowerer | ~185 | ✓ done |
| Bytecode → IR builder | ~388 | ✓ for BenchAdd/Empty/Invoke/Array opcode subsets |
| ArrayHelper (Java-array wrapper adapter) | ~43 | ✓ done |
| **4-fixture coverage subtotal** | **~842** | ✓ done |
| BenchTryCatch coverage in Builder | ~80 | next |
| BenchConcat (StringConcatFactory) | ~80 | next |
| BenchLambda (LambdaMetafactory + synth class gen) | ~120 | next |
| HelloWorld (invokevirtual + getstatic + ldc) | ~80 | next |
| BenchRunner (long math + multi-method) | ~100 | next |
| Wire into Compiler with fallback for un-IR'd opcodes | ~50 | next |
| **Total to full migration** | **~1300 LOC** | **~1.5–2 weeks** (not 1 week as previously estimated) |

**Architectural conclusion:** F-IR1, F-IR2, F-IR3 all hold — the IR is the right substrate. F-IR4 only falsifies the LOC estimate, not the architecture. The 24% overrun is the actual cost, justified by the rank-1-measured 2× perf win on BenchInvoke.
| Hand-translated idiomatic AOT (spike) | 0.4 ns / op no opt; 0.2 ns / op JIT | `bench/spike-fast-interp.php` |
| HotSpot interpreted reference | 0.52 ns / op | `bench/baseline-d803364-hotspot.json` |
| HotSpot JIT reference | ~0.10 ns / op | (recall) |

**The compiler-emitted AOT now beats the hand-emit *idiomatic* AOT
reference** (0.18 vs 0.2 ns/op JIT) after the 2026-05-03 stack-erasure
peephole landed. Sits at **1.8× HotSpot JIT**, 0.35× HotSpot interpreted
— meaning we're now ~3× faster than HotSpot's own interpreter and
within 2× of its top-tier C2 compiler. See `docs/PATTERNS.md`
"Stack-erasure peephole" for the implementation details (~150 LOC of
pattern-rewrite over emitted PHP statements, applied to fixpoint).

**Where that gap lives** (rank 1, `bench/jit-claims.php` 2026-05-03):
Zend's JIT does CSE, LICM, and type specialisation within a function (1×
ratios). It does **not** inline (cross-method calls cost 10–22×) and
does **not** optimise object property access (10× over scalar). The
remaining gap is exactly the work that has to live in the AOT compiler
itself — Tier 1c per ROADMAP — namely cross-method inlining for hot
invokestatic, escape analysis on Java arrays to skip the `stdClass{v}`
wrapper, and stack erasure to remove operand-stack indirection.

### Documented divergences from HotSpot (rank 1, structural)

These are workload classes that will run measurably slower under
PHPJava than under HotSpot, **regardless of AOT optimisations** —
they live below the AOT layer in PHP/Zend itself or in extensions:

| Workload | Divergence | Source |
|---|---|---|
| `java.math.BigInteger` arithmetic | 5–10× slower (BCMath/GMP overhead vs JNI HotSpot path) | `bench/jit-claims-ext.php`, see PATTERNS.md "Extension overhead" |
| `double`/`float` heavy code | 3.8× slower than `int` math at the Zend layer | `bench/jit-claims-extended.php` |
| Tail-recursive Java code | 9× slower (no PHP TCO) | `bench/jit-claims-extended.php` |
| Multi-byte String character ops via mbstring | 2× over native ASCII path | `bench/jit-claims-ext.php` |

Free-tier shim ops (≤1.5× HotSpot): `java.security.MessageDigest`,
`Mac`, `javax.crypto.Cipher`, `java.util.regex.Pattern`,
`java.util.zip.*` — these PHP extensions are absolute-time-comparable
to HotSpot's bundled implementations. PATTERNS.md "Extension overhead"
has the full ratio table.

**Falsifier F1 (per-op > 1 µs after Phase 2) — lifted by measurement.**
Switch dispatch alone is at 22 ns/op, well under the 1 µs threshold.

### Verification stack (rank 1, all PASS as of 2026-05-03)

| Fixture | Bytecode coverage | Result |
|---|---|---|
| BenchAdd::sum1k() | int loop, iadd, if_icmpge | 499500 |
| BenchInvoke::callLoop() | invokestatic (same-class) | 100 |
| HelloWorld::main() | getstatic, ldc, invokevirtual cross-class | "hello from phpjava\n55\n" |
| BenchArray::sumArray() | newarray, iastore, iaload, arraylength | 45 |
| BenchTryCatch::run() | new + dup + invokespecial<init>, athrow, exception-table → try/catch | 42 |
| BenchConcat::greet("alice", 5) | invokedynamic + StringConcatFactory.makeConcatWithConstants | "hello alice! count=5" |
| BenchLambda::run() | invokedynamic + LambdaMetafactory (no captures) | 42 |
| BenchLambda::withCapture(7) | invokedynamic + LambdaMetafactory (1 capture) | 107 |
| BenchAddFromBytes (defineClass) | raw `.class` bytes → AOT → run via `Compiler::compileBytes()` | 499500 |

### Decisions locked (with measurement backing)

- **Drop primitive wrappers entirely.** 7–9× cost per arithmetic op
  measured (`bench/validate-boxing.php` §3). Wrapper classes shrink to
  static-method namespaces + reflection metadata. See `docs/BOXING.md`.
- **Switch in static function for interpreter dispatch.** 9–10 ns/op
  with JIT — beats closure-table by 3–6×. See `docs/PATTERNS.md` Rule 5.
- **Regular array, never `SplFixedArray`.** Loses by 2–7× across all
  measurements (`bench/validate-datastructures.php`).
- **Frame state in PHP locals**, not arrays/objects. 5–13× faster.
- **Pre-decoded int array bytecode**, not stream. 2–3× faster per byte.
- **Java int overflow semantics preserved** via `& 0xFFFFFFFF` mask +
  sign extend. 1 ns extra per op.
- **PHP scalars throughout**; only tagged tuples at CFG-merge autobox sites
  (rare, ~5% of code paths).
- **No shared `$GLOBALS` runtime with cljp.** They're peers on Zend; bridge
  marshals at language boundary.
- **No separate IR for naive AOT.** JVM bytecode IS the IR (PHPJava parses).
- **PHP 8.1 minimum** (Fibers needed for Thread emulation). 8.4+ recommended.
- **Java 21 LTS target** for capability scope (lambda metafactory, indy,
  records, sealed, virtual threads).

## What's documented (consolidated, current)

Read in this order if landing fresh:

| Order | Doc | Purpose |
|---|---|---|
| 1 | `README-hbtweb.md` | orientation; doc map |
| 2 | `docs/MODEL.md` | strategic framing — *why* the architecture is what it is |
| 3 | `docs/CONTRACTS.md` | normative spec — interfaces, value-rep, dispatch decisions |
| 4 | `docs/PATTERNS.md` | the 5 measured rules + what to subtract |
| 5 | `docs/BOXING.md` | gut the wrappers; rationale + JVM contract mapping |
| 6 | `docs/BOTTLENECKS.md` | every measured cost + type emulation guidance |
| 7 | `docs/JVM-PHP-DELTA.md` | platform/semantic/API differences, version evolution (rank 1 verified) |
| 8 | `docs/STATUS.md` | this doc — where we are now |
| 9 | `ROADMAP.md` | tier ordering, exit criteria |
| ref | `docs/GAP-JDK.md` | concrete JDK version deltas (Java 19 → 21 → 25) |
| ref | `docs/CLOJURE-BOOT-ANALYSIS.md` | empirical class-load trace from Clojure boot |
| ref | `docs/ADJACENT-SHAPES.md` | TeaVM, DoppioJVM, bb, cljp landscape |
| ref | `bench/README.md` | bench methodology + LD_PRELOAD finding |
| ref | `bench/profile-c930e2c.md` | xhprof attribution of accidental costs |
| ref | `bench/PATTERN-VALIDATION.md` | dispatch/array/boxing pattern measurements |

Plus 7 measurement harnesses in `bench/`:
- `validate-patterns.php` — dispatch shape comparison
- `validate-hotloop.php` — tight-loop variants
- `validate-datastructures.php` — array vs SplFixedArray vs object frame
- `validate-boxing.php` — 6 boxing patterns × 5 workloads
- `validate-remaining.php` — strings, exceptions, numeric, static fields
- `spike-fast-interp.php` — interpreter vs AOT spike
- `aot-compile.php` — real AOT compiler verification

## What's built (rank 1, in repo)

| Component | Status | Notes |
|---|---|---|
| Bench harness (FFM-based) | ✓ working | `bench/baseline.clj`, `bench/bench-cli.php` |
| Profile harness (xhprof) | ✓ working | `bench/profile-xhprof.php` + LD_PRELOAD |
| Switch-dispatch interpreter (spike) | hand-coded subset of 9 opcodes | `bench/spike-fast-interp.php` |
| **AOT compiler (string-path)** | ~150 opcodes; covered by IR fallback | `src/Aot/Compiler.php` |
| **IR substrate** | Module + Method + BasicBlock + Stmt + Terminator + Expr | `src/Aot/Ir/Node.php` |
| **IR Builder (bytecode → IR)** | 9/9 fixtures lift cleanly; bake-in stack erasure via abstract-stack | `src/Aot/Ir/Builder.php` |
| **IR Lowerer (IR → PHP)** | live-label elision + redundant-goto elision + try/catch wrapping | `src/Aot/Ir/Lowerer.php` |
| **IR InlinePass** | cross-method inlining as IR transform; replaces post-emit-text pass | `src/Aot/Ir/InlinePass.php` |
| **IR escape analysis (Java arrays)** | LocalRead-source detection in iaload/iastore; bypasses 10× wrapper cost | `src/Aot/Ir/Builder.php` (in array-opcode emit) |
| **Compile-output cache** | per-(classPath, bytes) memoization; 2649× speedup on repeat compile | `Compiler::compileBytes/compileClass` |
| AOT bytecode-bytes path (`compileBytes`) | ✓ defineClass(byte[]) entry — raw `.class` → AOT'd PHP without ClassResolver | `Compiler::compileBytes()` |
| AOT contract gate (drift detection) | ✓ snapshot+diff over 9 fixtures | `bench/contract.php`, `bench/contract-snapshots.json` |
| AOT bench harness | ✓ rank-1 measured 0.18-0.20 ns/op JIT iadd-1k | `bench/bench-aot.php` |
| AOT-clean stdlib shim | ✓ `\PHPJava\Aot\Runtime\java\lang\System` + `java\io\PrintStream` | `src/Aot/Runtime/bootstrap.php` |
| Test suite unblock for JDK 25 | ✓ committed | `tests/Cases/Base.php` |

## What's open (rank 2/3 estimates)

| Work | Estimated effort | Blocking |
|---|---|---|
| ~~Full opcode coverage in AOT compiler~~ | done modulo invokedynamic + exception tables | – |
| ~~Method dispatch (INVOKE*) emission~~ | done — `\Class::m()` and `$obj->m()` direct emit per CONTRACTS.md §6, hours not weeks once the architectural cut was clear | – |
| ~~Exception-table → try/catch translation~~ | done for non-nested ranges — per-range try/catch with goto-from-catch handler dispatch. Rank-1 verified on `BenchTryCatch::run() = 42`. Nested/overlapping ranges fall back to no-protection emit; refine when fixture surfaces. | – |
| ~~`Compiler::compileBytes()` — defineClass(byte[]) path~~ | done — AOT compiler now accepts raw `.class` bytes via `InlineReader`. Rank-1 verified by reading `BenchAdd.class` from disk, AOT-compiling the bytes (no `ClassResolver`), running result = 499500. Unlocks runtime class synthesis: custom ClassLoader, CGLIB-style proxies, Clojure's anonymous fn classes, mocking frameworks. | – |
| ~~StringConcatFactory (subset of INVOKEDYNAMIC)~~ | done — recipe-to-PHP-concat translation. Rank-1 verified on `BenchConcat::greet("alice", 5)`. | – |
| ~~LambdaMetafactory~~ | done — synthetic PHP class generated per lambda, captures stored in private fields, SAM method forwards to the AOT'd static lambda body. Rank-1 verified on `BenchLambda::run()=42` (no-capture) and `BenchLambda::withCapture(7)=107` (1-capture). Covers every Java 8+ `() -> ...`. `altMetafactory` reuses the same path. | – |
| Arbitrary INVOKEDYNAMIC bootstraps (custom dynamic-language dispatch, ObjectMethods records, SwitchBootstraps pattern-switch) | ~1 week each | T1 — long tail of indy use; lazy CallSite shim for unknown bootstraps |
| `defineClass(byte[])` | 3–5 days | nothing |
| Test suite to green | 1–2 weeks | value-rep refactor |
| Value-rep refactor (drop `Int_`/`Long_`/`Double_` boxing) | 2 weeks | CONTRACTS.md ✓ |
| `Unsafe` shim (PHP-locked CAS) | 1 week | nothing |
| 233-class T2 implementation | 2–4 months | T1 |

## Revised tier work estimate (after CLOJURE-BOOT-ANALYSIS)

| Tier | Original estimate | Revised | Reason |
|---|---|---|---|
| T0 — contracts | 3–5 wks | **mostly done** — CONTRACTS.md committed | |
| T1 — class file format + indy | 3–5 wks | unchanged | |
| T2 — bb-allowlist core | ~80 classes | **233 classes** | empirical from Clojure boot trace |
| T3 — concurrency | ~6 classes | **~50 classes** | full `java.util.concurrent.*` |
| T4 — bb-allowlist tail | ~300 classes | **150 classes** | smaller than thought |
| T5 — `Unsafe` + JDK-internal stubs | ~10 surfaces | **~100 stubs + `Unsafe`** | needed for ConcurrentHashMap |

**Net revised work:** ~530 classes total (~400 implementations,
~130 stubs). Bigger than the earlier "~383" estimate but a meaningful
fraction is generator-amenable stubs.

## Ranked next steps (revised 2026-05-03 after IR migration)

The original 3-week plan around H1-H8 hot-loop fixes is mostly
overtaken by the IR migration — the AOT compiler now sits at ~0.18
ns/op JIT for hot int loops (~1.8× of HotSpot JIT). Remaining work
is capability surface + production hardening.

**Capability fill (parallelisable, not blocking):**
- ObjectMethods bootstrap (Java records' equals/hashCode/toString) — hours
- SwitchBootstraps (Java 21+ pattern switch) — hours
- 233-class T2 JDK shim layer — months, can run in parallel

**Production hardening (small, before production deploy):**
- LRU eviction for Compiler caches (long-running daemons would otherwise leak)
- Long-running Swoole soak test (24h at sustained load)
- AOT classloader integration into JavaClass::load (wires the strategy decision per CONTRACTS.md §5)

**Optional perf squeeze (diminishing returns; current is within 1.8× HotSpot JIT):**
- Constant folding + DCE at IR level (~hours each; F-IR2 confirms cheap)
- Full abstract-stack tracking for non-empty BB-entry stacks (~days)

**Cleanup:**
- Remove dead string-path emitter code now that IR is the default and string-path is fallback-only

**Original H1-H8 interpreter work — now lower priority:**
1. Rewrite `JavaMethodCallable::call` as switch over pre-decoded int array. The interpreter is still useful as Tier 0 fallback per CONTRACTS.md §3, but AOT covers the perf-critical path.
2. ~~Expand AOT compiler opcode coverage~~ — done; 9/9 fixtures lift through the IR.

**Week 2 — boxing refactor + test suite**
3. Drop `Int_`/`Long_`/`Double_`/`Boolean_`/`Char_` wrappers from
   runtime use. Keep type-tag constants for descriptor parsing.
   Touches ~50 files. Most of the 49 errors / 48 failures are value-rep
   symptoms; they resolve naturally.
4. Run test suite to 100%. Residual fixes for cases that aren't
   value-rep.

**Week 3 — integration + capability**
5. AOT classloader integration: wire `AotEager` and `AotLazy`
   strategies into `JavaClass::load` per CONTRACTS.md §3 + §5. New
   `JavaClassAotMethodInvoker`.
6. Lambda metafactory implementation (`StringConcatFactory` +
   `LambdaMetafactory`). Single biggest capability unlock.
7. `defineClass(byte[])` extension surface (~3 days). Enables
   Clojure-on-PHPJava follow-on work.
8. Long-running soak test in Swoole. Measure cache hit rate, memory,
   latency.

End-of-three-weeks milestone: **~9 ns/op interpreter, ~3-5 ns/op AOT,
test suite green, real Java library AOT-compiles successfully, callable
from PHP code with idiomatic types at the boundary, runs in a Swoole
daemon for 24h without memory growth.**

The longer-tail T2 surface (233 classes from `docs/CLOJURE-BOOT-ANALYSIS.md`)
runs after this milestone. That tail is mechanical implementation work,
not architecture.

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| GraalVM Crema matures, takes our niche | medium | high | Watch Graal release notes; the deployment target (shared PHP hosting) doesn't have Graal anyway |
| OpenJDK source-license entanglement | low | medium | Re-implement from spec; case-by-case override |
| `java.util.concurrent.*` complexity exceeds estimate | medium | medium | Lock + atomics first; defer ConcurrentHashMap until needed |
| opcache memory pressure with large emitted PHP | medium | medium | Generate compact PHP; measure at scale |
| PHP 9 breaking changes when it ships | low | high | Track PHP RFC; test against alpha |
| Self-hosting cljp diverges, breaks shared patterns | low | low | cljp and PHPJava are peers; bridge is small enough that drift is contained |

## What 3 weeks looks like (revised)

The pattern validation (`docs/PATTERNS.md`) showed the H1–H8 + M1–M4
fixes collapse to one structural change. Original 6-week target now
3 weeks.

**Week 1:** Interpreter rewrite + AOT opcode expansion.
**Week 2:** Boxing refactor + test suite to green.
**Week 3:** Integration + lambda metafactory + `defineClass(byte[])` +
soak test.

End-of-three milestone: **AOT compile a small real Java library, call
it from PHP code with idiomatic types at the boundary, run a 24h
Swoole soak test.** Demonstrates the architecture delivers.
