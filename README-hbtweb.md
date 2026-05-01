# PHPJava — hbtweb fork

> Active branch: `roadmap` — the strategic-rework branch, not yet merged
> to master. Upstream PHPJava (php-java/php-java) at `b34a90a` (2021).

This fork takes the original PHPJava project (a JVM bytecode interpreter
in PHP) and pursues a different goal: **a JVM-bytecode-to-PHP translator
for long-running PHP processes**, with selectable cache strategies (eager
AOT / lazy AOT / interpret-fallback) sharing one compiler.

The architectural model is in [`docs/MODEL.md`](docs/MODEL.md). Read it
first.

## What's working

- ✓ Test suite unblocked on JDK 25 (`javac --release 11`)
- ✓ Bench harness via FFM (calls libphp directly from JVM)
- ✓ Profile harness via xhprof (LD_PRELOAD trick documented)
- ✓ Real AOT compiler — walks PHPJava's parsed bytecode, emits PHP, runs
- ✓ AOT-compiled `BenchAdd::sum1k` — **14 ns/op no-opt, 3.2 ns/op JIT —
  468× faster than current PHPJava**
- ✓ Architectural contracts spec ([`docs/CONTRACTS.md`](docs/CONTRACTS.md))

## What's next (3–6 week target)

1. Value-representation refactor (drop primitive wrapper objects)
2. Test suite to 100% (most failures are value-rep symptoms)
3. Full opcode coverage in the AOT compiler (~200 opcodes, ~5 LOC each)
4. AOT classloader integration (wire `AotEager` / `AotLazy` into `JavaClass::load`)
5. Lambda metafactory (the biggest capability unlock for modern Java)
6. `defineClass(byte[])` — enables Clojure-on-PHPJava
7. Long-running soak test in a Swoole daemon

End-of-six-week milestone: **AOT-compile a small real Java library, call
it from PHP code with idiomatic types at the boundary.**

## Document map

Strategic / why:
- [`docs/MODEL.md`](docs/MODEL.md) — architectural model (the keystone)
- [`docs/ADJACENT-SHAPES.md`](docs/ADJACENT-SHAPES.md) — TeaVM, bb, cljp, where we sit

Specs / what:
- [`docs/CONTRACTS.md`](docs/CONTRACTS.md) — interface boundaries, value-rep decisions (normative)
- [`docs/GAP-JDK.md`](docs/GAP-JDK.md) — Java 19 → 21 → 25 deltas

Empirical / measured:
- [`docs/STATUS.md`](docs/STATUS.md) — where we are right now
- [`docs/CLOJURE-BOOT-ANALYSIS.md`](docs/CLOJURE-BOOT-ANALYSIS.md) — empirical Clojure class-load trace
- [`bench/README.md`](bench/README.md) — benchmark methodology
- [`bench/profile-c930e2c.md`](bench/profile-c930e2c.md) — profile attribution

Operational / next:
- [`ROADMAP.md`](ROADMAP.md) — tier ordering, exit criteria

## Original PHPJava docs

The upstream README and docs (`README.md`, `docs/compiler/`, `docs/jvm-lang/`,
`README-ja.md`) cover the original project's design — the JVM bytecode
interpreter and the PHP-syntax-to-bytecode-compiler features. Those
remain as-is for reference.

## Contributing

This fork is in early architectural-rework phase. Before opening PRs:

1. Read `docs/MODEL.md` and `docs/CONTRACTS.md`. These are normative.
2. Check `docs/STATUS.md` for current state and ranked next steps.
3. PRs that violate contracts should update `docs/CONTRACTS.md` first
   with rationale + impact analysis.

## Branding note

The "PHPJava" name remains for now. The strategic ambition (long-running
deployments, AOT compilation, multi-language JVM substrate) is meaningfully
different from upstream's "fun JVM in PHP" demo. A name change may follow
once the architecture stabilises.
