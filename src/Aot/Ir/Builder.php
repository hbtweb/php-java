<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir;

// Node.php declares multiple IR classes in one file; PSR-4 expects
// one-class-per-file, so explicit require here.
require_once __DIR__ . '/Node.php';

use PHPJava\Core\JavaCompiledClass;
use PHPJava\Kernel\Attributes\BootstrapMethodsAttribute;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;
use PHPJava\Kernel\Structures\ClassInfo;
use PHPJava\Kernel\Structures\DoubleInfo;
use PHPJava\Kernel\Structures\FieldrefInfo;
use PHPJava\Kernel\Structures\FloatInfo;
use PHPJava\Kernel\Structures\IntegerInfo;
use PHPJava\Kernel\Structures\InterfaceMethodrefInfo;
use PHPJava\Kernel\Structures\InvokeDynamicInfo;
use PHPJava\Kernel\Structures\LongInfo;
use PHPJava\Kernel\Structures\MethodHandleInfo;
use PHPJava\Kernel\Structures\MethodTypeInfo;
use PHPJava\Kernel\Structures\MethodrefInfo;
use PHPJava\Kernel\Structures\NameAndTypeInfo;
use PHPJava\Kernel\Structures\StringInfo;
use PHPJava\Kernel\Structures\Utf8Info;

/**
 * JVM bytecode → IR builder. Walks parsed bytecode, builds a Method
 * (BasicBlocks of Stmts + Terminators) with operand-stack erasure
 * baked in: pure pushes accumulate on an abstract stack of Expr nodes,
 * and side-effecting consumers (istore, branch, return) emit Stmts /
 * Terminators using the popped expressions directly.
 *
 * Coverage scope (this PoC):
 *   - iconst_m1..5, iconst_0..5 immediates
 *   - bipush, sipush
 *   - iload_0..3, istore_0..3
 *   - iadd, isub, imul (and their long-aliases at the same opcode)
 *   - iinc
 *   - if_icmplt/ge/gt/le/eq/ne
 *   - goto, ireturn
 *   - invokestatic (for BenchInvoke)
 *
 * Out of scope here (handled by the string emitter as fallback for
 * methods containing them): exception tables, invokedynamic, array
 * ops, type checks, switches, monitors, wide prefix.
 */
final class Builder
{
    /** @var \PHPJava\Kernel\Structures\StructureInterface[] */
    private array $constantPool = [];
    private string $currentClassBin = '';

    /** Operand-stack model, list of Expr. Erased into IR at build time. */
    private array $abstractStack = [];

    /** @var array<int, BasicBlock> Indexed by entry PC. */
    private array $blocks = [];
    private BasicBlock $currentBb;

    /** Branch targets discovered in the first pass. */
    private array $branchTargets = [];

    /**
     * Exception-table entries for the current method, indexed by start_pc.
     * Each entry: [{end:int, handler:int, classFqn:string}, ...].
     */
    private array $exceptionsByStart = [];
    private array $exceptionHandlerPcs = [];

    /** Cached BootstrapMethods entries — indy resolution uses this. */
    private array $bootstrapMethods = [];

    /**
     * Synthetic-local allocation for cross-BB stack spill (sub-step 1c-β).
     * Per target PC, the slot indices used to hold the residual abstract
     * stack at BB entry. Source BB writes via StoreLocal before the
     * terminator; target BB seeds its abstractStack via LocalRead at entry.
     *
     * Slots above the original `$maxSlot + 1` boundary are synthetic; the
     * Method's `maxLocals` is widened accordingly. JVM verifier guarantees
     * all predecessors of a given merge point deliver the same stack-shape,
     * so a single slot mapping per target PC suffices.
     *
     * @var array<int, int[]>  targetPc => [slot0, slot1, ...] (slot0 = bottom of stack)
     */
    private array $bbEntrySlots = [];

    /** Next free slot for synthetic-local allocation; init = max($argc, $maxSlot+1). */
    private int $nextSyntheticSlot = 0;

    /**
     * Slots known to hold a boolean[] array. JVM bastore (0x54) is
     * shared between byte[] and boolean[]; without per-slot type
     * tracking, the IR Builder can't tell which one the target is.
     * Populated at `newarray T_BOOLEAN` (atype=4); consumed at
     * bastore/baload to apply the Z-shape narrow/widen per
     * CONTRACTS.md §1 (boolean = PHP bool).
     *
     * Tracking only synthetic slots (allocated by materialiseToLocal
     * at newarray time) is sufficient for the dup-then-bastore
     * pattern javac emits for array initialisers. Astore propagation
     * to user slots would extend coverage but isn't needed for the
     * current failing surface.
     *
     * @var array<int, true>
     */
    private array $booleanArraySlots = [];

    /**
     * Synthetic lambda classes generated this build session. Caller
     * (Module assembler) reads via getLambdaClasses() after each
     * buildMethod call and attaches to the Module.
     * @var array<int, array{fqn:string, php:string}>
     */
    private array $lambdaClasses = [];
    private int $lambdaCounter = 0;

    public function getLambdaClasses(): array { return $this->lambdaClasses; }

    /** Optional override for the AOT-emitted class FQN. If null, Builder
     *  falls back to deriving from currentClassBin. Used when the
     *  emitted PHP class will be renamed (e.g. test fixtures). */
    private ?string $aotClassFqn = null;
    public function setAotClassFqn(?string $fqn): void { $this->aotClassFqn = $fqn; }

    /**
     * JVM binary name of the AOT-routed superclass (e.g. 'EnclosingMethodTest'
     * for `class EnclosingMethodTest$1 extends EnclosingMethodTest`). Set
     * by the Compiler when the emitted PHP class will carry an `extends`
     * clause. Drives the super-init peephole: with extends in place,
     * `aload_0; invokespecial Super.<init>` lowers to `parent::__construct`
     * rather than the no-op used when the AOT class doesn't extend.
     *
     * Null when the class doesn't extend any AOT-emitted class
     * (extends Object directly, or extends a JDK shim that we
     * deliberately don't chain into).
     */
    private ?string $superClassBin = null;
    public function setSuperClassBin(?string $bin): void { $this->superClassBin = $bin; }

    /**
     * Per-class overload index (set by Compiler). When a Java method
     * name has multiple descriptors in the current class, all overloads
     * — and self-class invokes targeting that name — get descriptor-
     * mangled so PHP method-name uniqueness holds. Cross-class invokes
     * always use the simple-mangled name (target class's dispatcher
     * routes by arg shape).
     *
     * @var array<string, string[]>  simple-mangled name → list of descriptors
     */
    private array $overloadIndex = [];
    public function setOverloadIndex(array $idx): void { $this->overloadIndex = $idx; }

    public function buildMethod(
        JavaCompiledClass $jcc,
        string $methodName,
        string $descriptor,
        string $bytecode,
        string $currentClassBin,
        array $exceptionTables = [],
        bool $isStatic = true
    ): Method {
        $this->constantPool = $jcc->getConstantPool()->getEntries();
        $this->currentClassBin = $currentClassBin;
        $this->abstractStack = [];
        $this->blocks = [];
        $this->branchTargets = [];
        $this->exceptionsByStart = [];
        $this->exceptionHandlerPcs = [];
        // Per-method state. Without this reset, a slot marked as
        // boolean[] in createBooleanArray() would still be marked when
        // createByteArray() reuses the same synthetic slot number,
        // mis-narrowing byte writes to bool.
        $this->booleanArraySlots = [];
        $this->bbEntrySlots = [];

        // Cache BootstrapMethods once per JCC (idempotent across method calls).
        if (empty($this->bootstrapMethods)) {
            try {
                $bsmAttr = AttributionResolver::resolve(
                    $jcc->getAttributes(),
                    BootstrapMethodsAttribute::class
                );
                $this->bootstrapMethods = $bsmAttr->getBootstrapMethods();
            } catch (\PHPJava\Exceptions\UnableToFindAttributionException $e) {
                $this->bootstrapMethods = [];
            }
        }

        // Index exception tables by start PC; mark handler PCs.
        foreach ($exceptionTables as $et) {
            $startPc = $et->getStartPc();
            $endPc   = $et->getEndPc();
            $handler = $et->getHandlerPc();
            $catchTypeIdx = $et->getCatchType();
            $fqn = '\\Throwable';
            if ($catchTypeIdx !== 0) {
                $cls = $this->constantPool[$catchTypeIdx] ?? null;
                if ($cls instanceof ClassInfo) {
                    $fqn = $this->classFqn($this->utf8At($cls->getClassIndex()));
                }
            }
            $this->exceptionsByStart[$startPc][] = [
                'end' => $endPc, 'handlerPc' => $handler, 'classFqn' => $fqn,
            ];
            $this->exceptionHandlerPcs[$handler] = true;
            // Handler entry is a branch target.
            $this->branchTargets[$handler] = true;
        }

        $bytes = array_values(unpack('C*', $bytecode));
        $end = count($bytes);

        // ── First pass: find branch targets, max slot used ──────────
        $pc = 0;
        $maxSlot = -1;
        while ($pc < $end) {
            $start = $pc;
            $op = $bytes[$pc++];
            if (in_array($op, [0xA2, 0xA7, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E,
                               0x9F, 0xA0, 0xA1, 0xA3, 0xA4, 0xA5, 0xA6, 0xC6, 0xC7], true)) {
                $hi = $bytes[$pc] ?? 0; $lo = $bytes[$pc + 1] ?? 0;
                $rawOffset = ($hi << 8) | $lo;
                if ($rawOffset & 0x8000) $rawOffset -= 0x10000;
                $this->branchTargets[$start + $rawOffset] = true;
            }
            if ($op >= 0x1A && $op <= 0x2D) {
                $maxSlot = max($maxSlot, ($op - 0x1A) % 4);
            } elseif ($op >= 0x3B && $op <= 0x4E) {
                $maxSlot = max($maxSlot, ($op - 0x3B) % 4);
            } elseif (in_array($op, [0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0x84], true)) {
                $maxSlot = max($maxSlot, $bytes[$pc] ?? 0);
            }
            // Variable-length switch opcodes — record all targets,
            // advance pc past the (padded) operand block.
            if ($op === 0xAA || $op === 0xAB) {
                $sw = $this->parseSwitch($bytes, $start);
                $this->branchTargets[$sw['default']] = true;
                foreach ($sw['cases'] as $tgt) {
                    $this->branchTargets[$tgt] = true;
                }
                $pc = $sw['nextPc'];
                continue;
            }
            $pc += $this->opcodeLength($op);
        }

        // Method-entry implicitly is a branch target.
        $this->branchTargets[0] = true;
        // Exception start_pc and handler_pc are also BB boundaries.
        foreach ($this->exceptionsByStart as $startPc => $_) {
            $this->branchTargets[$startPc] = true;
        }

        // Synthetic locals for cross-BB stack spill start above the
        // original-bytecode maxLocals. For instance methods, slot 0 is
        // implicitly `$this` (counted in maxLocals via $maxSlot scan),
        // explicit args occupy slots [1..argc].
        [$argTypes0, ] = $this->parseDescriptor($descriptor);
        $thisSlot0 = $isStatic ? 0 : 1;
        $this->nextSyntheticSlot = max(count($argTypes0) + $thisSlot0, $maxSlot + 1);

        // ── Second pass: build IR ───────────────────────────────────
        $this->currentBb = new BasicBlock(0);
        $this->blocks[0] = $this->currentBb;
        // If method entry is itself a handler (rare, exception in <init>):
        if (isset($this->exceptionHandlerPcs[0])) {
            $this->currentBb->isHandler = true;
            $this->push(new CaughtException());
        }
        // Apply exception protection to entry BB if it's at start_pc.
        if (isset($this->exceptionsByStart[0])) {
            $this->currentBb->tryProtect = $this->exceptionsByStart[0];
        }

        $pc = 0;
        while ($pc < $end) {
            $start = $pc;
            $op = $bytes[$pc++];

            // BB boundary: if this is a branch target and we're
            // already in a block (i.e., not the first opcode), end
            // the current block with a fall-through goto and start a
            // new one. JVM verifier guarantees stack consistency at
            // entry. For non-empty abstract stack at a non-handler
            // boundary, spill to synthetic locals (sub-step 1c-β);
            // the target BB reloads via LocalRead at its entry.
            // Exception-handler entries seed the stack with
            // [CaughtException] separately.
            if (isset($this->branchTargets[$start]) && $start !== 0) {
                $isHandler = isset($this->exceptionHandlerPcs[$start]);
                if (!empty($this->abstractStack) && !$isHandler) {
                    $this->spillStackToSlot($start);
                }
                if ($this->currentBb->term === null) {
                    $this->currentBb->term = new Goto_($start);
                }
                $bb = new BasicBlock($start);
                $this->blocks[$start] = $bb;
                $this->currentBb = $bb;
                if ($isHandler) {
                    $this->currentBb->isHandler = true;
                    // Reset abstract stack for handler entry.
                    $this->abstractStack = [new CaughtException()];
                } else {
                    // Re-seed abstract stack from synthetic slots if
                    // any predecessor spilled here.
                    $this->reloadStackFromSlots($start);
                }
                if (isset($this->exceptionsByStart[$start])) {
                    $this->currentBb->tryProtect = $this->exceptionsByStart[$start];
                }
            }

            $this->emitOpcode($op, $bytes, $pc, $start);
            // Note: emitOpcode advances $pc past its operands; we re-read.
            $pc = $this->pc;
        }

        // Final BB without terminator (shouldn't happen with valid bytecode).
        if ($this->currentBb->term === null) {
            throw new \LogicException("BB at PC {$this->currentBb->entryPc} has no terminator");
        }

        [$argTypes, ] = $this->parseDescriptor($descriptor);
        $argc = count($argTypes);
        $params = [];
        for ($i = 0; $i < $argc; $i++) $params[] = "\$__a{$i}";
        // Widen by any synthetic locals allocated for cross-BB stack
        // spill. nextSyntheticSlot points at the next free slot, which
        // equals the count of slots used. For instance methods,
        // maxLocals must accommodate $this at slot 0 + explicit args
        // at slots [1..argc].
        $thisSlot1 = $isStatic ? 0 : 1;
        $maxLocals = max($argc + $thisSlot1, $maxSlot + 1, $this->nextSyntheticSlot);

        $byrefParamIndices = self::detectByrefParams(
            $this->blocks, $argTypes, $isStatic
        );

        return new Method(
            name: $this->mangleMethodForInvoke($currentClassBin, $methodName, $descriptor),
            descriptor: $descriptor,
            isStatic: $isStatic,
            params: $params,
            maxLocals: $maxLocals,
            blocks: $this->blocks,
            byrefParamIndices: $byrefParamIndices,
        );
    }

    /**
     * Auto-detect parameters needing PHP `&` reference passing — mirrors
     * cljp's by-ref auto-detect (CLJP-COMPILER.md §"By-reference
     * auto-detect"). Direct case: any `aastore`/`iastore`/etc. (lowered
     * as `StoreArrayElement`) targeting a slot that maps back to a
     * parameter means that parameter is mutated in place. Without `&`,
     * PHP value-typed-array COW silently forks the caller's array.
     *
     * Indirect cases (chain through user fns, ^:ref annotations) deferred
     * — direct-aset alone is enough for the immediate failing surface
     * (in-place sorts, swap helpers).
     */
    private static function detectByrefParams(array $blocks, array $argTypes, bool $isStatic): array
    {
        $mutatedSlots = [];
        foreach ($blocks as $bb) {
            foreach ($bb->stmts as $stmt) {
                if ($stmt instanceof StoreArrayElement) {
                    $mutatedSlots[$stmt->slot] = true;
                }
            }
        }
        if (!$mutatedSlots) return [];

        // Map param index → its starting slot, accounting for J/D
        // 2-slot widths and the instance-method $this at slot 0.
        $byref = [];
        $slot = $isStatic ? 0 : 1;
        foreach ($argTypes as $idx => $type) {
            if (isset($mutatedSlots[$slot])) {
                $byref[] = $idx;
            }
            $slot += ($type === 'J' || $type === 'D') ? 2 : 1;
        }
        return $byref;
    }

    /** Stash for emitOpcode to advance through operand bytes. */
    private int $pc = 0;

    private function emitOpcode(int $op, array $bytes, int $pc, int $start): void
    {
        $this->pc = $pc;
        switch ($op) {
            // ── const push ──────────────────────────────────────────
            case 0x01: $this->push(new \PHPJava\Aot\Ir\NullLit()); return;
            case 0x02: $this->push(new IntLit(-1)); return;
            case 0x03: $this->push(new IntLit(0)); return;
            case 0x04: $this->push(new IntLit(1)); return;
            case 0x05: $this->push(new IntLit(2)); return;
            case 0x06: $this->push(new IntLit(3)); return;
            case 0x07: $this->push(new IntLit(4)); return;
            case 0x08: $this->push(new IntLit(5)); return;
            case 0x09: $this->push(new IntLit(0)); return; // lconst_0
            case 0x0A: $this->push(new IntLit(1)); return; // lconst_1
            case 0x0B: $this->push(new \PHPJava\Aot\Ir\FloatLit(0.0)); return; // fconst_0
            case 0x0C: $this->push(new \PHPJava\Aot\Ir\FloatLit(1.0)); return; // fconst_1
            case 0x0D: $this->push(new \PHPJava\Aot\Ir\FloatLit(2.0)); return; // fconst_2
            case 0x0E: $this->push(new \PHPJava\Aot\Ir\FloatLit(0.0)); return; // dconst_0
            case 0x0F: $this->push(new \PHPJava\Aot\Ir\FloatLit(1.0)); return; // dconst_1
            case 0x10: // bipush
                $v = $bytes[$this->pc++];
                if ($v & 0x80) $v -= 0x100;
                $this->push(new IntLit($v));
                return;
            case 0x11: // sipush
                $v = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                if ($v & 0x8000) $v -= 0x10000;
                $this->push(new IntLit($v));
                return;
            // ── load ────────────────────────────────────────────────
            case 0x1A: case 0x1E: case 0x22: case 0x26: case 0x2A: $this->push(new LocalRead(0)); return; // i/l/f/d/aload_0
            case 0x1B: case 0x1F: case 0x23: case 0x27: case 0x2B: $this->push(new LocalRead(1)); return;
            case 0x1C: case 0x20: case 0x24: case 0x28: case 0x2C: $this->push(new LocalRead(2)); return;
            case 0x1D: case 0x21: case 0x25: case 0x29: case 0x2D: $this->push(new LocalRead(3)); return;
            // wide-byte forms: i/l/f/d/aload N (byte operand)
            case 0x15: case 0x16: case 0x17: case 0x18: case 0x19:
                $idx = $bytes[$this->pc++];
                $this->push(new LocalRead($idx));
                return;
            // ── store ───────────────────────────────────────────────
            case 0x3B: case 0x3F: case 0x43: case 0x47: case 0x4B: $this->emitStore(0); return; // i/l/f/d/astore_0
            case 0x3C: case 0x40: case 0x44: case 0x48: case 0x4C: $this->emitStore(1); return;
            case 0x3D: case 0x41: case 0x45: case 0x49: case 0x4D: $this->emitStore(2); return;
            case 0x3E: case 0x42: case 0x46: case 0x4A: case 0x4E: $this->emitStore(3); return;
            // wide-byte forms: i/l/f/d/astore N (byte operand)
            case 0x36: case 0x37: case 0x38: case 0x39: case 0x3A:
                $idx = $bytes[$this->pc++];
                $this->emitStoreSlot($idx);
                return;
            // ── type conversions ──────────────────────────────────
            // Split by destination type, not by source. PHP int and float
            // are separate native types; tests using `assertSame` (strict
            // ===) distinguish 1234 from 1234.0. The JVM ints/floats need
            // explicit PHP int/float at the boundary.
            case 0x85: case 0x88:                       // i2l, l2i
            case 0x8B: case 0x8E: case 0x8C: case 0x8F: // f2i, d2i, f2l, d2l
                $top = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall('\\intval', '', [$top]));
                return;
            case 0x87: case 0x8A:                       // i2d, l2d (no narrow — D is binary64)
                $top = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall('\\floatval', '', [$top]));
                return;
            case 0x86: case 0x89: case 0x90:            // i2f, l2f, d2f — narrow to 32-bit precision
                $top = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\PHPJava\\Aot\\Runtime\\jvm_f32', '',
                    [new \PHPJava\Aot\Ir\StaticCall('\\floatval', '', [$top])]
                ));
                return;
            case 0x8D: return; // f2d — no-op (widening F→D preserves precision exactly)
            // ── 3-way comparison (lcmp/fcmpl/g/dcmpl/g) ────────────
            case 0x94: case 0x95: case 0x96: case 0x97: case 0x98:
                $right = $this->pop(); $left = $this->pop();
                $this->push(new BinOp('<=>', $left, $right));
                return;
            // ── if_acmpeq / if_acmpne — same emit as ===/!== ───────
            case 0xA5: $this->emitCondGoto('===', $bytes, $start); return;
            case 0xA6: $this->emitCondGoto('!==', $bytes, $start); return;
            // ── arithmetic. PHP `+`/`-`/`*` work uniformly on int
            //    and float, but JVM int is 32-bit (PHP int is 64-bit).
            //    Per CONTRACTS.md §1, int arith results need a 32-bit
            //    sign-extending mask: (v << 32) >> 32. Long (64-bit) =
            //    PHP int, no mask. Float/double = PHP float, no mask.
            case 0x60: $this->emitIntBinOp('+'); return; // iadd
            case 0x61: $this->emitLongBinFn('jvm_ladd'); return; // ladd (Java-correct wrap)
            case 0x62: case 0x63: $this->emitBinOp('+'); return; // fadd/dadd
            case 0x64: $this->emitIntBinOp('-'); return; // isub
            case 0x65: $this->emitLongBinFn('jvm_lsub'); return; // lsub
            case 0x66: case 0x67: $this->emitBinOp('-'); return; // fsub/dsub
            case 0x68: $this->emitIntBinOp('*'); return; // imul
            case 0x69: $this->emitLongBinFn('jvm_lmul'); return; // lmul
            case 0x6A: case 0x6B: $this->emitBinOp('*'); return; // fmul/dmul
            // ── iinc ────────────────────────────────────────────────
            case 0x84:
                $idx = $bytes[$this->pc++];
                $delta = $bytes[$this->pc++];
                if ($delta & 0x80) $delta -= 0x100;
                $this->currentBb->stmts[] = new IincLocal($idx, $delta);
                return;
            // ── two-operand if ──────────────────────────────────────
            case 0x9F: $this->emitCondGoto('===', $bytes, $start); return;
            case 0xA0: $this->emitCondGoto('!==', $bytes, $start); return;
            case 0xA1: $this->emitCondGoto('<',  $bytes, $start); return;
            case 0xA2: $this->emitCondGoto('>=', $bytes, $start); return;
            case 0xA3: $this->emitCondGoto('>',  $bytes, $start); return;
            case 0xA4: $this->emitCondGoto('<=', $bytes, $start); return;
            // ── goto ────────────────────────────────────────────────
            case 0xA7:
                $offset = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                if ($offset & 0x8000) $offset -= 0x10000;
                $tgt = $start + $offset;
                $this->spillStackToSlot($tgt);
                $this->currentBb->term = new Goto_($tgt);
                return;
            // ── tableswitch / lookupswitch ─────────────────────────
            // Emit Switch_ Terminator (pop key first; spill any residual
            // stack to slots shared by ALL successor PCs — verifier
            // guarantees same shape across default + cases).
            case 0xAA:
            case 0xAB:
                $sw = $this->parseSwitch($bytes, $start);
                $key = $this->pop();
                $this->spillStackForSwitch($sw['default'], array_values($sw['cases']));
                $this->currentBb->term = new \PHPJava\Aot\Ir\Switch_(
                    $key, $sw['default'], $sw['cases']
                );
                $this->pc = $sw['nextPc'];
                return;
            // ── return ──────────────────────────────────────────────
            case 0xAC: case 0xAD: case 0xAE: case 0xAF: case 0xB0:
                $this->currentBb->term = new Return_($this->pop());
                return;
            case 0xB1:
                $this->currentBb->term = new Return_(null);
                return;
            // ── nop ─────────────────────────────────────────────────
            case 0x00:
                return;
            // ── array ops — escape-analysis-aware emit ─────────────
            // newarray emits raw PHP `\array_fill(0, size, 0)` — no
            // `(object){v=>...}` wrapper. Subsequent astore-then-aload
            // patterns track via abstract-stack: when iastore sees
            // `LocalRead(N)` as the receiver, emit `$L[N][$i] = $v`
            // directly (StoreArrayElement Stmt), bypassing the
            // 10× object-property-access cost the JIT-claims battery
            // surfaced. Same for iaload → ArrayElementRead, and
            // arraylength → ArrayLengthRead.
            //
            // For non-LocalRead sources (e.g. arrays loaded from
            // fields, returned from method calls), fall back to
            // ArrayHelper::set/get/len which handles the wrapper +
            // raw-array cases dynamically. Most hot loops use
            // local-array patterns so the fast path applies.
            case 0xBC: // newarray (atype byte, length on stack)
                $atype = $bytes[$this->pc++];
                $size = $this->pop();
                // T_BOOLEAN (4): default value is false (per JVMS §2.3.4),
                // not int 0, to match CONTRACTS.md §1's boolean = PHP bool.
                // Other primitive arrays start at int 0 / float 0.0 — PHP's
                // 0 covers both at the array-fill level (PHP coerces on
                // first write).
                $defaultVal = $atype === 4 ? new \PHPJava\Aot\Ir\BoolLit(false) : new IntLit(0);
                // Materialise the allocation into a synthetic local so the
                // typical javac shape `newarray; dup; iconst i; iconst v;
                // bastore; dup; ...` mutates ONE array instead of one fresh
                // array_fill per bastore. Without this hoist, each dup'd
                // copy of the StaticCall(array_fill, ...) re-allocates at
                // execution time, *astore mutates a temporary that's
                // immediately discarded, and the final putstatic/putfield
                // stores yet another fresh-zeroed array.
                $local = $this->materialiseToLocal(new \PHPJava\Aot\Ir\StaticCall(
                    '\\array_fill', '', [new IntLit(0), $size, $defaultVal]
                ));
                if ($atype === 4) {
                    $this->booleanArraySlots[$local->slot] = true;
                }
                $this->push($local);
                return;
            case 0xBD: // anewarray
                $this->pc += 2;
                $size = $this->pop();
                $this->push($this->materialiseToLocal(new \PHPJava\Aot\Ir\StaticCall(
                    '\\array_fill', '', [new IntLit(0), $size, new \PHPJava\Aot\Ir\NullLit()]
                )));
                return;
            case 0xBE: // arraylength
                $arr = $this->pop();
                if ($arr instanceof LocalRead) {
                    $this->push(new \PHPJava\Aot\Ir\ArrayLengthRead($arr->slot));
                } else {
                    $this->push(new \PHPJava\Aot\Ir\StaticCall('\\count', '', [$arr]));
                }
                return;
            case 0x2E: case 0x2F: case 0x30: case 0x31: // *aload
            case 0x32: case 0x33: case 0x34: case 0x35:
                $i = $this->pop();
                $a = $this->pop();
                // Element-type widening for contract-shape arrays:
                // - caload (0x34): char[] holds 1-char UTF-8 strings → int.
                // - baload (0x33): SHARED with byte[]; only widen when the
                //   source slot is known to hold boolean[] (newarray T_BOOLEAN).
                $elemDesc = '';
                if ($op === 0x34) {
                    $elemDesc = 'C';
                } elseif ($op === 0x33 && $a instanceof LocalRead && isset($this->booleanArraySlots[$a->slot])) {
                    $elemDesc = 'Z';
                }
                if ($a instanceof LocalRead) {
                    $read = new \PHPJava\Aot\Ir\ArrayElementRead($a->slot, $i);
                } else {
                    // Fallback: array came from elsewhere (field, call return, etc).
                    $read = new \PHPJava\Aot\Ir\StaticCall(
                        '\\PHPJava\\Aot\\Ir\\ArrayHelper', 'get', [$a, $i]
                    );
                }
                $this->push(self::widenForJvmStack($elemDesc, $read));
                return;
            case 0x4F: case 0x50: case 0x51: case 0x52: // *astore
            case 0x53: case 0x54: case 0x55: case 0x56:
                $v = $this->pop();
                $i = $this->pop();
                $a = $this->pop();
                // Symmetric narrowing for contract-shape array storage.
                $elemDesc = '';
                if ($op === 0x55) {
                    $elemDesc = 'C';
                } elseif ($op === 0x54 && $a instanceof LocalRead && isset($this->booleanArraySlots[$a->slot])) {
                    $elemDesc = 'Z';
                }
                $v = self::narrowForFieldStorage($elemDesc, $v);
                if ($a instanceof LocalRead) {
                    $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\StoreArrayElement(
                        $a->slot, $i, $v
                    );
                } else {
                    $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\ExprStmt(
                        new \PHPJava\Aot\Ir\StaticCall(
                            '\\PHPJava\\Aot\\Ir\\ArrayHelper', 'set', [$a, $i, $v]
                        )
                    );
                }
                return;
            // ── invoke* ────────────────────────────────────────────
            case 0xB8: // invokestatic
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $this->emitInvokeStatic($idx);
                return;
            case 0xB6: // invokevirtual
            case 0xB9: // invokeinterface
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                if ($op === 0xB9) $this->pc += 2; // count + reserved
                $this->emitInvokeInstance($idx, isSpecial: false);
                return;
            case 0xB7: // invokespecial
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $this->emitInvokeInstance($idx, isSpecial: true);
                return;
            // ── new / dup / athrow ─────────────────────────────────
            case 0xBB: // new
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $cls = $this->constantPool[$idx] ?? null;
                $name = ($cls instanceof ClassInfo)
                    ? $this->classFqn($this->utf8At($cls->getClassIndex()))
                    : '\\stdClass';
                // Uninit marker — the immediately-following dup +
                // invokespecial<init> pattern rewrites this into a
                // proper New_(class, args) Expr at invokespecial time.
                $this->push(new \PHPJava\Aot\Ir\StaticCall($name, '__uninit', []));
                return;
            case 0x59: // dup
                $top = end($this->abstractStack);
                if ($top === false) throw new \LogicException('dup on empty stack');
                $this->push($top);
                return;
            case 0xBF: // athrow
                $this->currentBb->term = new Throw_($this->pop());
                return;
            // ── ldc family ─────────────────────────────────────────
            case 0x12: // ldc
                $idx = $bytes[$this->pc++];
                $this->push($this->ldcExpr($idx));
                return;
            case 0x13: case 0x14: // ldc_w, ldc2_w
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $this->push($this->ldcExpr($idx));
                return;
            // ── field access ───────────────────────────────────────
            case 0xB2: // getstatic
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [$cls, $field, $desc] = $this->resolveFieldRef($idx);
                $folded = self::lowerWrapperStaticField($cls, $field);
                if ($folded !== null) {
                    $this->push($folded);
                    return;
                }
                $read = new \PHPJava\Aot\Ir\StaticFieldRead($this->classFqn($cls), $field);
                $this->push(self::widenForJvmStack($desc, $read));
                return;
            case 0xB3: // putstatic
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [$cls, $field, $desc] = $this->resolveFieldRef($idx);
                $val = self::narrowForFieldStorage($desc, $this->pop());
                $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\StoreStaticField(
                    $this->classFqn($cls), $field, $val
                );
                return;
            case 0xB4: // getfield
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [, $field, $desc] = $this->resolveFieldRef($idx);
                $obj = $this->pop();
                $read = new \PHPJava\Aot\Ir\FieldRead($obj, $this->mangleField($field));
                $this->push(self::widenForJvmStack($desc, $read));
                return;
            case 0xB5: // putfield
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [, $field, $desc] = $this->resolveFieldRef($idx);
                $val = self::narrowForFieldStorage($desc, $this->pop());
                $obj = $this->pop();
                $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\StoreField($obj, $this->mangleField($field), $val);
                return;
            // ── single-operand if ───────────────────────────────────
            case 0x99: $this->emitIfPop('===', new IntLit(0), $bytes, $start); return; // ifeq
            case 0x9A: $this->emitIfPop('!==', new IntLit(0), $bytes, $start); return; // ifne
            case 0x9B: $this->emitIfPop('<',   new IntLit(0), $bytes, $start); return; // iflt
            case 0x9C: $this->emitIfPop('>=',  new IntLit(0), $bytes, $start); return; // ifge
            case 0x9D: $this->emitIfPop('>',   new IntLit(0), $bytes, $start); return; // ifgt
            case 0x9E: $this->emitIfPop('<=',  new IntLit(0), $bytes, $start); return; // ifle
            case 0xC6: $this->emitIfPop('===', new \PHPJava\Aot\Ir\NullLit(), $bytes, $start); return;
            case 0xC7: $this->emitIfPop('!==', new \PHPJava\Aot\Ir\NullLit(), $bytes, $start); return;
            // ── stack ops ─────────────────────────────────────────
            // pop discards the top value but its side effects matter —
            // bytecode like `String.charAt(-1); /* discard result */`
            // pops the int return after the call ran. If the popped Expr
            // is impure (StaticCall, InstanceCall, New_, …), materialise
            // it as an ExprStmt before discarding so the side effect
            // (e.g. IndexOutOfBoundsException) still happens.
            case 0x57:
                $top = array_pop($this->abstractStack);
                if ($top instanceof Expr && !$top->isPure()) {
                    $this->currentBb->stmts[] = new ExprStmt($top);
                }
                return;
            case 0x00: return; // nop (already above)
            // ── INVOKEDYNAMIC ──────────────────────────────────────
            case 0xBA:
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $this->pc += 2; // 2 reserved zero bytes
                $this->emitInvokeDynamic($idx);
                return;
            // ── more arithmetic / shifts / bitwise ─────────────────
            case 0x6C: $this->emitIntBinOpFn('intdiv'); return;               // idiv (32-bit mask)
            case 0x6D: $this->emitLongBinFn('jvm_ldiv'); return;              // ldiv (Long.MIN_VALUE / -1 wrap)
            case 0x6E: case 0x6F: $this->emitBinOp('/'); return;              // fdiv/ddiv
            case 0x70: $this->emitIntBinOp('%'); return;                      // irem (32-bit mask)
            case 0x71: $this->emitLongBinFn('jvm_lrem'); return;              // lrem (Long.MIN_VALUE % -1 = 0)
            case 0x72: case 0x73: $this->emitBinOpFn('fmod'); return;         // frem/drem
            case 0x74: // ineg — 32-bit mask
                $this->push($this->maskInt32(new \PHPJava\Aot\Ir\UnaryOp('-', $this->pop())));
                return;
            case 0x75: // lneg — Long.MIN_VALUE wraps to itself
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\PHPJava\\Aot\\Runtime\\jvm_lneg', '',
                    [$this->pop()]
                ));
                return;
            case 0x76: case 0x77: // fneg/dneg — no mask
                $this->push(new \PHPJava\Aot\Ir\UnaryOp('-', $this->pop()));
                return;
            case 0x7E: case 0x7F: $this->emitBinOp('&'); return;
            case 0x80: case 0x81: $this->emitBinOp('|'); return;
            case 0x82: case 0x83: $this->emitBinOp('^'); return;
            // ── shifts. JVM masks the shift count: ISHL/ISHR use low 5
            //    bits, LSHL/LSHR use low 6 bits. PHP `<<`/`>>` don't
            //    mask, so we mask the right operand here. PHP `>>` is
            //    arithmetic (sign-preserving), matching JVM ISHR/LSHR.
            //    IUSHR/LUSHR (unsigned) not yet emitted — fall through
            //    to default until we add a (\$v & PHP_INT_MAX) >> n shape.
            case 0x78: $this->emitShift('<<', 0x1F); return; // ishl
            case 0x79: $this->emitShift('<<', 0x3F); return; // lshl
            case 0x7A: $this->emitShift('>>', 0x1F); return; // ishr
            case 0x7B: $this->emitShift('>>', 0x3F); return; // lshr
            // ── narrowing int conversions ────────────────────────
            // I2B: truncate to bottom 8 bits, sign-extend to 64. PHP
            //  ints are 64-bit, so (v << 56) >> 56 keeps low 8 with
            //  correct sign. Same shape for I2S (16 bits → << 48).
            //  I2C is unsigned 16-bit, plain mask.
            case 0x91: // i2b
                $v = $this->pop();
                $this->push(new BinOp('>>', new BinOp('<<', $v, new IntLit(56)), new IntLit(56)));
                return;
            case 0x92: // i2c
                $v = $this->pop();
                $this->push(new BinOp('&', $v, new IntLit(0xFFFF)));
                return;
            case 0x93: // i2s
                $v = $this->pop();
                $this->push(new BinOp('>>', new BinOp('<<', $v, new IntLit(48)), new IntLit(48)));
                return;
            // ── stack manipulation ────────────────────────────────
            // DUP_X1: {..., a, b} → {..., b, a, b}. Same caveat as
            // dup (0x59) — duplicating an impure Expr means it'll
            // emit twice; the new+dup<init> pattern handles its own
            // case. For unrelated DUP_X1 sites, the duplicated value
            // is usually a pure load (typical javac shape: load+dup_x1
            // +putfield+something).
            case 0x5A:
                $b = array_pop($this->abstractStack);
                $a = array_pop($this->abstractStack);
                if ($a === null || $b === null) {
                    throw new \LogicException('dup_x1 stack underflow');
                }
                $this->abstractStack[] = $b;
                $this->abstractStack[] = $a;
                $this->abstractStack[] = $b;
                return;
            // DUP2: {..., w2, w1} → {..., w2, w1, w2, w1} for category-1
            // values (we treat all values as one-slot Exprs, matching
            // the abstract-stack model). Same impure-Expr caveat as dup.
            case 0x5C:
                $b = array_pop($this->abstractStack);
                $a = array_pop($this->abstractStack);
                if ($a === null || $b === null) {
                    throw new \LogicException('dup2 stack underflow');
                }
                $this->abstractStack[] = $a;
                $this->abstractStack[] = $b;
                $this->abstractStack[] = $a;
                $this->abstractStack[] = $b;
                return;
            // ── unsigned shift right ─────────────────────────────
            // IUSHR (0x7C): treat low 32 bits of $v as unsigned, shift
            // arithmetic-right (positive after mask), mask shift count
            // to low 5 bits.
            case 0x7C:
                $right = $this->pop();
                $left = $this->pop();
                $this->push(new BinOp(
                    '>>',
                    new BinOp('&', $left, new IntLit(0xFFFFFFFF)),
                    new BinOp('&', $right, new IntLit(0x1F))
                ));
                return;
            // LUSHR (0x7D): full 64-bit unsigned >>> ; PHP has no
            // logical-right-shift, so emit a helper call.
            case 0x7D:
                $right = $this->pop();
                $left = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\PHPJava\\Aot\\Runtime\\jvm_lushr', '',
                    [$left, $right]
                ));
                return;
            // ── multianewarray ────────────────────────────────────
            // operands: indexbyte1, indexbyte2 (CP class), dimensions (u1)
            // pops `dimensions` int counts from stack
            case 0xC5:
                $this->pc += 2; // skip CP class index
                $dims = $bytes[$this->pc++];
                $args = [];
                for ($i = $dims - 1; $i >= 0; $i--) $args[$i] = $this->pop();
                ksort($args);
                $args = array_values($args);
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\PHPJava\\Aot\\Runtime\\jvm_multianewarray', '',
                    $args
                ));
                return;
            // ── monitorenter/exit (no-op for single-thread PHP) ───
            case 0xC2: case 0xC3:
                $this->pop();
                return;
            // ── checkcast / instanceof ────────────────────────────
            // CHECKCAST: ref stays on stack; PHP is dynamically-typed
            // so the cast itself is a no-op aside from ClassCastException
            // semantics, which we don't enforce (matches MODEL.md
            // "do not implement the bytecode verifier" stance).
            case 0xC0:
                $this->pc += 2;
                return;
            // INSTANCEOF: pop ref, push intval(\is_a(ref, '\\FQN', true)).
            // intval wrap so subsequent IFEQ === IntLit(0) comparison is
            // type-correct (PHP false === 0 is false).
            //
            // Raw-scalar adapter classes (String, CharSequence — any
            // class whose AOT runtime form is a PHP scalar) need an
            // is_string() check rather than an is_a() lookup against a
            // class that may not exist as a PHP type. Object is treated
            // as a universal "non-null" check since every JVM ref +
            // raw scalar is a java.lang.Object except null.
            case 0xC1:
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $cls = $this->constantPool[$idx] ?? null;
                $clsBin = ($cls instanceof ClassInfo) ? $this->utf8At($cls->getClassIndex()) : '';
                $ref = $this->pop();
                $check = self::scalarInstanceOfCheck($clsBin, $ref);
                if ($check !== null) {
                    $this->push(new \PHPJava\Aot\Ir\StaticCall('\\intval', '', [$check]));
                    return;
                }
                $clsFqn = ($cls instanceof ClassInfo)
                    ? $this->classFqn($clsBin)
                    : '\\stdClass';
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\intval', '',
                    [new \PHPJava\Aot\Ir\StaticCall(
                        '\\is_a', '',
                        [$ref, new \PHPJava\Aot\Ir\StringLit($clsFqn), new IntLit(1)]
                    )]
                ));
                return;
            default:
                throw new \LogicException(sprintf(
                    "IRBuilder: opcode 0x%02X not yet supported",
                    $op
                ));
        }
    }

    private function push(Expr $e): void { $this->abstractStack[] = $e; }
    private function pop(): Expr {
        if (empty($this->abstractStack)) {
            throw new \LogicException("IR pop from empty abstract stack");
        }
        return array_pop($this->abstractStack);
    }

    private function emitStore(int $slot): void
    {
        $this->currentBb->stmts[] = new StoreLocal($slot, $this->pop());
    }

    /** Same as emitStore but exposed for byte-indexed callers. */
    private function emitStoreSlot(int $slot): void
    {
        $this->currentBb->stmts[] = new StoreLocal($slot, $this->pop());
    }

    /**
     * Spill an Expr into a fresh synthetic local and return a LocalRead
     * pointing at it. Used to give an impure Expr (eg \array_fill(...))
     * a stable identity so subsequent dup/use operations refer to the
     * same materialised value instead of re-evaluating per consumer.
     *
     * Without this, `newarray; dup; iconst i; iconst v; *astore` lowers
     * to `ArrayHelper::set(\array_fill(...), $i, $v);` — each call
     * allocates a new array and discards the mutation, so subsequent
     * putstatic/putfield store a zeroed array. Materialisation makes
     * the bastore branch see a LocalRead and emit StoreArrayElement
     * directly (`$L[N][$i] = $v`).
     */
    private function materialiseToLocal(\PHPJava\Aot\Ir\Expr $e): \PHPJava\Aot\Ir\LocalRead
    {
        $slot = $this->nextSyntheticSlot++;
        $this->currentBb->stmts[] = new StoreLocal($slot, $e);
        return new \PHPJava\Aot\Ir\LocalRead($slot);
    }

    private function emitBinOp(string $op): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $this->push(new BinOp($op, $left, $right));
    }

    /** Emit a binary op via a function call (e.g. intdiv, fmod). */
    private function emitBinOpFn(string $fn): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $this->push(new \PHPJava\Aot\Ir\StaticCall("\\{$fn}", '', [$left, $right]));
    }

    /**
     * Emit an int-arith binary op with 32-bit sign-extending overflow
     * mask: `(left op right << 32) >> 32`. PHP int is 64-bit; JVM int
     * is 32-bit signed. Per CONTRACTS.md §1.
     */
    private function emitIntBinOp(string $op): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $this->push($this->maskInt32(new BinOp($op, $left, $right)));
    }

    /** Same as emitIntBinOp but the op is a function call (e.g. \intdiv). */
    private function emitIntBinOpFn(string $fn): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $call = new \PHPJava\Aot\Ir\StaticCall("\\{$fn}", '', [$left, $right]);
        $this->push($this->maskInt32($call));
    }

    /**
     * Emit a long-arith binary op via a runtime helper that wraps modulo
     * 2^64 (Java semantics). PHP int is 64-bit on 64-bit hosts but
     * promotes to float on overflow, losing precision; the helper
     * detects the overflow and recovers via GMP. See
     * src/Aot/Runtime/bootstrap.php — jvm_ladd / jvm_lsub / etc.
     */
    private function emitLongBinFn(string $fn): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $this->push(new \PHPJava\Aot\Ir\StaticCall(
            "\\PHPJava\\Aot\\Runtime\\{$fn}", '',
            [$left, $right]
        ));
    }

    /** Wrap an Expr with the JVM 32-bit signed-int overflow mask. */
    private function maskInt32(Expr $e): Expr
    {
        return new BinOp('>>', new BinOp('<<', $e, new IntLit(32)), new IntLit(32));
    }

    /** Emit a shift op with the JVM's required shift-count mask. */
    private function emitShift(string $op, int $maskBits): void
    {
        $right = $this->pop();
        $left = $this->pop();
        $this->push(new BinOp(
            $op, $left,
            new BinOp('&', $right, new IntLit($maskBits)),
        ));
    }

    // ── Sub-step 1c-β: cross-BB stack spill ──────────────────────────

    /**
     * Allocate (or reuse) synthetic local slots for a target PC's
     * residual stack. JVM verifier guarantees all predecessors deliver
     * the same stack-shape, so the same slot mapping works for all.
     *
     * @return int[]  slot indices, slot[0] = bottom of stack
     */
    private function allocOrReuseSlots(int $targetPc, int $depth): array
    {
        if (isset($this->bbEntrySlots[$targetPc])) {
            $existing = $this->bbEntrySlots[$targetPc];
            if (count($existing) !== $depth) {
                throw new \LogicException(sprintf(
                    "Inconsistent stack depth at PC %d: %d vs %d",
                    $targetPc, $depth, count($existing)
                ));
            }
            return $existing;
        }
        $slots = [];
        for ($i = 0; $i < $depth; $i++) {
            $slots[] = $this->nextSyntheticSlot++;
        }
        $this->bbEntrySlots[$targetPc] = $slots;
        return $slots;
    }

    /**
     * Spill the current abstract stack into synthetic slots tagged for
     * the target PC. Stack is emptied as a side effect (StoreLocal stmts
     * appended to currentBb).
     */
    private function spillStackToSlot(int $targetPc): void
    {
        $depth = count($this->abstractStack);
        if ($depth === 0) return;
        $slots = $this->allocOrReuseSlots($targetPc, $depth);
        // Pop top-to-bottom; slot[i] holds stack depth i from bottom.
        for ($i = $depth - 1; $i >= 0; $i--) {
            $val = $this->pop();
            $this->currentBb->stmts[] = new StoreLocal($slots[$i], $val);
        }
    }

    /**
     * Spill the current abstract stack to slots shared by both successors
     * of a CondGoto. JVM verifier requires the stack-shape at thenPc and
     * elsePc to match; we use one slot mapping for both.
     */
    private function spillStackForBoth(int $thenPc, int $elsePc): void
    {
        $depth = count($this->abstractStack);
        if ($depth === 0) return;
        $slots = $this->allocOrReuseSlots($thenPc, $depth);
        // Mirror to elsePc — must agree if already allocated.
        if (isset($this->bbEntrySlots[$elsePc])) {
            if ($this->bbEntrySlots[$elsePc] !== $slots) {
                throw new \LogicException(sprintf(
                    "CondGoto slot mismatch: thenPc %d → [%s] vs elsePc %d → [%s]",
                    $thenPc, implode(',', $slots),
                    $elsePc, implode(',', $this->bbEntrySlots[$elsePc])
                ));
            }
        } else {
            $this->bbEntrySlots[$elsePc] = $slots;
        }
        for ($i = $depth - 1; $i >= 0; $i--) {
            $val = $this->pop();
            $this->currentBb->stmts[] = new StoreLocal($slots[$i], $val);
        }
    }

    /**
     * Re-seed the abstract stack at a target BB's entry from synthetic
     * slots a predecessor spilled into. No-op if no predecessor spilled.
     */
    private function reloadStackFromSlots(int $startPc): void
    {
        if (!isset($this->bbEntrySlots[$startPc])) return;
        foreach ($this->bbEntrySlots[$startPc] as $slot) {
            $this->push(new LocalRead($slot));
        }
    }

    /**
     * Spill stack to slots shared by all successors of a Switch
     * terminator (default PC + every case target PC). Same shape across
     * all per JVM verifier; allocate once on the default-PC entry, mirror
     * to each case target.
     *
     * @param int[] $caseTargetPcs unique target PCs for the case arms
     */
    private function spillStackForSwitch(int $defaultPc, array $caseTargetPcs): void
    {
        $depth = count($this->abstractStack);
        if ($depth === 0) return;
        $slots = $this->allocOrReuseSlots($defaultPc, $depth);
        foreach ($caseTargetPcs as $tgt) {
            if (isset($this->bbEntrySlots[$tgt])) {
                if ($this->bbEntrySlots[$tgt] !== $slots) {
                    throw new \LogicException(sprintf(
                        "Switch slot mismatch at PC %d", $tgt
                    ));
                }
            } else {
                $this->bbEntrySlots[$tgt] = $slots;
            }
        }
        for ($i = $depth - 1; $i >= 0; $i--) {
            $val = $this->pop();
            $this->currentBb->stmts[] = new StoreLocal($slots[$i], $val);
        }
    }

    /**
     * Parse a TABLESWITCH (0xAA) or LOOKUPSWITCH (0xAB) instruction
     * starting at the opcode byte $start. Returns the parsed table
     * (default target, case-value→target map, byte position after
     * the operand block).
     *
     * Both opcodes are variable-length and have padding bytes after
     * the opcode to align the first 4-byte operand at an offset that
     * is a multiple of four from the start of the method.
     *
     * @return array{default:int, cases:array<int,int>, nextPc:int}
     */
    private function parseSwitch(array $bytes, int $start): array
    {
        $op = $bytes[$start];
        $afterOp = $start + 1;
        $pad = (4 - ($afterOp & 3)) & 3;
        $cursor = $afterOp + $pad;
        $defOff = $this->readS32($bytes, $cursor); $cursor += 4;
        $cases = [];
        if ($op === 0xAA) {
            // tableswitch: low, high, then (high-low+1) offsets
            $low  = $this->readS32($bytes, $cursor); $cursor += 4;
            $high = $this->readS32($bytes, $cursor); $cursor += 4;
            for ($v = $low; $v <= $high; $v++) {
                $off = $this->readS32($bytes, $cursor); $cursor += 4;
                $cases[$v] = $start + $off;
            }
        } else {
            // lookupswitch: npairs, then npairs * (match, offset)
            $npairs = $this->readS32($bytes, $cursor); $cursor += 4;
            for ($i = 0; $i < $npairs; $i++) {
                $val = $this->readS32($bytes, $cursor); $cursor += 4;
                $off = $this->readS32($bytes, $cursor); $cursor += 4;
                $cases[$val] = $start + $off;
            }
        }
        return [
            'default' => $start + $defOff,
            'cases'   => $cases,
            'nextPc'  => $cursor,
        ];
    }

    /** Read a signed 32-bit big-endian int from $bytes at $offset. */
    private function readS32(array $bytes, int $offset): int
    {
        $v = ($bytes[$offset] << 24)
           | ($bytes[$offset + 1] << 16)
           | ($bytes[$offset + 2] << 8)
           |  $bytes[$offset + 3];
        if ($v & 0x80000000) $v -= 0x100000000;
        return $v;
    }

    /** Single-operand if: pop, compare against $rhs, branch. */
    private function emitIfPop(string $op, Expr $rhs, array $bytes, int $start): void
    {
        $offset = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
        if ($offset & 0x8000) $offset -= 0x10000;
        $left = $this->pop();
        $thenPc = $start + $offset;
        $elsePc = $this->pc;
        // Spill any residual stack to slots shared by both successors
        // (sub-step 1c-β; verifier guarantees both targets see same shape).
        $this->spillStackForBoth($thenPc, $elsePc);
        $this->currentBb->term = new CondGoto(
            new BinOp($op, $left, $rhs),
            $thenPc, $elsePc,
        );
        $this->branchTargets[$elsePc] = true;
    }

    private function emitCondGoto(string $op, array $bytes, int $start): void
    {
        $offset = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
        if ($offset & 0x8000) $offset -= 0x10000;
        $right = $this->pop();
        $left = $this->pop();
        $thenPc = $start + $offset;
        $elsePc = $this->pc;
        $this->spillStackForBoth($thenPc, $elsePc);
        $this->currentBb->term = new CondGoto(
            new BinOp($op, $left, $right),
            $thenPc, $elsePc,
        );
        // Mark fall-through PC as a BB boundary so the next opcode
        // starts a new block.
        $this->branchTargets[$elsePc] = true;
    }

    private function emitInvokeStatic(int $idx): void
    {
        $ref = $this->constantPool[$idx] ?? null;
        if (!$ref || !method_exists($ref, 'getClassIndex')) {
            throw new \LogicException("invokestatic CP idx {$idx} not Methodref-shaped");
        }
        $clsInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
        $nat = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        $clsName = $this->utf8At($clsInfo->getClassIndex());
        $methodName = $this->utf8At($nat->getNameIndex());
        $desc = $this->utf8At($nat->getDescriptorIndex());
        [$argTypes, $ret] = $this->parseDescriptor($desc);
        $argc = count($argTypes);

        $args = [];
        for ($i = $argc - 1; $i >= 0; $i--) $args[$i] = $this->pop();
        ksort($args);
        $args = array_values($args);

        $folded = self::lowerWrapperStaticCall($clsName, $methodName, $argTypes, $args);
        if ($folded !== null) {
            if ($ret === 'V') {
                $this->currentBb->stmts[] = new ExprStmt($folded);
            } else {
                $this->push($folded);
            }
            return;
        }

        $fqn = $this->classFqn($clsName);
        $bin = $this->generatedBinaryName($clsName);
        $call = new StaticCall($fqn, $this->mangleMethodForInvoke($clsName, $methodName, $desc), $args, $bin);

        if ($ret === 'V') {
            $this->currentBb->stmts[] = new ExprStmt($call);
        } else {
            $this->push($call);
        }
    }

    /**
     * Instance-method call. Special-cases the new+dup+invokespecial
     * <init> pattern: replaces the dup'd uninit marker with a proper
     * New_(class, args) Expr, so the emit becomes `new \X(args)`.
     */
    private function emitInvokeInstance(int $idx, bool $isSpecial): void
    {
        [$cls, $methodName, $desc] = $this->resolveMethodRef($idx);
        [$argTypes, $ret] = $this->parseDescriptor($desc);
        $argc = count($argTypes);

        $args = [];
        for ($i = $argc - 1; $i >= 0; $i--) $args[$i] = $this->pop();
        ksort($args);
        $args = array_values($args);
        $receiver = $this->pop();

        // new+dup+invokespecial<init> peephole. The receiver is the
        // dup'd uninit marker; the abstractStack still has one more
        // copy of it from the original new+dup. Replace BOTH with
        // a single New_(class, args) Expr.
        if ($isSpecial && $methodName === '<init>'
            && $receiver instanceof StaticCall && $receiver->method === '__uninit') {
            // Pop the second uninit copy (left over from dup).
            $other = end($this->abstractStack);
            if ($other instanceof StaticCall && $other->method === '__uninit'
                && $other->classFqn === $receiver->classFqn) {
                array_pop($this->abstractStack);
            }
            $this->push(new New_($receiver->classFqn, $args, $this->generatedBinaryName($cls)));
            return;
        }

        // super(...) call inside an instance method — `aload_0;
        // invokespecial X.<init>(...)V`, receiver is `$this`.
        //
        // - When the emitted PHP class will `extends` X (Compiler set
        //   $this->superClassBin === X): emit `parent::__construct(...)`
        //   so the parent's field-initialisation putfield's run on
        //   $this. Required for inherited fields to materialise.
        // - Otherwise (no `extends`, or super is a JDK base we don't
        //   chain into): no-op. There's no PHP target.
        // - The current-class case is `this(...)` (delegating
        //   constructor) — left alone (routes through InstanceCall).
        if ($isSpecial && $methodName === '<init>'
            && $receiver instanceof LocalRead && $receiver->slot === 0
            && $cls !== $this->currentClassBin) {
            if ($this->superClassBin !== null && $cls === $this->superClassBin) {
                $call = new StaticCall('parent', '__construct', $args);
                $this->currentBb->stmts[] = new ExprStmt($call);
            }
            return;
        }

        // BOXING.md lowering: wrapper-class instance methods on raw
        // scalars. Per CONTRACTS.md §1, primitive wrapper instances
        // never exist at runtime — the receiver IS the value. So
        // i.intValue() is identity, i.equals(j) is ===, etc. Inline
        // the lowering at IR build time so the AOT-emitted code is
        // pure scalar ops with no dispatch.
        $folded = self::lowerWrapperInstanceCall($cls, $methodName, $receiver, $args);
        if ($folded !== null) {
            if ($ret === 'V') {
                $this->currentBb->stmts[] = new ExprStmt($folded);
            } else {
                $this->push($folded);
            }
            return;
        }

        // Raw-scalar adapter: classes whose AOT runtime representation
        // is a PHP scalar (String → string) can't take instance-method
        // dispatch (`$s->charAt(0)` on a string is a fatal error). The
        // bootstrap.php `String_` class exposes the common methods as
        // statics with the receiver as first arg; emit a StaticCall.
        $adapterFqn = self::staticAdapterFqn($cls);
        if ($adapterFqn !== null) {
            $allArgs = $args;
            \array_unshift($allArgs, $receiver);
            $call = new StaticCall($adapterFqn, $this->mangleMethodForInvoke($cls, $methodName, $desc), $allArgs);
            if ($ret === 'V') {
                $this->currentBb->stmts[] = new ExprStmt($call);
            } else {
                $this->push($call);
            }
            return;
        }

        $call = new InstanceCall($receiver, $this->mangleMethodForInvoke($cls, $methodName, $desc), $args);
        if ($ret === 'V') {
            $this->currentBb->stmts[] = new ExprStmt($call);
        } else {
            $this->push($call);
        }
    }

    /**
     * JDK classes whose AOT runtime form is a PHP scalar — instance
     * dispatch is rewritten to a static call against the adapter class
     * with the receiver as first argument. Extend as raw-scalar adapter
     * shims land in `Aot/Runtime/bootstrap.php`.
     */
    private static function staticAdapterFqn(string $clsBin): ?string
    {
        return match ($clsBin) {
            'java/lang/String' => '\\PHPJava\\Aot\\Runtime\\java\\lang\\String_',
            default            => null,
        };
    }

    /**
     * Per BOXING.md, primitive-wrapper public-static fields (MAX_VALUE,
     * MIN_VALUE, SIZE, BYTES, POSITIVE_INFINITY, NEGATIVE_INFINITY) are
     * compile-time constants. Fold to literal IR nodes so the AOT-emitted
     * code never reaches the wrapper shim.
     *
     * Returns null for non-wrapper or unknown fields → caller emits the
     * standard StaticFieldRead.
     */
    private static function lowerWrapperStaticField(string $cls, string $field): ?Expr
    {
        return match ("{$cls}.{$field}") {
            'java/lang/Integer.MAX_VALUE'   => new IntLit(2147483647),
            'java/lang/Integer.MIN_VALUE'   => new IntLit(-2147483648),
            'java/lang/Integer.SIZE'        => new IntLit(32),
            'java/lang/Integer.BYTES'       => new IntLit(4),

            'java/lang/Long.MAX_VALUE'      => new IntLit(\PHP_INT_MAX),
            'java/lang/Long.MIN_VALUE'      => new IntLit(\PHP_INT_MIN),
            'java/lang/Long.SIZE'           => new IntLit(64),
            'java/lang/Long.BYTES'          => new IntLit(8),

            'java/lang/Short.MAX_VALUE'     => new IntLit(32767),
            'java/lang/Short.MIN_VALUE'     => new IntLit(-32768),
            'java/lang/Short.SIZE'          => new IntLit(16),
            'java/lang/Short.BYTES'         => new IntLit(2),

            'java/lang/Byte.MAX_VALUE'      => new IntLit(127),
            'java/lang/Byte.MIN_VALUE'      => new IntLit(-128),
            'java/lang/Byte.SIZE'           => new IntLit(8),
            'java/lang/Byte.BYTES'          => new IntLit(1),

            'java/lang/Character.MAX_VALUE' => new IntLit(0xFFFF),
            'java/lang/Character.MIN_VALUE' => new IntLit(0),
            'java/lang/Character.SIZE'      => new IntLit(16),
            'java/lang/Character.BYTES'     => new IntLit(2),

            'java/lang/Float.MAX_VALUE'         => new FloatLit(3.4028235e38),
            'java/lang/Float.MIN_VALUE'         => new FloatLit(1.4e-45),
            'java/lang/Float.POSITIVE_INFINITY' => new FloatLit(\INF),
            'java/lang/Float.NEGATIVE_INFINITY' => new FloatLit(-\INF),
            'java/lang/Float.NaN'               => new FloatLit(\NAN),

            'java/lang/Double.MAX_VALUE'         => new FloatLit(\PHP_FLOAT_MAX),
            'java/lang/Double.MIN_VALUE'         => new FloatLit(\PHP_FLOAT_MIN),
            'java/lang/Double.POSITIVE_INFINITY' => new FloatLit(\INF),
            'java/lang/Double.NEGATIVE_INFINITY' => new FloatLit(-\INF),
            'java/lang/Double.NaN'               => new FloatLit(\NAN),

            default => null,
        };
    }

    /**
     * Per BOXING.md §"Mapping JVM contract to PHP semantics", primitive-
     * wrapper static methods reduce to identity, basic operators, or
     * single shim calls. Inline at IR build time.
     *
     * - Wrapper.valueOf(primitive)   → identity  (no boxing)
     * - Wrapper.compare(a, b)        → a <=> b
     * - Wrapper.max(a, b) / min      → ternary  (avoid \max/\min cost)
     * - Wrapper.toString(primitive)  → (string) primitive
     * - Number.intValue/etc statics  → cast
     *
     * Wrapper.parseInt(String) is left as-is (still routes through the
     * shim — the shim does the validation and throws NumberFormatException
     * to match JDK; inlining (int)$s would silently accept "abc" → 0).
     */
    private static function lowerWrapperStaticCall(
        string $cls,
        string $method,
        array $argTypes,
        array $args
    ): ?Expr {
        if (!self::isPrimitiveWrapperClass($cls)) return null;
        $argc = \count($args);

        // valueOf(primitive) → identity. valueOf(String) is parseInt for
        // int/long/etc. and is left to the shim.
        if ($method === 'valueOf' && $argc === 1
            && isset($argTypes[0])
            && self::isJvmPrimitiveDescriptor($argTypes[0])) {
            return $args[0];
        }

        // compare(a, b) → spaceship. JVM semantics: returns int sign
        // (-1/0/1 in spec; PHP <=> returns -1/0/1 too).
        if ($method === 'compare' && $argc === 2) {
            return new BinOp('<=>', $args[0], $args[1]);
        }

        // toString(primitive) → (string)x. The 1-arg int form for
        // Integer/Long is the common case; the 2-arg radix form falls
        // through to the shim.
        if ($method === 'toString' && $argc === 1) {
            return new StaticCall('\\strval', '', [$args[0]]);
        }

        // Number.intValue / longValue / etc. as statics — Java doesn't
        // expose these as statics on Number, but Integer.intValue exists.
        // Cast to the appropriate PHP scalar type.
        if ($argc === 1) {
            $cast = match ($method) {
                'intValue', 'shortValue', 'byteValue' => '\\intval',
                'longValue'                            => '\\intval',
                'doubleValue', 'floatValue'           => '\\floatval',
                'booleanValue'                         => '\\boolval',
                default                                => null,
            };
            if ($cast !== null) {
                return new StaticCall($cast, '', [$args[0]]);
            }
        }

        return null;
    }

    /**
     * Per BOXING.md, wrapper instance methods on raw-scalar receivers
     * inline to identity, ===, basic ops. The receiver is the value.
     *
     * - i.intValue() / .longValue() / etc. → identity (or numeric cast)
     * - i.equals(j)                        → $i === $j ? 1 : 0
     * - i.hashCode()                       → identity (Integer.hashCode IS the int)
     * - i.toString()                       → (string)$i
     * - i.compareTo(j)                     → $i <=> $j
     */
    private static function lowerWrapperInstanceCall(
        string $cls,
        string $method,
        Expr $receiver,
        array $args
    ): ?Expr {
        if (!self::isPrimitiveWrapperClass($cls)) return null;
        $argc = \count($args);

        // Identity unboxing — i.intValue(), l.longValue(), etc.
        if ($argc === 0) {
            switch ($method) {
                case 'intValue':
                case 'shortValue':
                case 'byteValue':
                case 'longValue':
                    return $cls === 'java/lang/Double' || $cls === 'java/lang/Float'
                        ? new StaticCall('\\intval', '', [$receiver])
                        : $receiver;
                case 'doubleValue':
                case 'floatValue':
                    return $cls === 'java/lang/Double' || $cls === 'java/lang/Float'
                        ? $receiver
                        : new StaticCall('\\floatval', '', [$receiver]);
                case 'booleanValue':
                    return $receiver;
                case 'hashCode':
                    // Integer.hashCode IS the int value per JVMS. For other
                    // wrappers, fall through to the shim — Long.hashCode
                    // mixes high/low halves; Double/Float bit-fiddle.
                    return $cls === 'java/lang/Integer' ? $receiver : null;
                case 'toString':
                    return new StaticCall('\\strval', '', [$receiver]);
            }
        }

        if ($argc === 1) {
            // i.equals(j) → ($i === $j ? 1 : 0). JVM semantics: returns
            // boolean (Z), which is int 0/1 on the operand stack.
            if ($method === 'equals') {
                return new BinOp('===', $receiver, $args[0]);
            }
            // i.compareTo(j) → $i <=> $j (returns -1/0/1).
            if ($method === 'compareTo') {
                return new BinOp('<=>', $receiver, $args[0]);
            }
        }

        return null;
    }

    private static function isPrimitiveWrapperClass(string $cls): bool
    {
        return match ($cls) {
            'java/lang/Integer',
            'java/lang/Long',
            'java/lang/Short',
            'java/lang/Byte',
            'java/lang/Character',
            'java/lang/Float',
            'java/lang/Double',
            'java/lang/Boolean',
            'java/lang/Number' => true,
            default            => false,
        };
    }

    private static function isJvmPrimitiveDescriptor(string $d): bool
    {
        return $d === 'I' || $d === 'J' || $d === 'D' || $d === 'F'
            || $d === 'S' || $d === 'B' || $d === 'C' || $d === 'Z';
    }

    /**
     * Convert a value coming OFF a Z (boolean) or C (char) field — stored
     * per CONTRACTS.md §1 as PHP bool / 1-char string — to the int the
     * JVM operand stack expects (boolean and char are int-on-stack at
     * the JVM bytecode level).
     *
     * Other descriptors pass through unchanged.
     */
    private static function widenForJvmStack(string $desc, Expr $e): Expr
    {
        if ($desc === 'Z') return new StaticCall('\\intval', '', [$e]);
        // Java char is UTF-16 (0..0xFFFF), so the storage form is multi-
        // byte UTF-8. Use mb_ord so we get the codepoint, not just the
        // first byte (\ord on a multi-byte string returns the leading
        // byte only — wrong for any non-ASCII char).
        if ($desc === 'C') return new StaticCall('\\mb_ord', '', [$e, new StringLit('UTF-8')]);
        return $e;
    }

    /**
     * Convert a value coming OFF the JVM stack (int for Z/C) to the
     * contract-shaped storage form (PHP bool / multi-byte UTF-8 string)
     * before writing to a Z- or C-typed field. Round-trip with
     * widenForJvmStack.
     */
    private static function narrowForFieldStorage(string $desc, Expr $e): Expr
    {
        if ($desc === 'Z') return new BinOp('!==', $e, new IntLit(0));
        if ($desc === 'C') return new StaticCall('\\mb_chr', '', [$e, new StringLit('UTF-8')]);
        // Java `float` is binary32; PHP `float` is binary64. Narrow at
        // the field/array storage boundary so write-then-read round-trips
        // give the 32-bit-precise value Java code expects.
        if ($desc === 'F') return new StaticCall('\\PHPJava\\Aot\\Runtime\\jvm_f32', '', [$e]);
        return $e;
    }

    /**
     * `instanceof` short-circuit for classes whose runtime form is a
     * PHP scalar or a universal predicate. Returns the IR Expr that
     * yields the appropriate boolean, or null if the standard
     * `is_a($ref, $fqn, true)` path should be used.
     *
     * - `String` / `CharSequence`: `is_string($ref)` (also handles null
     *   correctly — is_string(null) is false).
     * - `Object`: `$ref !== null` (every JVM ref + raw scalar is an
     *   Object except null per JVM spec).
     */
    private static function scalarInstanceOfCheck(string $clsBin, Expr $ref): ?Expr
    {
        return match ($clsBin) {
            'java/lang/String', 'java/lang/CharSequence'
                => new StaticCall('\\is_string', '', [$ref]),
            'java/lang/Object'
                => new BinOp('!==', $ref, new \PHPJava\Aot\Ir\NullLit()),
            default => null,
        };
    }

    /** Emit invokedynamic. Whitelisted bootstraps: StringConcatFactory,
     *  LambdaMetafactory. Others: emit a placeholder StaticCall. */
    private function emitInvokeDynamic(int $idx): void
    {
        $idy = $this->constantPool[$idx] ?? null;
        if (!($idy instanceof InvokeDynamicInfo)) {
            $this->push(new StringLit('UNRESOLVED_INDY'));
            return;
        }
        $bsm = $this->bootstrapMethods[$idy->getBootstrapMethodAttrIndex()] ?? null;
        $nat = $this->constantPool[$idy->getNameAndTypeIndex()] ?? null;
        if (!$bsm || !($nat instanceof NameAndTypeInfo)) {
            $this->push(new StringLit('UNRESOLVED_INDY'));
            return;
        }
        $callSiteName = $this->utf8At($nat->getNameIndex());
        $callSiteDesc = $this->utf8At($nat->getDescriptorIndex());

        $mh = $this->constantPool[$bsm->getBootstrapMethodRef()] ?? null;
        $bsmClass = '?'; $bsmMethod = '?';
        if ($mh instanceof MethodHandleInfo) {
            $ref = $this->constantPool[$mh->getReferenceIndex()] ?? null;
            if ($ref instanceof MethodrefInfo || $ref instanceof InterfaceMethodrefInfo) {
                $clsInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
                $refNat = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
                if ($clsInfo instanceof ClassInfo) {
                    $bsmClass = $this->utf8At($clsInfo->getClassIndex());
                }
                if ($refNat instanceof NameAndTypeInfo) {
                    $bsmMethod = $this->utf8At($refNat->getNameIndex());
                }
            }
        }

        // LambdaMetafactory: generate synthetic class, emit `new`.
        if ($bsmClass === 'java/lang/invoke/LambdaMetafactory'
            && in_array($bsmMethod, ['metafactory', 'altMetafactory'], true)) {
            $this->emitLambdaIndy($idy, $bsm, $callSiteName, $callSiteDesc);
            return;
        }

        // SwitchBootstraps.{typeSwitch,enumSwitch} — Java 21+ pattern
        // switch. Bootstrap returns int: matched case index, or -1 for
        // default. Bootstrap args are the case labels (ClassInfo for
        // type cases, IntegerInfo for int-constant cases, null for
        // catchall). Call site descriptor pops (selector, restartIdx)
        // and pushes int. We ignore restartIdx (assume 0) — fall-through
        // restart-from-N is rare and a runtime bug if encountered.
        if ($bsmClass === 'java/lang/runtime/SwitchBootstraps'
            && in_array($bsmMethod, ['typeSwitch', 'enumSwitch'], true)) {
            $this->emitSwitchBootstrapsIndy($idy, $bsm, $callSiteDesc);
            return;
        }

        // ObjectMethods.bootstrap — Java records' synthetic equals /
        // hashCode / toString. Bootstrap args:
        //   [0] = ClassInfo for the record
        //   [1] = StringInfo of semicolon-separated field names
        //   [2..] = MethodHandles for each field's getter (REF_getField)
        // callSiteName selects the operation: 'equals', 'hashCode', 'toString'.
        //
        // Note: emit is structurally valid PHP, but runtime correctness
        // requires AOT to emit record instance methods AND <init> as PHP
        // instance methods (currently all emitted public-static; field
        // declarations also missing). End-to-end record execution
        // pending a future "instance-method emit" task.
        if ($bsmClass === 'java/lang/runtime/ObjectMethods'
            && $bsmMethod === 'bootstrap') {
            $this->emitObjectMethodsIndy($idy, $bsm, $callSiteName, $callSiteDesc);
            return;
        }

        // StringConcatFactory: decode recipe + concat with PHP `.`.
        if ($bsmClass === 'java/lang/invoke/StringConcatFactory'
            && $bsmMethod === 'makeConcatWithConstants') {
            $bsmArgs = $bsm->getBootstrapArguments();
            $recipe = '';
            if (isset($bsmArgs[0]) && $bsmArgs[0] instanceof StringInfo) {
                $recipe = $this->utf8At($bsmArgs[0]->getStringIndex());
            }
            [$argTypes, ] = $this->parseDescriptor($callSiteDesc);
            $argc = count($argTypes);
            $args = [];
            for ($i = $argc - 1; $i >= 0; $i--) $args[$i] = $this->pop();
            ksort($args);
            $args = array_values($args);
            $this->push($this->buildConcatExpr($recipe, $args));
            return;
        }

        // Other bootstraps: emit a runtime IndyRegistry::resolve call.
        // Per CONTRACTS.md §3 + §5 — extension hook for non-whitelisted
        // indy patterns (JRuby / Groovy 3+ / Scala 3 dynamic dispatch).
        // Default behaviour throws UnsupportedIndyBootstrapException
        // with a clear message; consumers register a handler via
        // \PHPJava\Aot\Runtime\IndyRegistry::register('<bsmClass>',
        // '<bsmMethod>', $callable). Replaces the prior UNHANDLED_INDY
        // string sentinel which silently corrupted operand-stack values.
        $this->emitUnknownIndy($bsm, $bsmClass, $bsmMethod, $callSiteName, $callSiteDesc);
    }

    /** Emit a runtime-resolved indy call site for non-whitelisted bootstraps. */
    private function emitUnknownIndy(
        $bsm,
        string $bsmClass,
        string $bsmMethod,
        string $callSiteName,
        string $callSiteDesc
    ): void {
        // Pop call-site args from the abstract stack (reverse order).
        [$argTypes, ] = $this->parseDescriptor($callSiteDesc);
        $argc = count($argTypes);
        $callSiteArgs = [];
        for ($i = $argc - 1; $i >= 0; $i--) {
            $callSiteArgs[$i] = $this->pop();
        }
        ksort($callSiteArgs);
        $callSiteArgs = array_values($callSiteArgs);

        // Encode bootstrap args from the constant pool. Best-effort:
        // primitives + class/method-type FQNs as strings; complex
        // values (MethodHandle, dynamic constants) as null. Handlers
        // that need the full structure can resolve via their own
        // reflection — the AOT-emitted call passes the registry the
        // bootstrap class+method names so the handler knows which
        // bootstrap method to consult.
        $bsmArgExprs = [];
        foreach ($bsm->getBootstrapArguments() as $arg) {
            $bsmArgExprs[] = $this->encodeBsmArg($arg);
        }

        $this->push(new \PHPJava\Aot\Ir\StaticCall(
            '\\PHPJava\\Aot\\Runtime\\IndyRegistry::resolve',
            '',
            [
                new StringLit($bsmClass),
                new StringLit($bsmMethod),
                new StringLit($callSiteName),
                new StringLit($callSiteDesc),
                new \PHPJava\Aot\Ir\ArrayLit($bsmArgExprs),
                new \PHPJava\Aot\Ir\ArrayLit($callSiteArgs),
            ]
        ));
    }

    /** Encode a single bootstrap-argument CP entry as an Expr. */
    private function encodeBsmArg($arg): Expr
    {
        if ($arg instanceof StringInfo) {
            return new StringLit($this->utf8At($arg->getStringIndex()));
        }
        if ($arg instanceof IntegerInfo) {
            return new IntLit((int) $arg->getBytes());
        }
        if ($arg instanceof FloatInfo) {
            return new FloatLit((float) $arg->getBytes());
        }
        if ($arg instanceof LongInfo) {
            return new IntLit((int) $arg->getBytes());
        }
        if ($arg instanceof DoubleInfo) {
            return new FloatLit((float) $arg->getBytes());
        }
        if ($arg instanceof ClassInfo) {
            // Pass the JVM-form FQN so handlers can resolve via PHPJava
            // ClassLoader if needed.
            return new StringLit($this->utf8At($arg->getClassIndex()));
        }
        if ($arg instanceof MethodTypeInfo) {
            return new StringLit($this->utf8At($arg->getDescriptorIndex()));
        }
        // MethodHandleInfo + dynamic constants + others — null
        // placeholder. Handlers needing these wire up their own
        // reflection. Better to be honest about the missing data than
        // to fabricate a fake value.
        return new NullLit();
    }

    /**
     * LambdaMetafactory: generate a synthetic PHP class (registered in
     * $this->lambdaClasses), then emit `new \LambdaClass(captures...)`
     * at the indy call site as a New_ Expr.
     */
    private function emitLambdaIndy(InvokeDynamicInfo $idy, $bsm, string $samName, string $callSiteDesc): void
    {
        $bsmArgs = $bsm->getBootstrapArguments();
        // [1] is implMethod — a MethodHandle pointing to the lambda body.
        $implHandle = $bsmArgs[1] ?? null;
        if (!($implHandle instanceof MethodHandleInfo)) {
            $this->push(new StringLit('UNRESOLVED_LAMBDA'));
            return;
        }
        $ref = $this->constantPool[$implHandle->getReferenceIndex()] ?? null;
        if (!($ref instanceof MethodrefInfo) && !($ref instanceof InterfaceMethodrefInfo)) {
            $this->push(new StringLit('UNRESOLVED_LAMBDA'));
            return;
        }
        $clsInfo = $this->constantPool[$ref->getClassIndex()] ?? null;
        $natRef = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        $implClass = $clsInfo instanceof ClassInfo ? $this->utf8At($clsInfo->getClassIndex()) : '?';
        $implMethod = $natRef instanceof NameAndTypeInfo ? $this->utf8At($natRef->getNameIndex()) : '?';

        // SAM descriptor from bootstrap arg [0] (a MethodType).
        $samDesc = '()V';
        if (isset($bsmArgs[0])) {
            $mt = $bsmArgs[0];
            if (method_exists($mt, 'getDescriptorIndex')) {
                $samDesc = $this->utf8At($mt->getDescriptorIndex());
            } elseif ($mt instanceof Utf8Info) {
                $samDesc = $mt->getString();
            }
        }
        [$samArgTypes, $samRet] = $this->parseDescriptor($samDesc);
        [$callSiteArgTypes, ] = $this->parseDescriptor($callSiteDesc);
        $captureCount = count($callSiteArgTypes);
        $samArgCount = count($samArgTypes);

        // Synthetic lambda class.
        $idx = $this->lambdaCounter++;
        $ownerMangled = str_replace(['/', '$'], '_', $this->currentClassBin);
        $lambdaClassName = "{$ownerMangled}__Lambda_{$idx}";
        $implFqn = $implClass === $this->currentClassBin
            ? ($this->aotClassFqn ?? '\\PHPJava\\Aot\\Ir\\Generated\\' . str_replace('/', '_', $implClass))
            : $this->classFqn($implClass);
        $implMethodPhp = $this->mangleMethod($implMethod);
        $samNamePhp = $this->mangleMethod($samName);

        $ctorParams = []; $ctorAssigns = []; $fieldDecls = [];
        for ($i = 0; $i < $captureCount; $i++) {
            $ctorParams[] = "\$c{$i}";
            $ctorAssigns[] = "\$this->c{$i} = \$c{$i};";
            $fieldDecls[] = "    private \$c{$i};";
        }
        $samParams = []; $callArgs = [];
        for ($i = 0; $i < $captureCount; $i++) $callArgs[] = "\$this->c{$i}";
        for ($i = 0; $i < $samArgCount; $i++) {
            $samParams[] = "\$a{$i}";
            $callArgs[] = "\$a{$i}";
        }
        $samCall = "{$implFqn}::{$implMethodPhp}(" . implode(', ', $callArgs) . ")";
        $samBody = $samRet === 'V' ? "        {$samCall};" : "        return {$samCall};";

        $fieldsBlock = $fieldDecls ? implode("\n", $fieldDecls) . "\n" : '';
        $ctorBlock = $captureCount > 0
            ? "    public function __construct(" . implode(', ', $ctorParams) . ") {\n        " . implode("\n        ", $ctorAssigns) . "\n    }"
            : '';
        $samBlock = "    public function {$samNamePhp}(" . implode(', ', $samParams) . ") {\n{$samBody}\n    }";
        $lambdaSrc = "namespace PHPJava\\Aot\\Ir\\Generated;\n\nfinal class {$lambdaClassName}\n{\n{$fieldsBlock}{$ctorBlock}\n{$samBlock}\n}\n";
        $this->lambdaClasses[] = [
            'fqn' => "\\PHPJava\\Aot\\Ir\\Generated\\{$lambdaClassName}",
            'php' => $lambdaSrc,
        ];

        // Emit at the call site: pop captures, emit New_(LambdaClass, captureExprs).
        $captures = [];
        for ($i = $captureCount - 1; $i >= 0; $i--) $captures[$i] = $this->pop();
        ksort($captures);
        $this->push(new New_("\\PHPJava\\Aot\\Ir\\Generated\\{$lambdaClassName}", array_values($captures)));
    }

    /**
     * SwitchBootstraps.typeSwitch / enumSwitch — Java 21+ pattern switch.
     * Bootstrap returns int matched-case-index (-1 = default). bsmArgs
     * are the case labels: ClassInfo (type-pattern), IntegerInfo (int
     * constant case), null (catchall).
     *
     * Call site descriptor: `(Object, int)int` — pop selector + restart.
     * We ignore restart (treat as 0): the int restart-from-N variant
     * is for fall-through patterns; first emit unsupported until a
     * fixture surfaces it.
     */
    private function emitSwitchBootstrapsIndy(
        InvokeDynamicInfo $idy, $bsm, string $callSiteDesc
    ): void {
        // Pop callsite args: selector (Object), restart (int).
        [$argTypes, ] = $this->parseDescriptor($callSiteDesc);
        $argc = count($argTypes);
        // Drop restart (top of stack); keep selector.
        $popped = [];
        for ($i = $argc - 1; $i >= 0; $i--) $popped[$i] = $this->pop();
        ksort($popped);
        $popped = array_values($popped);
        $selector = $popped[0] ?? new \PHPJava\Aot\Ir\NullLit();

        // Build labels array from bsmArgs.
        $labelExprs = [];
        foreach ($bsm->getBootstrapArguments() as $arg) {
            if ($arg === null) {
                $labelExprs[] = new \PHPJava\Aot\Ir\NullLit();
                continue;
            }
            if ($arg instanceof ClassInfo) {
                $bin = $this->utf8At($arg->getClassIndex());
                $fqn = '\\PHPJava\\Aot\\Generated\\'
                    . str_replace(['.', '/', '\\', '$'], '_', $bin);
                $labelExprs[] = new StringLit($fqn);
                continue;
            }
            if ($arg instanceof IntegerInfo) {
                $labelExprs[] = new IntLit($arg->getValue());
                continue;
            }
            if ($arg instanceof StringInfo) {
                // Some bootstraps include string-constant cases.
                $labelExprs[] = new StringLit(
                    $this->utf8At($arg->getStringIndex())
                );
                continue;
            }
            // Unknown label kind — push null catchall as conservative
            // default; full coverage requires recognising EnumDesc,
            // MethodHandle-wrapped values, etc.
            $labelExprs[] = new \PHPJava\Aot\Ir\NullLit();
        }

        $this->push(new \PHPJava\Aot\Ir\StaticCall(
            '\\PHPJava\\Aot\\Runtime\\jvm_typeswitch', '',
            [$selector, new \PHPJava\Aot\Ir\ArrayLit($labelExprs)]
        ));
    }

    /**
     * ObjectMethods.bootstrap — emit equals/hashCode/toString as inline
     * Exprs at the indy site. Structurally-valid emit; full runtime
     * correctness pending instance-method-emit refactor (records'
     * <init> + getfield writes need PHP instance-method shape, not
     * current public-static-with-$L[0]-as-this).
     */
    private function emitObjectMethodsIndy(
        InvokeDynamicInfo $idy, $bsm, string $methodName, string $callSiteDesc
    ): void {
        $bsmArgs = $bsm->getBootstrapArguments();
        $recordClass = $bsmArgs[0] ?? null;
        $fieldNamesInfo = $bsmArgs[1] ?? null;
        if (!($recordClass instanceof ClassInfo)
            || !($fieldNamesInfo instanceof StringInfo)) {
            $this->push(new StringLit('UNRESOLVED_OBJECTMETHODS'));
            return;
        }
        $recordBin = $this->utf8At($recordClass->getClassIndex());
        // classFqn shortens to 'self' for the current class, which won't
        // work as a runtime string passed to \is_a. Use the absolute
        // \PHPJava\Aot\Generated\... form unconditionally for the type
        // check; classFqn-style 'self' optimisation is for static-call
        // emit, not class-name strings.
        $recordFqn = '\\PHPJava\\Aot\\Generated\\'
            . str_replace(['.', '/', '\\', '$'], '_', $recordBin);
        $names = $this->utf8At($fieldNamesInfo->getStringIndex());
        $fields = $names === '' ? [] : explode(';', $names);

        // Pop callsite args.
        [$argTypes, ] = $this->parseDescriptor($callSiteDesc);
        $argc = count($argTypes);
        $args = [];
        for ($i = $argc - 1; $i >= 0; $i--) $args[$i] = $this->pop();
        ksort($args);
        $args = array_values($args);

        if ($methodName === 'equals' && count($args) >= 2) {
            $self = $args[0];
            $other = $args[1];
            // \is_a($other, '\\FQN', true)
            $check = new \PHPJava\Aot\Ir\StaticCall(
                '\\is_a', '',
                [$other, new StringLit($recordFqn), new IntLit(1)]
            );
            // && $self->fieldI === $other->fieldI for each field
            foreach ($fields as $field) {
                $check = new BinOp('&&', $check,
                    new BinOp('===',
                        new \PHPJava\Aot\Ir\FieldRead($self, $field),
                        new \PHPJava\Aot\Ir\FieldRead($other, $field),
                    ),
                );
            }
            // Java boolean = JVM int; intval the bool.
            $this->push(new \PHPJava\Aot\Ir\StaticCall(
                '\\intval', '', [$check]
            ));
            return;
        }

        if ($methodName === 'hashCode' && count($args) >= 1) {
            $self = $args[0];
            // Standard Java records hashCode: 31*h + field per field.
            // For ref fields this should call .hashCode(); for primitives
            // and Strings, value used directly. For now, treat all as
            // value-equal — improves later with type-aware emit.
            $h = new IntLit(0);
            foreach ($fields as $field) {
                $h = new BinOp('+',
                    new BinOp('*', new IntLit(31), $h),
                    new \PHPJava\Aot\Ir\FieldRead($self, $field),
                );
            }
            $this->push($h);
            return;
        }

        if ($methodName === 'toString' && count($args) >= 1) {
            $self = $args[0];
            // Java record toString: SimpleName[f1=v1, f2=v2, ...].
            // SimpleName = part of binary name after the last `$` or `/`.
            $simple = preg_replace('#^.*[$/]#', '', $recordBin);
            $expr = new StringLit($simple . '[');
            $first = true;
            foreach ($fields as $field) {
                if (!$first) {
                    $expr = new BinOp('.', $expr, new StringLit(', '));
                }
                $first = false;
                $expr = new BinOp('.', $expr, new StringLit($field . '='));
                $expr = new BinOp('.', $expr,
                    new \PHPJava\Aot\Ir\FieldRead($self, $field));
            }
            $expr = new BinOp('.', $expr, new StringLit(']'));
            $this->push($expr);
            return;
        }

        // Unrecognised ObjectMethods method name.
        $this->push(new StringLit("UNHANDLED_OBJECTMETHODS:{$methodName}"));
    }

    /** Build a `'literal' . $a . 'literal'` chain expression for StringConcatFactory. */
    private function buildConcatExpr(string $recipe, array $dynArgs): Expr
    {
        $parts = []; $literal = ''; $dynIdx = 0; $n = strlen($recipe);
        for ($i = 0; $i < $n; $i++) {
            $c = $recipe[$i];
            if ($c === "\x01") {
                if ($literal !== '') { $parts[] = new StringLit($literal); $literal = ''; }
                $parts[] = $dynArgs[$dynIdx++] ?? new StringLit('');
            } else {
                $literal .= $c;
            }
        }
        if ($literal !== '') $parts[] = new StringLit($literal);
        if (empty($parts)) return new StringLit('');
        $expr = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $expr = new BinOp('.', $expr, $parts[$i]);
        }
        return $expr;
    }

    /** Resolve a Methodref/InterfaceMethodref CP idx to [classBinName, methodName, descriptor]. */
    private function resolveMethodRef(int $idx): array
    {
        $ref = $this->constantPool[$idx] ?? null;
        if (!($ref instanceof MethodrefInfo) && !($ref instanceof InterfaceMethodrefInfo)) {
            return ['?', '?', '?'];
        }
        $cls = $this->constantPool[$ref->getClassIndex()] ?? null;
        $nat = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        return [
            $cls instanceof ClassInfo ? $this->utf8At($cls->getClassIndex()) : '?',
            $nat instanceof NameAndTypeInfo ? $this->utf8At($nat->getNameIndex()) : '?',
            $nat instanceof NameAndTypeInfo ? $this->utf8At($nat->getDescriptorIndex()) : '?',
        ];
    }

    /** Resolve Fieldref CP idx to [classBinName, fieldName, descriptor]. */
    private function resolveFieldRef(int $idx): array
    {
        $ref = $this->constantPool[$idx] ?? null;
        if (!($ref instanceof FieldrefInfo)) return ['?', '?', '?'];
        $cls = $this->constantPool[$ref->getClassIndex()] ?? null;
        $nat = $this->constantPool[$ref->getNameAndTypeIndex()] ?? null;
        return [
            $cls instanceof ClassInfo ? $this->utf8At($cls->getClassIndex()) : '?',
            $nat instanceof NameAndTypeInfo ? $this->utf8At($nat->getNameIndex()) : '?',
            $nat instanceof NameAndTypeInfo ? $this->utf8At($nat->getDescriptorIndex()) : '?',
        ];
    }

    /** ldc CP entry → IR Expr. */
    private function ldcExpr(int $idx): Expr
    {
        $entry = $this->constantPool[$idx] ?? null;
        if ($entry instanceof StringInfo) return new StringLit($this->utf8At($entry->getStringIndex()));
        if ($entry instanceof IntegerInfo) {
            // PHPJava's IntegerInfo::getBytes() returns the 32-bit
            // value as an unsigned PHP int (e.g. 0x80000000 →
            // 2147483648, not -2147483648). Sign-extend explicitly:
            // if the high bit is set, subtract 2^32 to get the
            // negative form. Without this, `Integer.MIN_VALUE`
            // round-trips as 2147483648 instead of -2147483648.
            $v = $entry->getBytes();
            if ($v >= 0x80000000) $v -= 0x100000000;
            return new IntLit($v);
        }
        if ($entry instanceof FloatInfo) return new \PHPJava\Aot\Ir\FloatLit($entry->getBytes());
        if ($entry instanceof LongInfo) return new IntLit($entry->getBytes());
        if ($entry instanceof DoubleInfo) return new \PHPJava\Aot\Ir\FloatLit($entry->getBytes());
        return new \PHPJava\Aot\Ir\NullLit();
    }

    /** Map JVM binary class name to PHP FQN. */
    private function classFqn(string $binaryName): string
    {
        if ($binaryName === $this->currentClassBin) return 'self';
        $isJdk = str_starts_with($binaryName, 'java/')
              || str_starts_with($binaryName, 'javax/')
              || str_starts_with($binaryName, 'jdk/')
              || str_starts_with($binaryName, 'sun/')
              || str_starts_with($binaryName, 'com/sun/');
        $php = str_replace('/', '\\', $binaryName);
        if ($isJdk) {
            // JDK shim path: $ is rare in JDK class names; leave as-is
            // and let the shim author choose the concrete shape.
            return '\\PHPJava\\Aot\\Runtime\\' . $php;
        }
        // AOT-emitted: mirror Compiler::mangle — replace `\` and `$`
        // with `_` so the FQN matches the class-declaration name.
        return '\\PHPJava\\Aot\\Generated\\' . str_replace(['\\', '$'], '_', $php);
    }

    /**
     * Returns the JVM binary name when the target is an AOT-emitted
     * cross-class reference — null for self, JDK, and raw-PHP-fn calls.
     * The binary name is used by Lowerer to route through Loader's
     * load+dispatch helpers, sidestepping the autoloader's reverse-
     * mangle ambiguity for inner classes.
     */
    private function generatedBinaryName(string $binaryName): ?string
    {
        if ($binaryName === $this->currentClassBin) return null;
        if (str_starts_with($binaryName, 'java/')
            || str_starts_with($binaryName, 'javax/')
            || str_starts_with($binaryName, 'jdk/')
            || str_starts_with($binaryName, 'sun/')
            || str_starts_with($binaryName, 'com/sun/')) {
            return null;
        }
        return $binaryName;
    }

    private function utf8At(int $idx): string
    {
        $e = $this->constantPool[$idx] ?? null;
        return $e instanceof Utf8Info ? $e->getString() : '?';
    }

    private function parseDescriptor(string $desc): array
    {
        if (!str_starts_with($desc, '(')) return [[], 'V'];
        $end = strpos($desc, ')');
        if ($end === false) return [[], 'V'];
        $args = $this->parseTypeList(substr($desc, 1, $end - 1));
        return [$args, substr($desc, $end + 1)];
    }

    private function parseTypeList(string $s): array
    {
        $out = []; $i = 0; $n = strlen($s);
        while ($i < $n) {
            $start = $i;
            while ($i < $n && $s[$i] === '[') $i++;
            if ($i >= $n) break;
            if ($s[$i] === 'L') {
                $semi = strpos($s, ';', $i);
                if ($semi === false) break;
                $i = $semi + 1;
            } else { $i++; }
            $out[] = substr($s, $start, $i - $start);
        }
        return $out;
    }

    private function mangleMethod(string $name): string
    {
        if ($name === '<init>') return '__construct';
        if ($name === '<clinit>') return '__staticConstruct';
        return str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name);
    }

    /**
     * Descriptor-aware mangle for invoke* sites. When the target is
     * the current class AND the method name is overloaded in the
     * current class, append a descriptor suffix so we hit the right
     * PHP method (each overload was emitted with its descriptor
     * suffix). Cross-class invokes always use the simple mangle —
     * the target class's dispatcher routes by arg shape.
     */
    private function mangleMethodForInvoke(string $clsName, string $name, string $desc): string
    {
        $simple = $this->mangleMethod($name);
        $isCurrent = $clsName === $this->currentClassBin;
        if (!$isCurrent || !isset($this->overloadIndex[$simple])) {
            return $simple;
        }
        return $simple . '_' . \PHPJava\Aot\Compiler::mangleDescriptorForOverload($desc);
    }

    /**
     * Field-name mangle. Matches `Compiler::emitFieldDeclarations`'s
     * substitution: `$` → `_S_`, `<` → `_LT_`, `>` → `_GT_`. Without
     * this, JVM-internal field names like `this$0` (the synthetic
     * outer-reference on inner classes) emit as `$obj->this$0` —
     * which PHP parses as `$obj->this` concatenated with `$0`,
     * a syntax error at the next token.
     */
    private function mangleField(string $name): string
    {
        return str_replace(['$', '<', '>'], ['_S_', '_LT_', '_GT_'], $name);
    }

    private function opcodeLength(int $op): int
    {
        return match ($op) {
            0x10, 0x12, 0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0xA9, 0xBC => 1,
            0x11, 0x13, 0x14, 0x84, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E,
            0x9F, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5, 0xA6, 0xA7, 0xA8,
            0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xBB, 0xBD, 0xC0, 0xC1, 0xC6, 0xC7 => 2,
            // multianewarray: indexbyte1, indexbyte2, dimensions = 3 bytes.
            0xC5 => 3,
            0xB9, 0xBA, 0xC8, 0xC9 => 4,
            default => 0,
        };
    }
}
