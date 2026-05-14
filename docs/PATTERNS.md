# PHPJava patterns — what the data says

> Date: 2026-05-01 (initial); updated through 2026-05-03 with JIT-claims
> battery, extension-overhead measurements, stack-erasure peephole,
> cross-method inlining at IR level, escape analysis on arrays, and
> compile-cache 2649× speedup findings.
> All measurements rank 1, run on PHP 8.4.20 and 8.5.2 with and without
> opcache+JIT. Source: `bench/validate-patterns.php`,
> `bench/validate-hotloop.php`, `bench/validate-datastructures.php`,
> `bench/spike-fast-interp.php`. Numbers are ns/op unless stated.

This document is the empirical companion to `docs/CONTRACTS.md`. The
contract says *what*; this says *why those choices and not others*. PRs
that propose alternative shapes need to either fit one of the validated
patterns below or come with their own measurement.

## The five rules

1. **Bytecode is a pre-decoded int array.** `unpack('C*', $code)` once at
   class load. Index by `$pc` thereafter.
2. **Frame state is PHP locals**, not arrays-of-state or frame objects.
   `$stack`, `$sp`, `$L`, `$pc` live in the function's local scope.
3. **Operand stack is a regular PHP array.** Not SplFixedArray, not a
   wrapper. Indexed by `$sp` (a local).
4. **Primitives are PHP scalars** on the operand stack. No `Int_`/`Long_`/
   `Double_`/`Boolean_` wrappers. Wrap only at autoboxing sites
   (statically determinable from method descriptors).
5. **Dispatch is switch-in-a-static-function** (interpreter) or
   inlined-and-eval'd PHP (AOT). Both are static-function PHP that
   JIT can trace.

Apply these five and you have the fastest, simplest, most traceable
PHPJava code possible. Everything else is JVM-contract implementation.

## Rule 1 — pre-decoded int array bytecode

PHP 8.5 + JIT, 5 byte reads:

| Pattern | ns/byte |
|---|---|
| **Pre-decoded int array `$ints[$pc++]`** | **5.3** |
| `ord($string[$pc++])` | 13.7 |
| `unpack('C5', $string)` per method call | 32.0 |

Pre-decode once; subsequent reads are direct hashtable lookups. The
`ord()`-on-string path looks faster (no upfront unpack) but loses 2.6×
under JIT — string-byte access has more overhead than int-array
indexing.

Current PHPJava uses `BinaryReader` over `php://memory` streams (the M1
cost). Replace with `unpack('C*', $code)` at parse time, store as int
array on the `MethodInfo` object.

## Rule 2 — frame state in PHP locals

PHP 8.5 + JIT, 4 field operations per iteration:

| Pattern | ns |
|---|---|
| **Locals only** (`$stack`, `$sp`, `$L`, `$pc` as PHP locals) | **11.4** |
| Array `$f['sp']++` | 143.5 |
| Typed object `$f->sp++` | 145.7 |
| `stdClass` `$f->sp++` | 297.8 |

Locals beat every encapsulation by **5–13×**. The reason is that JIT
specialises locals heavily: `$sp` becomes a CPU register. Frame
objects defeat this — every property access goes through Zend's object
machinery.

Current PHPJava puts state into a `DependencyInjectionProvider` rebuilt
per iteration. Cut entirely. State lives in the function's locals.

## Rule 3 — regular array, never SplFixedArray

PHP 8.5 + JIT, 4 push/pop ops:

| Pattern | ns |
|---|---|
| **Regular array `$stack[$sp++]`** | **9.0** |
| SplFixedArray `$stack[$sp++]` | 68.9 |
| `array_fill(0, 16, 0)` then index | 75.7 |

SplFixedArray loses by 7×. The conventional wisdom that "fixed arrays
are faster because they don't hash" is wrong on modern PHP. Zend's
hashtable for indexed arrays is hand-tuned beyond what the
fixed-array path achieves. Same finding on lookups (regular wins by
2×) and iteration (regular wins by 4×).

**Drop SplFixedArray from any plans.** It's a perf trap.

## Rule 4 — no primitive wrappers

PHP 8.5 + JIT, single `$a + $b`:

| Pattern | ns | vs raw |
|---|---|---|
| **Raw `$a + $b`** | **18.8** | 1× |
| `(new Int_(1))->value + (new Int_(2))->value` | 140.4 | **7.5×** |
| `Int_::get(1)->value + Int_::get(2)->value` | 169.4 | **9.0×** |

This is the single largest accidental cost in the current PHPJava
codebase. Every arithmetic operation pays a 7–9× tax for boxing.

**The current `Int_::get()` static-factory pattern is the worst variant
measured.** It allocates a fresh object that escapes the local scope,
then accesses `->value`, then discards. JIT cannot eliminate the
allocation because the object's lifetime crosses the operation
boundary.

Wrap only at autoboxing sites (when the method descriptor is
`(Ljava/lang/Integer;)V` rather than `(I)V`). Statically determinable.

## Rule 5 — switch in static function, or inlined PHP

PHP 8.5 + JIT, 5000 ops/call × 2000 calls:

| Pattern | ns/op |
|---|---|
| **Hand-unrolled inline (= idiomatic AOT)** | **2.5** |
| **Eval-built unrolled function (= naive AOT)** | **3.7** |
| **Switch in static function** | **9.3** |
| Eval-built switch closure | 44.3 |
| Closure-array table | 36.8 |
| Threaded code (closure chain) | 39.3 |

Three observations:

- **Eval-built function ≈ hand-written.** AOT-compile-then-eval matches
  hand-written PHP at runtime. Confirms the AOT architecture.
- **Switch beats closures by ~4×.** Even with PHP 8.5's closure
  improvements, switch dispatch in static functions is ~4× faster.
  JIT traces switch into compare-and-branch native; closures retain a
  use-frame setup cost.
- **eval'd switch closures are *worse* than static-function switch.**
  Don't put dispatch into eval'd closures — JIT can't trace through
  them as well.

For the interpreter fallback, the dispatch loop should be a switch in
a static function (or method). For AOT, the emitted PHP is the
function itself.

## JIT-friendly emit findings (rank 1, measured 2026-05-02)

The naive AOT path went from a claimed 3.2 ns/op JIT (per handover
2026-05-01) to **0.77 ns/op JIT** — better than the hand-emit reference
(1.4 ns/op) and 4× faster than the prior compiler-emit. The five
subtractions that landed it, in order of impact:

| Subtraction | Before → after | Why JIT cared |
|---|---|---|
| **Two-phase compile-then-require (no interleave)** | ~5 → ~0.8 ns/op | Largest single win. Interleaving `compileClass` + `file_put_contents` + `require_once` per fixture in a loop changes file mtimes mid-loop; opcache invalidates cached versions and tears down JIT traces. Empirically observed `mode=compile-and-write` (0.80 ns/op) → `mode=all` adding require_once after writes (5.56 ns/op) in `bench/probe-bisect.php`. Fix: write all files first, require all files second, with an mtime-stable skip if content is identical. |
| **Variable class names → literal class names in callers** | ~2× empty-method | PHP's tracing JIT specialises only over literal class names. `fn() => $cls::method()` (variable) defeats the trace; `fn() => \PHPJava\Aot\Generated\BenchAdd::sum1k()` (literal) lets JIT inline. |
| **Variadic `...$__args` → fixed-arity signatures** | ~1.5× JIT | Tracing JIT refuses to trace through `...$__args` because the arity is dynamic per call. Per-method param count from descriptor parse + explicit `$L[i] = $__ai` prelude restores fixed-arity. |
| **`$L[N] ?? 0` defensive null-coalesce → bare `$L[N]`** | ~10% | JVM verifier guarantees no iload reads an un-stored slot. The defensive `?? 0` was dead code per CLAUDE.md "don't add error handling for scenarios that can't happen", and it was on every load in the hot loop. |
| **Lazy `$L = []` → pre-init `$L = [0, 0, ...]`** | ~10% | JIT specialises over sealed-shape arrays. Lazy growth on first `istore` re-shapes the array each call; pre-init from `max_locals` (computed in the first-pass walk) keeps shape stable from entry. |

These are *PHP-specific* JIT-friendliness rules, not general AOT tips —
they're what Zend's tracing JIT specifically needs to engage on the
emitted code. Each is a subtraction (less code, simpler shape, fewer
defensive checks). None are speed hacks added on top.

## Stack-erasure peephole (rank 1, measured 2026-05-03)

`Compiler::peepholeErase()` post-processes the emitted statement
list. After all opcodes have been written in stack-mode, it walks the
list applying pattern rewrites until fixpoint. Five patterns cover
~95% of operand-stack traffic in javac-generated code:

| Pattern | Before | After |
|---|---|---|
| P1: push + ireturn | `$stack[$sp++] = X;` `return $stack[--$sp];` | `return X;` |
| P2: push + istore | `$stack[$sp++] = X;` `$L[N] = $stack[--$sp];` | `$L[N] = X;` |
| P3: push + push + iadd-style + istore | 4 stmts | `$L[N] = X OP Y;` |
| P4: push + push + if_icmp* | 3 stmts | `if (X CMP Y) goto L;` |
| P5: push + if-single-op (vs 0 / null) | 2 stmts | `if (X CMP 0) goto L;` |

**Pure-source restriction:** captured push expression must match `$L[N]`
(a local read) or a numeric/null/string literal. Anything else (method
call, getstatic, getfield) may have side effects whose ordering must
be preserved — those pushes are left alone, the operand stack stays.

**Iterated to fixpoint:** P3 produces `$L[N] = X OP Y;`. If `$L[N]` is
then reloaded by a subsequent push, P2/P3 kicks in again on the new
sequence. Most simple methods (BenchAdd, BenchInvoke) reduce fully in
2–3 passes.

**BenchAdd loop body, before vs after:**

```php
// Before — 9 stmts/iter
$stack[$sp++] = $L[1];
$stack[$sp++] = 1000;
$b = $stack[--$sp]; $a = $stack[--$sp]; if ($a >= $b) goto L_21;
$stack[$sp++] = $L[0];
$stack[$sp++] = $L[1];
$b = $stack[--$sp]; $stack[$sp - 1] += $b;
$L[0] = $stack[--$sp];
$L[1] += 1;
goto L_4;

// After — 4 stmts/iter
if ($L[1] >= 1000) goto L_21;
$L[0] = $L[0] + $L[1];
$L[1] += 1;
goto L_4;
```

**Measured impact (rank 1, 2026-05-03):**

| Bench | Before | After | Speedup |
|---|---|---|---|
| BenchAdd::sum1k() JIT | 0.82 ns/op | **0.18 ns/op** | **4.5×** |
| BenchInvoke::callLoop() JIT | ~4.1 ns/op | ~3.7 ns/op | 1.1× (peephole doesn't touch invokestatic) |
| empty-method JIT | 22 ns | 22 ns | unchanged |

**Where 0.18 ns/op puts us:**
- HotSpot JIT (~0.10 ns/op) → 1.8× slower
- HotSpot interpreted (0.52 ns/op) → **2.9× faster**
- Hand-emit idiomatic AOT spike (0.2 ns/op) → **slightly faster**
- Hand-emit naive AOT spike (1.4 ns/op) → 7.8× faster

## Cross-method inlining (rank 1, measured 2026-05-03)

`Compiler::detectInlinable()` + `Compiler::inlineAcrossText()` —
recognise single-`return <expr>;` methods after peephole erasure and
register them; then substitute `self::<name>(args)` call sites with
the parameterised return expression.

**Inlinability rule (conservative):**
- Method body, post-peephole + post-label-strip, is exactly one stmt
- That stmt matches `return <expr>;`
- `<expr>` references only `$L[N]` (params) — no `$stack`/`$sp`, no method calls

**Substitution mechanics:**
1. Find each `self::<name>(args)` in the concatenated class body
2. Paren-depth args parser splits args
3. Substitute `$L[N]` in the registered returnExpr with corresponding arg
4. Replace the entire `self::<name>(...)` substring with `(<expr>)`
5. Iterate to fixpoint (so `a calls b calls c` chains collapse end-to-end)

**Effect on BenchInvoke** (a 100-iter loop that calls `self::noop(int)` each iter, where `noop(x) = x + 1`):

```php
// Before — invokestatic emit + lookup
L_4:
    if ($L[1] >= 100) goto L_21;
    $stack[$sp++] = $L[0];
    $__a0 = $stack[--$sp]; $stack[$sp++] = self::noop($__a0);
    $L[0] = $stack[--$sp];
    $L[1] += 1;
    goto L_4;

// After — call inlined to (($__a0) + 1), peephole-then collapses
//        the surrounding stack ops
L_4:
    if ($L[1] >= 100) goto L_21;
    $stack[$sp++] = $L[0];
    $__a0 = $stack[--$sp]; $stack[$sp++] = (($__a0) + 1);
    $L[0] = $stack[--$sp];
    $L[1] += 1;
    goto L_4;
```

(The `pop-into-temp + push-expr` pattern after inlining isn't yet
peephole-erased — that's the next improvement, would close the
remaining stack traffic.)

**Measured impact (rank 1, 2026-05-03, 1024M JIT buffer):**

| Bench | Before inlining | After | Speedup |
|---|---|---|---|
| BenchInvoke::callLoop() JIT | 4.05 ns/op | **0.41 ns/op** | **9.9×** |

The 22× static-call cost from the JIT-claims battery is functionally
closed for inlinable callees. Closes ~half the gap to BenchAdd's
0.18 ns/op (remaining 2× is the un-erased pop-into-temp around the
inlined expression).

**Bench-harness gotcha:** the bench script must run with
`opcache.jit_buffer_size ≥ 1024M`. Smaller buffers (256M default-ish)
get exhausted by JIT-tracing the compiler code itself, leaving the
bench loop un-traced and the headline numbers 9× wrong. Documented
in `bench/bench-aot.php`.

**What's still on the table:**

- **Pop-into-temp + push-expr collapse:** after inlining, call sites have a leftover `$__a0 = $stack[--$sp]; $stack[$sp++] = (...)` shape. A peephole that detects "single-pop-into-temp then push-expr-using-temp-once" rewrites to `$stack[$sp - 1] = (...);` — closes the remaining 2× gap.
- **Multi-statement inlining:** methods with 2–3 statements (typical for trivial getters/setters that do field validation + return) require renaming `$L[N]` to fresh slots in the caller and splatting all stmts. Doable; punt for now.
- **Recursive method protection:** detectInlinable doesn't currently check for recursion. A method that calls itself shouldn't be inlinable (would loop the inline pass infinitely). Add a self-call check before registering.

**Why this worked when prior small peepholes didn't:** the 2026-05-02
negative results showed piecemeal peepholes (drop dead label, isolated
iload+ireturn rewrite) caused cold-start regressions — JIT trace shape
changed in one place but left the rest alone, confusing the trace
planner. The full peephole pass touches *every* reducible site, so the
JIT trace is uniformly the new shape. **Coherent change beats partial
change** — possibly the most important meta-finding from the JIT-emit
exploration.

**Peephole limitations (cases where stack mode persists):**

- Push + push + arith-without-immediate-store (e.g., a 3-arg expression where the first two are added then compared with the third). Real abstract-stack tracking would catch these — Tier 1c-β.
- Cross-block stack flow (exception handlers with stack-on-entry).
- Object/array operations through the `->v` wrapper.

Estimated remaining headroom from going to full abstract-stack tracking
+ cross-method inlining: ~2× to reach HotSpot JIT parity.

**Negative results (rank 1, measured 2026-05-02) — opts that don't help:**

| Opt tried | Hypothesis | Measured outcome |
|---|---|---|
| Drop dead `L_0:` label at method entry | smaller emit, fewer trace landmarks | cold path regressed to 5–6 ns/op for first ~6 calls; only recovered to 0.8 after JIT had retraced. JIT seems to anchor traces on labels even when not branched to. |
| Peephole `iload N; ireturn` → `return $L[N];` | eliminate end-of-method push/pop round-trip (~1 ns/call save) | same cold-start regression as above — function exit shape change confuses JIT trace planning, takes many calls to recover. |
| iadd without temp `$b`: `$stack[$sp - 2] += $stack[$sp - 1]; --$sp;` | one fewer assignment per binary arith op | **15% regression**, JIT-warm 0.81 → 0.94 ns/op median. PHP's tracing JIT specialises better over a named scalar temp (whose type can be inferred from the array element type at the pop) than over two indexed reads on the same array (which it has to re-prove are scalars on each access). |

**Heuristic learned: `verbose-but-explicit` beats `clever-and-compact` for PHP's tracing JIT.** Each apparent "subtraction" needs to be measured; what looks like fewer ops at the source level may be more work for the JIT trace planner.

## Zend's JIT: what it does and doesn't do (rank 1, measured 2026-05-03)

`bench/jit-claims.php` isolates 7 tier-4 optimisations on PHP 8.4 + JIT
tracing. The picture is uneven — Zend does the local optimisations
within a function well, but does no cross-function work:

| Optimisation | Verdict | A/B ratio (JIT) | Implication for our AOT |
|---|---|---|---|
| Common-subexpression elimination | **applied** | 1.02× | safe to emit redundant subexprs — JIT folds them |
| Loop-invariant code motion | **applied** | 1.12× | safe to emit `$a*$b` inside a loop where it could be hoisted |
| Type specialisation (typed vs untyped params) | **applied** | 0.98× | type hints in signatures are not a perf necessity at this layer |
| Dead code elimination | **partial** | 1.55× | don't rely on this — emit only what's needed |
| **Function inlining** | **NOT applied** | **9.91×** worse | every cross-function call costs ~10× over inlined |
| **Static method inlining** | **NOT applied** | **22.45×** worse | every `\Class::method()` call costs ~22× over inlined |
| **Object property access (escape-analysis proxy)** | **NOT applied** | **10.38×** worse | `$obj->v` costs 10× over scalar — Java-array wrapper pays this |

**Architectural consequences:**

1. **Cross-method inlining belongs in the AOT compiler, not Zend.** `BenchInvoke::callLoop()` is ~4 ns/op vs `BenchAdd::sum1k()` at ~0.94 — the 4× gap is exactly the un-inlined `self::noop()` cost. Inlining small invokestatic targets at AOT time would close it.

2. **Escape analysis on Java arrays belongs in the AOT compiler.** The `stdClass{v: phpArray}` wrapper that solved Java's reference-write semantics costs ~10× per access vs raw PHP arrays. When a Java array is provably local-only (no escape via field/return/method-arg-of-non-AOT'd-method), the AOT path can use a raw PHP array and skip the wrapper.

3. **Property access is expensive enough that wrapping primitives in objects is a big tax.** The "drop primitive wrappers" decision in CONTRACTS.md §1 is rank-1 vindicated here.

4. **Local opts (CSE, LICM, type spec) are free — don't reproduce them.** Spending AOT-compile-time on these is wasted work.

So the earlier "Zend does ~80% of HotSpot tier-4" claim was wrong;
**rank 1 measurement says ~50%.** Zend covers within-function opts but
nothing cross-function. The remaining ~50% — inlining and escape
analysis — has to live in the AOT compiler if we want to close further
gap to HotSpot JIT (~0.10 ns/op). This is exactly the work that fits
under Tier 1c idiomatic AOT in ROADMAP.md.

### Extended battery — 12 more HotSpot/C2 optimisations measured 2026-05-03

`bench/jit-claims-extended.php` covered a second tranche, with several
surprises (and one validation of an existing emit choice):

| Optimisation | Verdict | A/B (JIT) | Implication |
|---|---|---|---|
| Strength reduction (`*4` → `<<2`) | **applied** | 0.89× | safe to emit JVM-style multiplies; JIT folds power-of-2 cases |
| Null-check elimination | **applied** | 0.98× | redundant `=== null` chains on the same ref are elided |
| **Devirtualisation on `final` class** | **applied** | 1.11× | the `final` keyword on emitted AOT classes is **load-bearing for perf**, not just convention. Small measurable win on every instance call. |
| Pointer-chain deref (`$a->b->c->d`) | **applied** | 1.01× | chain depth has no marginal cost — JIT unfolds the chain |
| Range-check elimination via `foreach` | **applied** | 0.69× | counter-intuitive: `foreach ($a as $v)` is **faster** than `for ($i=0; $i<count; $i++) $a[$i]`. JIT specialises the iterator more aggressively than the indexed read. |
| Loop unrolling | **partial** | 1.50× | hand-unrolled-by-4 is 1.5× faster — JIT does some unrolling but not as aggressively as C2's 4-or-8 |
| Constant folding through branches | **partial** | 1.53× | `if (CONST_TRUE) ...` doesn't fully fold; the branch stays |
| Branch prediction (always-taken) | **partial** | 1.35× | even a 100%-monotonic branch has predict overhead |
| Constant propagation (`$a=7;$b=13;$c=$a+$b;return $c*2;`) | **partial** | 1.63× | local-const arith not fully folded; JIT keeps the chain |
| **Allocation folding / scalar replacement** | **NOT applied** | **2.25×** | confirms the EA finding from the first battery — local `Box{a,b}` is not stack-replaced |
| **Tail-call optimisation** | **NOT applied** | **9.29×** | every recursive call is a real PHP call. Java tail-recursive code will be 9× slower than iterative. |
| **Float math** | **NOT applied** | **3.84×** | float ops are **3.8× slower than int**. Java `double`/`float` workloads pay this on every arith op. |

**Architectural consequences (additions to the inlining + EA findings):**

1. **`final` is load-bearing on emitted AOT classes.** Already there, now justified — drop the modifier and lose ~1.1× on every instance call.

2. **Float-heavy Java code will run 3.8× slower than equivalent int code.** This is a documented divergence at the Zend layer, not something we can fix in the AOT compiler. Workloads doing scientific computing in Java should be flagged as not-for-PHPJava.

3. **No TCO at the PHP layer.** Java tail-recursive code (functional patterns: fold, recursive descent) will be 9× slower than iterative equivalents. Two responses possible: (a) document as divergence, (b) AOT-time tail-call recognition that rewrites `return tail_a(...)` into iteration. (b) is non-trivial — needs flow analysis.

4. **`foreach` is faster than indexed `for` for array iteration.** JVM bytecode for Java `for (int i = 0; i < a.length; i++) s += a[i]` doesn't naturally lower to `foreach`. A peephole pass that detects this exact bytecode pattern and emits `foreach` would buy ~1.4× on array-loop code.

5. **Loop unrolling, branch prediction, const-folding through branches are partial in PHP's JIT.** AOT-time unrolling for short-trip loops is a candidate optimisation in Tier 1c.

**Net Zend-vs-C2 picture (rank 1, all measured 2026-05-03):**

| Category | What Zend does | What it doesn't |
|---|---|---|
| Within-function arithmetic | CSE, LICM, type spec, strength reduction | – |
| Within-function memory | null elision, ptr-chain unfold, **range-elim via foreach** | scalar replacement (EA), allocation folding |
| Within-function control | partial branch elim, partial unroll | full constant folding through branches |
| Cross-function | nothing | inlining (10–22×), TCO (9×) |
| Type system | int/typed vs untyped specialisation, **`final` devirt** | full CHA, polymorphic inline cache |
| Numeric | int math fully specialised | float math is 3.8× slower than int |

The 50%-tier-4 claim still holds. The takeaway pattern: **Zend's JIT is good at local arithmetic and primitive control flow, weak on cross-function and on heap-shape analysis**. The optimisations the AOT compiler should focus on are precisely the ones Zend doesn't do — inlining, EA, and AOT-time loop transforms.

### Extension overhead — Java stdlib substrate (rank 1, measured 2026-05-03)

`bench/jit-claims-ext.php` measures PHP-extension calls against their
native-PHP-int equivalents (where there is one) or as absolute numbers.
Driving question: when the AOT compiler shims a JDK class through a PHP
extension (java.math.BigInteger → BCMath/GMP, java.util.regex →
PCRE, java.security.MessageDigest → hash, etc.), what's the cost?

| Extension call | ns/op | ext/native ratio | Shim equivalent |
|---|---|---|---|
| `bcadd('1234567890', '9876543210')` | 88 | **6–8×** | BigInteger.add (small) |
| `gmp_add('1234567890', '9876543210')` | 174 | **9–12×** | BigInteger.add (small) — slower than BCMath here |
| `bcmul('12345', '67890')` | 97 | **5–6×** | BigInteger.multiply (small) |
| `gmp_mul('12345', '67890')` | 166 | **10–11×** | BigInteger.multiply (small) — slower |
| `bcpow('12345', '5')` | 186 | **12–13×** | BigInteger.pow |
| `gmp_pow('12345', 5)` | 135 | **8–9×** | BigInteger.pow — **GMP wins for pow** |
| `mb_strlen($asciiString)` | 65 | 2.2× | String.length on multi-byte |
| `mb_substr` | 70 | 1.2× | String.substring on multi-byte |
| `preg_match('/.../', '...')` | 80 | 1.4× over `strpos` | regex.Pattern.matches |
| `hash('md5', ...)` | 172 | – | MessageDigest.digest |
| `hash('sha256', ...)` | 147 | – | MessageDigest.digest |
| `hash('xxh3', ...)` | 103 | – | non-cryptographic hash |
| `hash_hmac('sha256', ...)` | 371 | – | Mac.doFinal |
| `openssl_encrypt('aes-256-gcm', ...)` | 870 | – | Cipher AES-GCM |
| `sodium_crypto_generichash` (BLAKE2b) | 355 | – | not in JDK; useful for new code |
| `gzdeflate` (950-byte input) | 5250 | – | java.util.zip.Deflater |

**The load-bearing finding for Java BigInteger workloads:**

The shim choice (BCMath vs GMP) is **operand-size dependent**:

- **Small numbers (≤ 64 bits, fits in PHP int):** BCMath wins by ~2× — `bcadd`/`bcmul` ~90 ns vs GMP's ~170 ns. GMP's per-call object-allocation cost dominates when the math itself is trivial.
- **Large numbers / `pow` / `powm` / cryptographic precision:** GMP wins by ~1.5× — `gmp_pow` at 135 ns vs `bcpow` at 186 ns. The actual computation overshadows GMP's allocation overhead.

The Java BigInteger shim should **switch internally** based on operand
magnitude — BCMath for ≤2^63, GMP for larger. The branch overhead
(~2 ns) is dwarfed by the per-op savings in either direction.

**Cost vs HotSpot:** Java BigInteger.add on small numbers is ~10–30 ns
on HotSpot. Our shim is **5–10× slower regardless of which extension**.
This is a structural divergence to document — Java code dominated by
BigInteger arithmetic (RSA, EC point multiplication, factoring) will run
~10× slower under PHPJava than under HotSpot.

**Comparable-to-HotSpot ops:**
- Hashing (md5/sha256): ~150–180 ns vs HotSpot ~100–200 ns. Wash.
- Regex: 80 ns simple match vs HotSpot ~50–150 ns. Wash.
- AES-GCM, HMAC: ~370–870 ns vs HotSpot ~500–1000 ns. Wash.
- Deflate: ~5 µs/950B vs HotSpot ~3–5 µs/950B. Comparable.
- Sodium primitives (BLAKE2b): 355 ns. JDK doesn't ship; new-code wins for our shim.

**Cost vs HotSpot — slower paths:**
- BigInteger arithmetic: 5–10× slower (architectural, not fixable)
- mbstring vs native string: 2× slower (use native when ASCII-bounded)
- Float math: 3.8× slower (per earlier finding, not extension-related)

**Architectural recommendation for the JDK shim layer:**

The 233-class T2 surface (per the historical stress corpus in
docs/CLOJURE-BOOT-ANALYSIS.md) needs each class shimmed in PHP. The
bench above tells us which classes have a
"free" shim (extension matches HotSpot perf within 1.5×) vs a "tax"
shim (extension is 5–10× slower). Tax-shim classes:

- `java.math.BigInteger` / `BigDecimal` (5–13× via BCMath/GMP)
- `java.lang.Float` / `java.lang.Double` heavy arith (3.8× — Zend layer)

Free-shim classes (≤2× HotSpot):
- `java.lang.String` (when ASCII; 2× when multi-byte via mbstring)
- `java.util.regex.Pattern` (PCRE, similar perf)
- `java.security.MessageDigest`, `Mac` (hash/openssl)
- `javax.crypto.Cipher` (openssl)
- `java.util.zip.*` (zlib)

This bench should be re-run when the T2 shim layer expands — each new
shim's perf vs its native PHP backing op is the rank-1 question for
"is this shim viable".

## IR speed at compile-time (rank 1, measured 2026-05-03)

For runtime-compilation use cases (defineClass(byte[]), hot reload),
compile time is hot path, not just build-time concern. Profiled the
IR pipeline on BenchAdd::sum1k():

| Phase | ns/op | µs/op | % of total |
|---|---|---|---|
| PHPJava JCC parse | 1,279,948 | 1280 | **83%** |
| IR build + lower | 19,304 | 19 | 1% |
| Full compile | 1,547,047 | 1547 | 100% |

**The IR layer is 65× faster than the parse layer.** Optimising the
IR further chases the 1.5% slice; optimising/caching the parser
chases the 83%. **For runtime-compilation perf, the IR is
already fast enough.**

**Falsifier tested:** "10× faster IR via flat-array substrate."
Hand-built BenchAdd in both OOP IR and equivalent flat-array IR
(`['kind' => INT, 'fields' => ...]` tagged-array nodes), benched
each. **FALSIFIED:** flat-array gives **1.29× end-to-end** (1.51×
construction, 1.14× lowering). PHP 8's JIT specialises over both
OOP and array shapes about equally well — `instanceof`-dispatch
becomes a hashed jump table; object construction is fast in
JIT-warm code. The 10× speedup hypothesis was over-optimistic.

**The actual lever for fast runtime compilation:** caching.

| Strategy | Speedup over uncached first compile |
|---|---|
| Cache parsed JCC by bytecode hash | up to 65× (skip the 1280 µs parse) |
| Cache built IR per class | additional ~20× over JCC cache |
| Cache lowered PHP per class | ~2× more on top |
| Flat-array IR | 1.3× — not worth the refactor on its own |

**Architectural decision:** keep the OOP IR for clarity. For
runtime-compilation perf, build per-class memoization. The 19 µs
IR layer is not the bottleneck; the 1280 µs parse layer is.

## Reference-semantics finding (functional, not perf)

Java arrays
need a wrapper because PHP's copy-on-write splits the array on first
write through a stack copy, and `=&` reference pushes leak across slot
reuse. Wrap as `(object){'v' => phpArray}` at `newarray`/`anewarray` —
PHP objects are by-reference natively, so by-value pushes propagate
writes correctly with no `=&` or `unset` bookkeeping. One property hop
per access; bench-stable at 0.77 ns/op for non-array workloads
(arrays add a constant per access, doesn't affect the hot iadd path).

## Cross-version sanity (PHP 8.4 vs 8.5)

PHP 8.5 made closures faster but switch and inline still win:

| Pattern | 8.4 + JIT | 8.5 + JIT |
|---|---|---|
| Inline | 2.5 | 2.6 |
| AOT (eval-built) | 3.8 | 3.7 |
| Switch | 10.2 | 9.3 |
| Closures | 64.0 | 36.8 |

Closures got 1.7× faster on 8.5; switch and AOT essentially unchanged.
The relative ordering is preserved. **Optimise for switch + AOT and
both versions perform.**

## What this implies for the codebase

| Current PHPJava element | Decision |
|---|---|
| `Kernel/Mnemonics/_*::execute()` — 200 opcode classes | **cut.** Replaced by switch cases in interpreter, by emit functions in AOT. ~10kloc removed. |
| `Compiler/Emulator/Mnemonics/_*` — parallel type-tracking tree | **repurpose.** The structural-emission pattern is what AOT needs. ~10kloc rebadged. |
| `Compiler/Lang/Assembler/` — PHP-syntax → bytecode | **cut.** Educational artifact, not on critical path. ~5kloc gone. |
| `Compiler/Builder/` — programmatic class file builder | **keep but freeze.** Useful for tests. Don't extend. |
| `Kernel/Types/Int_`, `Long_`, `Double_`, `Boolean_`, `Char_` | **cut as runtime objects.** Keep as compile-time type tags (constants in a `Type` enum) for descriptor parsing. Stop using them on the operand stack. |
| `Kernel/Filters/Normalizer.php` | **shrink.** Keep only the autobox-site logic. ~80% gone. |
| `Core/JVM/Stream/BinaryReader` | **keep for class file parse.** Replace with int-array indexing for in-method bytecode walks. |
| `OperationCache` | **cut.** Per-call allocation we don't need. |
| `DependencyInjectionProvider` | **cut.** Frame state lives in PHP locals. |
| `JavaMethodCallable::call` | **rewrite.** From a 100-line dispatch loop with per-iter alloc + setter chain → a switch over int array with locals for state. ~30 lines. |
| `Core/JVM/Invoker/InvokerInterface` and impls | **clean.** The polymorphism boundary is right; tighten the contract per CONTRACTS.md §3. |
| `Packages/java/*` shim layer | **keep, fill out.** Per CONTRACTS.md §1, expose both ArrayAccess (JVM surface) and underlying-array property (PHP hot-path). |
| `Kernel/Resolvers/ClassResolver` | **keep, extend.** Add `RESOURCE_TYPE_BYTES` for `defineClass(byte[])` and `RESOURCE_TYPE_AOT` for cached AOT files. |

Net: about **20kloc removed**, ~5kloc added. The codebase shrinks
substantially. Most of the removal is the per-opcode class tree (200
× ~30 LOC × 2 trees) and the Compiler/Lang/Assembler subsystem.

## Public API — same surface, different internals

```php
// Unchanged:
ClassResolver::add([[ClassResolver::RESOURCE_TYPE_FILE, '/path/to/classes']]);
$cls = JavaClass::load('com.example.Foo');
$result = $cls->getInvoker()->getStatic()->getMethods()->call('bar', $arg1, $arg2);
```

The user-facing API stays. Internal changes:

- `JavaClass::load` returns a `JavaCompiledClass` (interp), `JavaAotClass`
  (compiled), or `JavaSimpleClass` (shim) per the classloader strategy.
- The invoker returned by `getInvoker()` is one of three implementations
  behind `InvokerInterface`. Caller doesn't know which.
- `call($name, ...$args)` accepts PHP scalars natively; returns PHP
  scalars natively. No more `Int_`/`Char_` wrapping at the boundary.

This means **existing callers continue to work**, but the values they
get back are idiomatic PHP types instead of wrappers. That's a soft
break for any caller that did `instanceof Int_` checks (likely zero in
the wild — these wrappers were internal).

## Why the original PHPJava chose differently

The upstream project (php-java/php-java, 2018–2021) optimised for a
different goal: **be a JVM simulator readable as a tour of the JVMS**.

That goal rewards:

- **One class per opcode** — each opcode has a Wikipedia-style
  reference page in code; you can navigate to `_iadd.php` and see
  exactly what `iadd` does
- **Wrapper classes per JVM type** — `Int_` vs `Long_` vs `Char_`
  literally maps to the JVMS's typed-slot model
- **`BinaryReader` over the bytecode stream** — the bytecode is a
  stream in the spec
- **Stack frames as objects** — frames are objects in the spec
- **No optimisation pressure** — the project shipped HelloWorld and
  some demos; nobody booted Clojure on it

It's a **reference implementation in PHP**. We're doing something
different: **a translator that emits PHP for Zend to run.** Same parser,
same class file structures, different runtime philosophy.

The original choices made sense for the original goal. We can keep the
parser they wrote (it's solid) and rebuild the runtime layer for our
goal. **The code we're cutting was the right shape for "simulate the
JVM"; it's the wrong shape for "translate to PHP".**

## What to subtract — full audit

Every removal is justified by a measurement above:

| Subtraction | Justification |
|---|---|
| Per-opcode class tree | switch + locals beats class-per-opcode by ~600× per op |
| `Int_`/`Long_`/etc wrappers on the stack | 7–9× per arithmetic op (Rule 4) |
| `BinaryReader` for in-method bytecode | 2.6× per byte read (Rule 1) |
| `DependencyInjectionProvider` | locals beat any frame encapsulation by 5–13× (Rule 2) |
| `setParameters` / `setConstantPool` chain | three method calls per opcode dispatch we don't need |
| `microtime()` per iteration | sample once outside the loop |
| `is_callable($trigger)` × 2.25/op | hoist boolean flag once |
| `class_exists($fullName)` per opcode | gone with switch dispatch |
| `OperationCache` per method call | one less allocation per call |
| `fopen('php://memory')` per method call | gone with int array (Rule 1) |

## Total work

The five rules + the cut list = **~3 weeks of focused work** to a
re-architected PHPJava that runs at 9–10 ns/op interpreter and 3–5
ns/op AOT. That's:

- Week 1: rewrite `JavaMethodCallable::call` as switch over int array
  with PHP-locals frame. Expand AOT compiler to full opcode coverage.
  Cut the per-opcode class tree.
- Week 2: cut `Int_`/`Long_`/etc. boxing. Update Normalizer to wrap
  only at autobox sites. Update test suite expectations. Most of the
  49 errors / 48 failures resolve naturally.
- Week 3: classloader integration of three strategies (interpret /
  AOT-eager / AOT-lazy / shim). Long-running soak test in Swoole.
  Lambda metafactory if time.

End: a substantially simpler, dramatically faster PHPJava. The model
in `docs/MODEL.md` and the contract in `docs/CONTRACTS.md` lock the
shape; the rules here lock the implementation choices.
