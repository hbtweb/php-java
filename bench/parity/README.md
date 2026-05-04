# Parity oracle harness — Path D′

> ROADMAP §Build #1 — the v1-critical-path prerequisite for
> bb-allowlist non-stub fill. Provides rank-1 evidence that a
> PHPJava JDK-shim implementation behaves identically to the real
> JDK on a defined input set. License posture per `docs/LAYERS.md`
> §License posture: OpenJDK is used as a *test oracle*, not as a
> *source*; captured I/O traces aren't copyrightable.

## Scaffold status

This directory holds the harness infrastructure. As of 2026-05-04
the PHP-side runner is in place; the Clojure-side driver builds on
`bench/baseline.clj`'s FFM transport but isn't yet ground out into
a full per-class battery.

```
bench/parity/
├── README.md              ← this doc
├── oracle-runner.php      ← PHP-side runner; invokes PHPJava AOT
│                            method, captures (return, exception,
│                            stdout, side-effect snapshot)
└── cases/                 ← per-class case batteries
    └── (none yet)
```

The Clojure-side oracle driver lives in cljp (or a dedicated `bench/parity-driver/`
deps.edn project — TBD when the full harness ships) and uses
`cljp.transport.ffm` to:

1. Load a JDK class on real HotSpot, invoke its method, capture I/O.
2. Invoke `bench/parity/oracle-runner.php`'s entry function via
   `zend_eval_string` with the same args, capture I/O.
3. Compare. Emit a parity report per class.

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
