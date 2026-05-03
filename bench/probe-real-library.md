# AOT real-library probe — Apache Commons Lang 3.17.0

> Rank-1 evidence for STATUS.md "what's NOT verified" Open Question #1:
> does the IR Builder cover production Java bytecode (not just our 9
> hand-curated fixtures)?
>
> Probe: `bench/probe-real-library.php`. Setup: `apt install
> libcommons-lang3-java`. Date: 2026-05-03.

## TL;DR

Driving every `.class` in `commons-lang3-3.17.0.jar` (395 classes,
~3,769–4,166 methods depending on parser fixes) through
`Compiler::compileBytes()`. Series of probes; final result after the
2026-05-03 work-plan items #1–#4 landed:

| Pass | IR success | Top-level | Methods | Change |
|---|---:|---:|---:|---|
| Initial | 65.6% | 308/395 | 3769 | starting point |
| + 14 missing opcodes | 86.5% | 308/395 | 3769 | mechanical |
| + sub-step 1c-β (full abstract-stack tracking) | 99.3% | 308/395 | 3769 | the architectural one |
| + switch terminator (TABLESWITCH/LOOKUPSWITCH) | 99.9% | 308/395 | 3769 | new IR Switch_ |
| + lazy super-class load in parser | 99.9% | 394/395 | 4166 | parser fix |
| + rare-opcode tail (DUP2/IUSHR/LUSHR/MULTIANEWARRAY) | **100.0%** | **394/395** | **4166** | mechanical |

**Final: 100.0% IR coverage on every method of every class that
parses (4166 methods, 0 fallbacks).** Single remaining top-level
failure (1/395) is a PHPJava parser TypeError unrelated to AOT.

**The architecture holds.** No structural surprises in production
bytecode; the IR Builder's coverage gaps were either well-known JVM
opcodes absent from our 9 hand-curated fixtures, or the
already-roadmapped 1c-β work. The 1c-β perf headroom estimate was
~1.5×, but its **coverage** impact was 95% of remaining fallbacks
— a much larger lever than the original session estimated.

## Method

Per-class child-process invocation of `(new Compiler())->compileBytes()`.
Bytecode bytes are piped via stdin to keep arg lists short. With
`CLJP_IR_DEBUG=1` set, the IR fallback path logs `IR fallback for
{method}: {message}` to stderr — captured per child. Method count is
read from the emitted PHP via regex on `public static function `.

Fresh process per class isolates compile-cache state and avoids
cross-class CP-resolution carry-over. Child-process overhead is the
dominant cost (~50–100 ms/class); the actual compile is sub-millisecond
per class on average.

## Top-level failures (87/395 = 22%)

All `ClassNotFoundException` from PHPJava's parser eagerly resolving
constant-pool `ClassRef` entries during parse — *not* an AOT issue.
Failing classes reference *internal* commons-lang3 types
(`ToStringStyle`, `CharSequenceTranslator`, `StrMatcher`, etc.) that
the parser couldn't find on its classpath because the probe only
extracts the JAR; the parser's classloader lookup didn't see the
extracted root. Fixing this is a probe-setup change (or, separately,
moving PHPJava's parser to lazy resolution at constant-pool access
time) — orthogonal to IR Builder coverage.

## IR fallback histogram (before fixes — 1295 fallbacks)

| Cause | Count | % of fb | Decoded |
|---|---:|---:|---|
| Non-empty abstract stack at BB boundary | 367 | 28.3% | Sub-step 1c-β (known) |
| `0x01` ACONST_NULL | 322 | 24.9% | Missing opcode |
| `0xC0` CHECKCAST | 286 | 22.1% | Missing opcode |
| `0xC1` INSTANCEOF | 80 | 6.2% | Missing opcode |
| `0x22-0x29` F/DLOAD_n | ~110 | 8.5% | Missing opcodes (clones of int) |
| `0x91/92/93` I2B/I2C/I2S | ~44 | 3.4% | Narrowing conversions |
| `0x7A/78/79` shifts, `0x5A` DUP_X1 | ~25 | <2% | Stack/shift |
| Long-tail (FADD/DADD/FSUB/etc., switch, DUP2) | rest | <7% | Mechanical |

## IR fallback histogram (after fixes — 509 fallbacks)

| Cause | Count | % of remaining fb |
|---|---:|---:|
| Non-empty abstract stack at BB boundary | 485 | 95.3% |
| `0xAA` TABLESWITCH | 11 | 2.2% |
| `0xAB` LOOKUPSWITCH | 8 | 1.6% |
| `0x5C` DUP2 | 2 | 0.4% |
| `0x7D` LUSHR (unsigned long shift) | 2 | 0.4% |
| `0xC5` MULTIANEWARRAY | 1 | 0.2% |

## What was added (this probe round)

`src/Aot/Ir/Builder.php` — opcode coverage extensions, all in one pass
of the existing dispatch switch. No new IR node types; reuses
`NullLit`, `FloatLit`, `StringLit`, `IntLit`, `BinOp`, `StaticCall`.

| Opcode(s) | Mnemonic | Emit |
|---|---|---|
| `0x01` | ACONST_NULL | `push NullLit` |
| `0x0B-0x0D` | FCONST_0/1/2 | `push FloatLit(0/1/2)` |
| `0x0E-0x0F` | DCONST_0/1 | `push FloatLit(0/1)` |
| `0x22-0x25` | FLOAD_0..3 | `push LocalRead(N)` |
| `0x26-0x29` | DLOAD_0..3 | `push LocalRead(N)` |
| `0x43-0x46` | FSTORE_0..3 | `emitStore(N)` |
| `0x47-0x4A` | DSTORE_0..3 | `emitStore(N)` |
| `0x5A` | DUP_X1 | `{...,a,b} → {...,b,a,b}` |
| `0x62/63` | FADD/DADD | extended existing `BinOp '+'` case |
| `0x66/67` | FSUB/DSUB | extended existing `BinOp '-'` case |
| `0x6A/6B` | FMUL/DMUL | extended existing `BinOp '*'` case |
| `0x72/73` | FREM/DREM | `emitBinOpFn('fmod')` |
| `0x78/79` | ISHL/LSHL | `emitShift('<<', 0x1F/0x3F)` |
| `0x7A/7B` | ISHR/LSHR | `emitShift('>>', 0x1F/0x3F)` |
| `0x91` | I2B | `($v << 56) >> 56` (sign-extend low 8) |
| `0x92` | I2C | `$v & 0xFFFF` (unsigned 16-bit) |
| `0x93` | I2S | `($v << 48) >> 48` (sign-extend low 16) |
| `0xC0` | CHECKCAST | skip 2-byte CP idx; ref stays on stack |
| `0xC1` | INSTANCEOF | `\intval(\is_a($ref, '\\FQN', true))` |

Supporting helper added: `emitShift(string $op, int $maskBits)` —
applies JVM's required shift-count mask before the BinOp.

## Rank-1 verification

- All 9 existing fixtures PASS post-changes (BenchAdd, BenchInvoke,
  HelloWorld, BenchArray, BenchTryCatch, BenchConcat, BenchLambda
  (run + withCapture), BenchAddFromBytes).
- `bench/contract.php` reports `9 stable, 0 changed` — emit shape
  unchanged for the 9-fixture set.

## Notes on remaining gaps

**Sub-step 1c-β (485 fallbacks).** The IR Builder bakes operand-stack
erasure in via abstract-stack tracking during construction. When a BB
ends with non-empty abstract stack — typically javac patterns like
`load + dup_x1 + putfield + load + ifeq` where a value is left on the
stack across a branch — the Builder throws and the string-path picks
the method up. This was estimated at 4–8h in `ROADMAP.md`; given the
real-world frequency, the perf headroom is probably similar to the
estimate but the *coverage* impact is much larger than expected —
recovering 1c-β would lift IR coverage to ~99% on commons-lang3.

**TABLESWITCH/LOOKUPSWITCH (19 fallbacks).** Multi-way branches with
either dense (table) or sparse (lookup) jump tables. Need a `Switch`
Terminator IR node; the Lowerer emits a `match`/`switch` block. Bigger
than a one-line opcode add but structurally bounded.

**DUP2/DUP2_X1/DUP2_X2 (2 fallbacks).** Duplicate top two stack
slots — semantically depends on whether the top is a category-1 (int,
ref, float) or category-2 (long, double) value. Rare; defer.

**LUSHR (2 fallbacks).** Unsigned 64-bit shift right. PHP has no `>>>`;
emulate via mask. Trivial but rare.

**MULTIANEWARRAY (1 fallback).** Multi-dim `int[][][]` allocation.
Very rare in commons-lang3.

## Reproduce

```bash
sudo apt install libcommons-lang3-java
cd ~/GitHub/php-java
php bench/probe-real-library.php
```

First run extracts the JAR to `/tmp/probe-aot-extracted/` (~1s);
subsequent runs reuse it. Wall time: ~30–60s on a Kali WSL box,
dominated by `proc_open` per-class overhead (the actual AOT compile
is sub-millisecond per class).
