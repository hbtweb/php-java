# Parity oracle harness — Path D′

> ROADMAP §Build #1 — the v1-critical-path prerequisite for
> bb-allowlist non-stub fill. Provides rank-1 evidence that a
> PHPJava JDK-shim implementation behaves identically to the real
> JDK on a defined input set. License posture per `docs/LAYERS.md`
> §License posture: OpenJDK is used as a *test oracle*, not as a
> *source*; captured I/O traces aren't copyrightable.

## Status (2026-07-24)

Shipped and working. Rank 1: 11 hand-written batteries, **428/428
match** against OpenJDK 25 / PHP 8.5.8.

```
bench/parity/
├── README.md              ← this doc
├── deps.edn
├── oracle-runner.php      ← PHP side: invokes the PHPJava AOT method,
│                            captures (return, exception, stdout)
├── oracle_driver.clj      ← Clojure side: reflects the real JDK, runs
│                            both, compares per axis, reports
├── gen.clj                ← generates batteries by reflection (Phase 0a)
└── cases/                 ← per-class case batteries
```

Transport is `clojure.java.shell` into the PHP runner — one subprocess
per case, ~60 ms. The FFM transport sketched in `bench/baseline.clj` is
the optimisation for when battery size makes that bite; measured
2026-07-24, a 791-case generated battery takes 49 s wall.

Run one battery:

```
clj -Sdeps '{:paths ["bench/parity"] :deps {org.clojure/data.json {:mvn/version "2.5.0"}}}' \
    -M -m oracle-driver --case bench/parity/cases/java.lang.Math.json
```

Exit code 0 if all cases match, 1 if any diverged.

## Generating a battery

Hand-authored batteries have a structural weakness: the author of a
shim is usually the author of its test, so both can be wrong in the
same direction. `gen.clj` derives the battery from the JDK instead —
reflects the class, walks every public static method, draws arguments
from an edge-first per-type corpus.

```
clj -Sdeps '{:paths ["bench/parity"] :deps {org.clojure/data.json {:mvn/version "2.5.0"}}}' \
    -M -m gen --class java.lang.Integer [--out DIR] [--cap N] [--seed N]
```

Emits two files: `<FQN>.gen.json` (**seen** — work against this) and
`<FQN>.holdout.json` (**holdout** — same corpus, different seed,
disjoint draw; run at verify time only). The holdout is what stops a
shim that special-cases the visible inputs from showing green.

**Only `.gen.json` is committed.** `.holdout.json` is gitignored and
regenerated at verify time — generation is deterministic, and the seed
is recorded in the `_generated` block of the committed seen battery, so
the holdout is reproducible without ever being in the implementer's
reach. A committed holdout is a holdout that no longer tests anything.

Two limits, both reported rather than silent:

- **Static methods only.** Instance-mode generation (the `{new, ops}`
  shape the driver already supports) is not implemented.
- **Holdout can come back empty** when the input space is small enough
  that `seen` exhausts it (e.g. `java.lang.Boolean`). For those classes
  the verify-stage defence is an adversarial reader, not the holdout.

Methods whose parameters aren't expressible on the wire (`Comparator`,
`Supplier`, …) and non-deterministic methods (`random`, `nanoTime`, …)
are skipped **with a printed reason**.

## Why split into two halves

The Clojure half holds the *oracle truth* — real HotSpot, real JDK,
real method invocation. It's the fixed reference.

The PHP half (this directory) holds the *capture-from-PHPJava* logic
— invoke the AOT'd method, normalise return/exception/stdout shape,
serialise to JSON the Clojure side can parse.

Splitting them means the PHP runner can be tested in isolation
(`php bench/parity/oracle-runner.php --probe ...`) without the
JVM-side driver. It also means the JSON contract between the two
halves is the load-bearing surface; either side can change
internally as long as the JSON shape stays stable.

## JSON contract (PHP→Clojure)

```json
{
  "class": "java.lang.Math",
  "method": "abs",
  "args": [-42],
  "result": {
    "kind": "ok",
    "return": 42,
    "stdout": "",
    "stderr": ""
  }
}
```

For exception-throwing calls:

```json
{
  "class": "java.util.regex.Pattern",
  "method": "compile",
  "args": ["[a-z*"],
  "result": {
    "kind": "exception",
    "class": "java.util.regex.PatternSyntaxException",
    "message": "Unclosed group at index 5"
  }
}
```

For side-effecting calls (rare in stdlib; relevant for I/O classes):

```json
{
  "class": "java.io.PrintStream",
  "method": "println",
  "args": ["hello"],
  "result": {
    "kind": "ok",
    "return": null,
    "stdout": "hello\n",
    "stderr": ""
  }
}
```

The Clojure side runs the same call against the real JDK, captures
in the same shape, and a comparator does set-equality on the
normalised JSON.

## Comparator semantics

Per-axis comparison (the Clojure side runs these):

- `result.kind` strict eq (`ok` vs `exception`)
- `result.return`: deep-eq, with primitive normalisation (PHP int 42
  matches Java int 42; PHP float 1.5 matches Java double 1.5)
- `result.stdout` / `result.stderr`: strict-eq (whitespace matters
  for stream-flush semantics — `println` adds `\n`)
- `result.class` (exception case): strict-eq on Java FQN
- `result.message`: optional strict-eq; when JDK-version-sensitive,
  compare against a regex pattern provided in the case spec

## Case-spec format

Each case battery is a JSON file with:

```json
{
  "class": "java.lang.Math",
  "cases": [
    { "method": "abs", "args": [-42] },
    { "method": "abs", "args": [0] },
    { "method": "abs", "args": [2147483647] },
    { "method": "abs", "args": [-2147483648] },
    {
      "method": "addExact",
      "args": [2147483647, 1],
      "expect": { "kind": "exception", "class": "java.lang.ArithmeticException" }
    }
  ]
}
```

`expect` is optional — when present, the comparator validates against
both the JDK oracle output AND the explicit expectation, catching
JDK-version drift.

### Dispatch modes

A case spec takes one of three shapes:

- **Static** (default): `{method, args}` → `Class.method(args)`. JDK
  invokes the static method; PHP calls the shim statically.
- **Instance**: `{new: [...], ops: [{call, args}, ...]}` → construct,
  then run the op chain, comparing the last op's return. For
  instance-bearing classes (HashMap, ArrayList).
- **Receiver**: `{recv, method, args}` → an instance method on a value
  type. Java dispatches `recv.method(args)`; PHPJava carries the value
  as a bare PHP value and calls `Shim::method(recv, ...args)` — e.g.
  `"hello".charAt(1)` on the JDK side, `String_::charAt("hello", 1)` on
  ours. `gen.clj` emits these automatically for classes in its
  `value-types` set (currently `java.lang.String`), drawing the receiver
  from a small corpus of encoding-relevant strings.

The runner maps a dotted FQN to its shim, applying the reserved-word
remap the IR Builder uses (`java.lang.String` → `...\String_`, likewise
Object / Float / Void). An absent shim method in receiver mode surfaces
as a clean `UnsupportedOperationException` rather than a raw TypeError
out of the bytecode fallback, so battery output stays readable and marks
the method as bb-fill surface.

### Tagged argument shapes

Raw JSON can't express NaN, ±Infinity, or a typed array, so arguments
may carry three tagged forms — the same tags the return path already
normalises to, plus `__arr__`:

```json
{"method": "abs",    "args": [{"__nan__": true}]}
{"method": "min",    "args": [{"__inf__": -1}, 1.0]}
{"method": "equals", "args": [{"__arr__": "double", "v": [0.0]},
                              {"__arr__": "double", "v": [-0.0]}]}
```

`__arr__` element tags: `int long double float short byte char boolean
String Object`. A **bare JSON array keeps its legacy meaning** —
`Object[]`, for `Object...` varargs — so `Arrays.equals(double[],
double[])` is only reachable through the tagged form. That distinction
is load-bearing: it is what exposed the `+0.0` vs `-0.0` divergence the
37-case hand-written `Arrays` battery could not reach.

Decoding is implemented twice and must stay in lockstep — `decode-arg`
in `oracle_driver.clj` and `decodeArg()` in `oracle-runner.php`. If the
two drift, the sides silently compare different inputs.

## Building it out

The Clojure-side harness is the remaining piece. Order of work:

1. **Per-method invocation primitive** (~200 LOC Clojure) — wraps
   `java.lang.reflect.Method.invoke`, handles primitive boxing on
   args, captures stdout via `System.setOut(...)` redirect.
2. **PHP-side mirror** (this dir's `oracle-runner.php` — drafted) —
   loads PHPJava, invokes the equivalent method via the AOT path,
   serialises the result.
3. **FFM glue** — boots libphp inside the JVM, calls
   `oracle-runner.php`'s entry via `zend_eval_string` per case.
4. **Comparator** (~150 LOC) — per-axis equality with normalisation
   rules.
5. **Per-class driver** (~100 LOC) — loops case battery, runs
   oracle + PHPJava, diffs, reports.
6. **CI integration** — failing parity cases register as PHPUnit
   failures via a thin bridge.

Total ~750 LOC of Clojure harness atop the existing
`bench/baseline.clj` FFM transport. Reusable once-and-then-mechanical
across every bb-allowlist class — that's the leverage move.
