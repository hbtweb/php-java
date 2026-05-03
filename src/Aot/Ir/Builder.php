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

    public function buildMethod(
        JavaCompiledClass $jcc,
        string $methodName,
        string $descriptor,
        string $bytecode,
        string $currentClassBin,
        array $exceptionTables = []
    ): Method {
        $this->constantPool = $jcc->getConstantPool()->getEntries();
        $this->currentClassBin = $currentClassBin;
        $this->abstractStack = [];
        $this->blocks = [];
        $this->branchTargets = [];
        $this->exceptionsByStart = [];
        $this->exceptionHandlerPcs = [];

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
            $pc += $this->opcodeLength($op);
        }

        // Method-entry implicitly is a branch target.
        $this->branchTargets[0] = true;
        // Exception start_pc and handler_pc are also BB boundaries.
        foreach ($this->exceptionsByStart as $startPc => $_) {
            $this->branchTargets[$startPc] = true;
        }

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
            // entry; we assert empty-stack here for safety, allowing
            // exception-handler entry where the stack starts with the
            // caught exception.
            if (isset($this->branchTargets[$start]) && $start !== 0) {
                $isHandler = isset($this->exceptionHandlerPcs[$start]);
                if (!empty($this->abstractStack) && !$isHandler) {
                    throw new \LogicException(
                        "non-empty abstract stack at BB boundary PC={$start} — "
                        . "stack-erasure assumption violated; need spill or fallback"
                    );
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
        $maxLocals = max($argc, $maxSlot + 1);

        return new Method(
            name: $this->mangleMethod($methodName),
            descriptor: $descriptor,
            isStatic: true,
            params: $params,
            maxLocals: $maxLocals,
            blocks: $this->blocks,
        );
    }

    /** Stash for emitOpcode to advance through operand bytes. */
    private int $pc = 0;

    private function emitOpcode(int $op, array $bytes, int $pc, int $start): void
    {
        $this->pc = $pc;
        switch ($op) {
            // ── const push ──────────────────────────────────────────
            case 0x02: $this->push(new IntLit(-1)); return;
            case 0x03: $this->push(new IntLit(0)); return;
            case 0x04: $this->push(new IntLit(1)); return;
            case 0x05: $this->push(new IntLit(2)); return;
            case 0x06: $this->push(new IntLit(3)); return;
            case 0x07: $this->push(new IntLit(4)); return;
            case 0x08: $this->push(new IntLit(5)); return;
            case 0x09: $this->push(new IntLit(0)); return; // lconst_0
            case 0x0A: $this->push(new IntLit(1)); return; // lconst_1
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
            case 0x1A: case 0x1E: case 0x2A: $this->push(new LocalRead(0)); return; // i/l/aload_0
            case 0x1B: case 0x1F: case 0x2B: $this->push(new LocalRead(1)); return;
            case 0x1C: case 0x20: case 0x2C: $this->push(new LocalRead(2)); return;
            case 0x1D: case 0x21: case 0x2D: $this->push(new LocalRead(3)); return;
            // wide-byte forms: i/l/f/d/aload N (byte operand)
            case 0x15: case 0x16: case 0x17: case 0x18: case 0x19:
                $idx = $bytes[$this->pc++];
                $this->push(new LocalRead($idx));
                return;
            // ── store ───────────────────────────────────────────────
            case 0x3B: case 0x3F: case 0x4B: $this->emitStore(0); return; // i/l/astore_0
            case 0x3C: case 0x40: case 0x4C: $this->emitStore(1); return;
            case 0x3D: case 0x41: case 0x4D: $this->emitStore(2); return;
            case 0x3E: case 0x42: case 0x4E: $this->emitStore(3); return;
            // wide-byte forms: i/l/f/d/astore N (byte operand)
            case 0x36: case 0x37: case 0x38: case 0x39: case 0x3A:
                $idx = $bytes[$this->pc++];
                $this->emitStoreSlot($idx);
                return;
            // ── type conversions ──────────────────────────────────
            case 0x85: case 0x86: case 0x87: case 0x88: // i2l, i2f, i2d, l2i
            case 0x8B: case 0x8E: case 0x8C: case 0x8F: // f2i, d2i, f2l, d2l
                // PHP int (no-op for i2l/l2i; for f2i/d2i/f2l/d2l, cast)
                $top = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall('\\intval', '', [$top]));
                return;
            case 0x89: case 0x8A: // l2f, l2d
                $top = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall('\\floatval', '', [$top]));
                return;
            case 0x8D: case 0x90: return; // f2d, d2f — no-op (PHP float == double)
            // ── 3-way comparison (lcmp/fcmpl/g/dcmpl/g) ────────────
            case 0x94: case 0x95: case 0x96: case 0x97: case 0x98:
                $right = $this->pop(); $left = $this->pop();
                $this->push(new BinOp('<=>', $left, $right));
                return;
            // ── if_acmpeq / if_acmpne — same emit as ===/!== ───────
            case 0xA5: $this->emitCondGoto('===', $bytes, $start); return;
            case 0xA6: $this->emitCondGoto('!==', $bytes, $start); return;
            // ── arithmetic ──────────────────────────────────────────
            case 0x60: case 0x61: $this->emitBinOp('+'); return; // *add
            case 0x64: case 0x65: $this->emitBinOp('-'); return; // *sub
            case 0x68: case 0x69: $this->emitBinOp('*'); return; // *mul
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
                $this->currentBb->term = new Goto_($start + $offset);
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
                $this->pc++; // skip atype
                $size = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\array_fill', '', [new IntLit(0), $size, new IntLit(0)]
                ));
                return;
            case 0xBD: // anewarray
                $this->pc += 2;
                $size = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\StaticCall(
                    '\\array_fill', '', [new IntLit(0), $size, new \PHPJava\Aot\Ir\NullLit()]
                ));
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
                if ($a instanceof LocalRead) {
                    $this->push(new \PHPJava\Aot\Ir\ArrayElementRead($a->slot, $i));
                } else {
                    // Fallback: array came from elsewhere (field, call return, etc).
                    // Need an in-line array-index expression; build via BinOp shim.
                    $this->push(new \PHPJava\Aot\Ir\StaticCall(
                        '\\PHPJava\\Aot\\Ir\\ArrayHelper', 'get', [$a, $i]
                    ));
                }
                return;
            case 0x4F: case 0x50: case 0x51: case 0x52: // *astore
            case 0x53: case 0x54: case 0x55: case 0x56:
                $v = $this->pop();
                $i = $this->pop();
                $a = $this->pop();
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
                [$cls, $field, ] = $this->resolveFieldRef($idx);
                $this->push(new \PHPJava\Aot\Ir\StaticFieldRead($this->classFqn($cls), $field));
                return;
            case 0xB3: // putstatic
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [$cls, $field, ] = $this->resolveFieldRef($idx);
                $val = $this->pop();
                $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\StoreStaticField(
                    $this->classFqn($cls), $field, $val
                );
                return;
            case 0xB4: // getfield
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [, $field, ] = $this->resolveFieldRef($idx);
                $obj = $this->pop();
                $this->push(new \PHPJava\Aot\Ir\FieldRead($obj, $field));
                return;
            case 0xB5: // putfield
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                [, $field, ] = $this->resolveFieldRef($idx);
                $val = $this->pop();
                $obj = $this->pop();
                $this->currentBb->stmts[] = new \PHPJava\Aot\Ir\StoreField($obj, $field, $val);
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
            case 0x57: array_pop($this->abstractStack); return; // pop
            case 0x00: return; // nop (already above)
            // ── INVOKEDYNAMIC ──────────────────────────────────────
            case 0xBA:
                $idx = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
                $this->pc += 2; // 2 reserved zero bytes
                $this->emitInvokeDynamic($idx);
                return;
            // ── more arithmetic / shifts / bitwise ─────────────────
            case 0x6C: case 0x6D: $this->emitBinOpFn('intdiv'); return;
            case 0x6E: case 0x6F: $this->emitBinOp('/'); return;
            case 0x70: case 0x71: $this->emitBinOp('%'); return;
            case 0x74: case 0x75: case 0x76: case 0x77: // *neg
                $this->push(new \PHPJava\Aot\Ir\UnaryOp('-', $this->pop()));
                return;
            case 0x7E: case 0x7F: $this->emitBinOp('&'); return;
            case 0x80: case 0x81: $this->emitBinOp('|'); return;
            case 0x82: case 0x83: $this->emitBinOp('^'); return;
            // ── monitorenter/exit (no-op for single-thread PHP) ───
            case 0xC2: case 0xC3:
                $this->pop();
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

    /** Single-operand if: pop, compare against $rhs, branch. */
    private function emitIfPop(string $op, Expr $rhs, array $bytes, int $start): void
    {
        $offset = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
        if ($offset & 0x8000) $offset -= 0x10000;
        $left = $this->pop();
        $this->currentBb->term = new CondGoto(
            new BinOp($op, $left, $rhs),
            $start + $offset,
            $this->pc,
        );
        $this->branchTargets[$this->pc] = true;
    }

    private function emitCondGoto(string $op, array $bytes, int $start): void
    {
        $offset = ($bytes[$this->pc] << 8) | $bytes[$this->pc + 1]; $this->pc += 2;
        if ($offset & 0x8000) $offset -= 0x10000;
        $right = $this->pop();
        $left = $this->pop();
        $this->currentBb->term = new CondGoto(
            new BinOp($op, $left, $right),
            $start + $offset,
            $this->pc, // fall-through
        );
        // Mark fall-through PC as a BB boundary so the next opcode
        // starts a new block.
        $this->branchTargets[$this->pc] = true;
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

        $fqn = $this->classFqn($clsName);
        $call = new StaticCall($fqn, $this->mangleMethod($methodName), $args);

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
            $this->push(new New_($receiver->classFqn, $args));
            return;
        }

        $call = new InstanceCall($receiver, $this->mangleMethod($methodName), $args);
        if ($ret === 'V') {
            $this->currentBb->stmts[] = new ExprStmt($call);
        } else {
            $this->push($call);
        }
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

        // Other bootstraps: emit a sentinel.
        [$argTypes, ] = $this->parseDescriptor($callSiteDesc);
        for ($i = 0; $i < count($argTypes); $i++) $this->pop();
        $this->push(new StringLit("UNHANDLED_INDY:{$bsmClass}.{$bsmMethod}"));
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
        if ($entry instanceof IntegerInfo) return new IntLit($entry->getBytes());
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
        return $isJdk
            ? '\\PHPJava\\Aot\\Runtime\\' . $php
            : '\\PHPJava\\Aot\\Generated\\' . str_replace('\\', '_', $php);
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

    private function opcodeLength(int $op): int
    {
        return match ($op) {
            0x10, 0x12, 0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0xA9, 0xBC => 1,
            0x11, 0x13, 0x14, 0x84, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E,
            0x9F, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5, 0xA6, 0xA7, 0xA8,
            0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xBB, 0xBD, 0xC0, 0xC1, 0xC6, 0xC7 => 2,
            0xB9, 0xBA, 0xC5, 0xC8, 0xC9 => 4,
            default => 0,
        };
    }
}
