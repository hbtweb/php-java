# xhprof profile of `BenchAdd::sum1k` @ c930e2c

xhprof adds ~5× overhead (per-op shows 243 µs vs measured 5.22 µs), so the
absolute timings here are inflated, but the **proportions** between
functions are load-bearing. Profile is over 10 runs of `sum1k` ≈ 80k JVM
bytecode ops total; raw JSON in `profile-c930e2c.xhprof.txt`.

## Top per-op cost centers (calls/op assuming ~80k ops total)

| Function | Calls | Calls/op | µs/call (under xhprof) | Notes |
|---|---|---|---|---|
| `JavaClassMethodInvoker::call` | 10 | — | 567,551 | Inclusive root — the interpreter loop itself |
| `AbstractOperationCode::setParameters` | 90,090 | ~1.1 | 7.34 | **H4** — the 3-method setter chain on every opcode |
| `BinaryReader::readUnsignedByte` | 100,090 | ~1.25 | 5.18 | Bytecode read (via temp file stream — **M1**) |
| `Map::getName` | 90,110 | ~1.1 | 5.64 | Mnemonic name lookup (**H3** related) |
| `GlobalOptions::get` | **360,410** | **~4.5** | 1.41 | **Configuration lookup called 4.5× per op!** |
| `_iinc::execute` | 10,000 | 0.125 | 36.10 | Actual opcode body |
| `BinaryReader::read` | 140,110 | ~1.75 | 2.25 | Mid-level read helper (calls `fread`) |
| `_if_icmpge::execute` | 10,010 | 0.125 | 28.81 | Actual opcode body |
| `JavaClassInvoker::getJavaClass` | 90,130 | ~1.1 | 2.56 | Per-op invoker lookup |
| `_goto::execute` | 10,000 | 0.125 | 21.77 | Actual opcode body |
| `_iadd::execute` | 10,000 | 0.125 | 17.46 | Actual opcode body |
| `Normalizer::getPrimitiveValue` | 50,020 | ~0.6 | 2.81 | **H7** — unboxing |
| `_iload_1::execute` | 20,010 | 0.25 | 7.12 | Actual opcode body |
| `is_callable` | 180,180 | ~2.25 | — | **H8** — trigger callable checks per op (×2) |
| `fread` | 140,110 | ~1.75 | — | **M1** — actual file read |
| `unpack` | 140,110 | ~1.75 | — | Byte-to-int conversion per read |

## What this tells us

1. **The opcode bodies are not the bottleneck.** All `_*::execute` methods
   together account for ~10–15% of total time. The interpreter spends 85%+
   of its time on dispatch overhead.

2. **`GlobalOptions::get` is called 4.5× per bytecode op.** Each call does
   ~1.4 µs of work — that's ~6 µs/op of pure config lookup. Config doesn't
   change between ops; this is hoistable.

3. **`setParameters` is the largest single per-op cost** at 7.34 µs/call,
   called ~once per op. This is H4 in the roadmap (the 3-method setter
   chain on every fresh opcode object).

4. **File I/O via the temp stream is real cost.** `fread` 1.75 calls/op,
   `unpack` 1.75 calls/op, `BinaryReader::readUnsignedByte` 1.25 calls/op.
   Replacing the file-stream with byte-string indexing removes all of this
   — confirms M1 hypothesis.

5. **`is_callable` 2.25× per op** — the trigger-callable check on every
   iteration even when no callable is configured. **H8 confirmed.**

## Cost attribution (rough, scaled from xhprof %s to measured 5.22 µs/op)

| Layer | Fraction | µs/op |
|---|---|---|
| `setParameters` setter chain (H4) | ~14% | 0.73 |
| File I/O for bytecode read (M1) | ~15% | 0.78 |
| Mnemonic lookup `Map::getName` (H3) | ~10% | 0.52 |
| `GlobalOptions::get` ×4.5 (H1 / config) | ~14% | 0.73 |
| `is_callable` ×2.25 (H8) | ~5% | 0.26 |
| Per-iter object alloc `new $opcodeClass()` (H2) | ~10% | 0.52 |
| Other dispatch infrastructure | ~17% | 0.89 |
| Actual opcode bodies | ~15% | 0.79 |
| **Total** | 100% | **5.22** |

## Projected post-fix per-op cost

If the 7 attributable accidental costs above are eliminated:

- Removable: H1+H2+H3+H4+H8+M1 ≈ 0.73 + 0.78 + 0.52 + 0.73 + 0.26 + 0.52 = **3.54 µs/op**
- Floor: 5.22 − 3.54 = **~1.68 µs/op** (other dispatch + actual op bodies)

That's a ~3× speedup — meaningful but not the 10× the roadmap optimistically
projected. The opcode dispatch model itself has irreducible cost: PHP method
invocation, virtual dispatch, parameter passing, return value handling.

**Implication for falsifier F1:** the projected post-Phase-2 floor is
~1.5–2 µs/op, not the < 1 µs the roadmap targeted. **F1 fires.** AOT
compilation (Q3 in the roadmap) becomes the realistic path forward for
any goal that requires per-op cost approaching HotSpot interpreted.

## What an AOT path looks like

Translate each `.class` method into a single PHP method that emits the
same observable behaviour as walking the bytecode:

- Each JVM bytecode → 1–3 PHP statements (PHP-side stack as a small
  array of locals; literal arithmetic; direct branches via gotos)
- Method body: one PHP function per Java method, no dispatch loop
- INVOKEVIRTUAL on user classes → direct PHP method call
- INVOKEVIRTUAL on `java.*` shim → direct PHP shim call (already exists)
- Field access → array/property access

Per-op cost projection: PHP-native (~10–50 ns per typical statement),
which is ~50–250× HotSpot interpreted. A 100× total improvement over
the interpreter floor.

This is structurally the same architecture as cljp (Clojure→PHP),
applied to a different frontend (`.class` files instead of `.cljp`
forms). The PHP-side runtime is shared. Only the compiler frontend
differs.

**Cost estimate:** 4–8 person-months for an AOT compiler covering the
opcode set bb-style Clojure code uses (which is most of it but not all
— invokedynamic / lambda metafactory still need their own treatment).

This becomes the post-Phase-1 strategic decision: *do the interpreter
fixes anyway as a fallback for code that can't be AOT-compiled, and
build the AOT compiler in parallel as the production path?* Or *abandon
the interpreter route entirely and put everything into AOT?* The
profile says the latter is the higher-leverage call.
