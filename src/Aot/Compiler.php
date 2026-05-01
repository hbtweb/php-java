<?php
declare(strict_types=1);
namespace PHPJava\Aot;

use PHPJava\Core\JavaClass;
use PHPJava\Core\JavaCompiledClass;
use PHPJava\Kernel\Attributes\CodeAttribute;
use PHPJava\Kernel\Resolvers\AttributionResolver;
use PHPJava\Kernel\Structures\MethodInfo;
use PHPJava\Kernel\Structures\Utf8Info;

/**
 * Naive AOT compiler — walks PHPJava's parsed bytecode and emits a PHP
 * function per Java method. Validates the architectural claim that
 * interpreter and AOT are the same compiler with different consumers.
 *
 * Scope: enough opcodes to compile BenchAdd::sum1k. This is a spike
 * harness, not the final compiler — the production compiler will have
 * an emit method per opcode (mirroring Kernel/Mnemonics/_*::execute()),
 * proper exception-table translation, native-method dispatch through
 * the curated Packages/java/* shim layer, etc.
 *
 * Operand-stack model: PHP array. Each opcode emits PHP statements that
 * push/pop the stack. The "naive AOT" level from bench/spike-fast-interp.php.
 */
final class Compiler
{
    /** @var \PHPJava\Kernel\Structures\StructureInterface[] */
    private array $constantPool;

    public function compileClass(string $classPath): string
    {
        $cls = JavaClass::load($classPath);
        // JavaClass wraps a JavaCompiledClass via `genericClass` (private);
        // reach in via reflection — fine for a spike. The production
        // compiler will get a proper accessor.
        $rc = new \ReflectionClass($cls);
        $prop = $rc->getProperty('genericClass');
        $prop->setAccessible(true);
        /** @var JavaCompiledClass $jcc */
        $jcc = $prop->getValue($cls);

        $this->constantPool = $jcc->getConstantPool()->getEntries();
        $methods = $jcc->getDefinedMethods();

        $emittedMethods = [];
        foreach ($methods as $method) {
            $name = $this->utf8At($method->getNameIndex());
            $desc = $this->utf8At($method->getDescriptorIndex());
            // Skip <init> — constructors not in scope for the spike.
            if ($name === '<init>') continue;

            try {
                $codeAttr = AttributionResolver::resolve(
                    $method->getAttributes(),
                    CodeAttribute::class
                );
            } catch (\PHPJava\Exceptions\UnableToFindAttributionException $e) {
                continue; // abstract method — no body
            }

            $emittedMethods[] = $this->compileMethod(
                $classPath,
                $name,
                $desc,
                $codeAttr->getCode()
            );
        }

        $phpClassName = str_replace('.', '\\', $classPath);
        $body = implode("\n\n", $emittedMethods);
        return "<?php\nnamespace PHPJava\\Aot\\Generated;\n\nfinal class {$this->mangle($classPath)}\n{\n{$body}\n}\n";
    }

    private function compileMethod(string $owner, string $name, string $descriptor, string $bytecode): string
    {
        $bytes = array_values(unpack('C*', $bytecode));
        $end = count($bytes);
        $pc = 0;

        // First pass: collect branch targets for label generation.
        $labels = [0 => "L_0"];
        $pcCopy = 0;
        while ($pcCopy < $end) {
            $start = $pcCopy;
            $op = $bytes[$pcCopy++];
            $advance = $this->opcodeLength($op);
            // Branch targets: encode as signed 16-bit offset from $start
            if (in_array($op, [0xA2, 0xA7, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F, 0xA0, 0xA1, 0xA3, 0xA4, 0xA5, 0xA6], true)) {
                $hi = $bytes[$pcCopy] ?? 0;
                $lo = $bytes[$pcCopy + 1] ?? 0;
                $rawOffset = ($hi << 8) | $lo;
                if ($rawOffset & 0x8000) $rawOffset -= 0x10000;
                $target = $start + $rawOffset;
                $labels[$target] = "L_{$target}";
            }
            $pcCopy += $advance;
        }

        // Second pass: emit PHP per opcode.
        $stmts = [];
        $stmts[] = "\$L = []; // locals";
        $stmts[] = "\$stack = []; \$sp = 0;";

        $pc = 0;
        while ($pc < $end) {
            $start = $pc;
            $op = $bytes[$pc++];

            // Insert label if this offset is a branch target.
            if (isset($labels[$start])) {
                $stmts[] = "{$labels[$start]}:";
            }

            switch ($op) {
                case 0x03: // iconst_0
                    $stmts[] = "        \$stack[\$sp++] = 0;";
                    break;
                case 0x04: // iconst_1
                    $stmts[] = "        \$stack[\$sp++] = 1;";
                    break;
                case 0x05: // iconst_2
                    $stmts[] = "        \$stack[\$sp++] = 2;";
                    break;
                case 0x10: // bipush
                    $val = $this->readSignedByte($bytes, $pc); $pc += 1;
                    $stmts[] = "        \$stack[\$sp++] = {$val};";
                    break;
                case 0x11: // sipush
                    $val = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $stmts[] = "        \$stack[\$sp++] = {$val};";
                    break;
                case 0x1A: // iload_0
                    $stmts[] = "        \$stack[\$sp++] = \$L[0] ?? 0;";
                    break;
                case 0x1B: // iload_1
                    $stmts[] = "        \$stack[\$sp++] = \$L[1] ?? 0;";
                    break;
                case 0x1C: // iload_2
                    $stmts[] = "        \$stack[\$sp++] = \$L[2] ?? 0;";
                    break;
                case 0x1D: // iload_3
                    $stmts[] = "        \$stack[\$sp++] = \$L[3] ?? 0;";
                    break;
                case 0x3B: // istore_0
                    $stmts[] = "        \$L[0] = \$stack[--\$sp];";
                    break;
                case 0x3C: // istore_1
                    $stmts[] = "        \$L[1] = \$stack[--\$sp];";
                    break;
                case 0x3D: // istore_2
                    $stmts[] = "        \$L[2] = \$stack[--\$sp];";
                    break;
                case 0x3E: // istore_3
                    $stmts[] = "        \$L[3] = \$stack[--\$sp];";
                    break;
                case 0x60: // iadd
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] += \$b;";
                    break;
                case 0x64: // isub
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] -= \$b;";
                    break;
                case 0x68: // imul
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$stack[\$sp - 1] *= \$b;";
                    break;
                case 0x84: // iinc index const
                    $idx = $bytes[$pc++];
                    $delta = $this->readSignedByte($bytes, $pc); $pc += 1;
                    $stmts[] = "        \$L[{$idx}] = (\$L[{$idx}] ?? 0) + ({$delta});";
                    break;
                case 0xA2: // if_icmpge
                    $offset = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $target = $start + $offset;
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$a = \$stack[--\$sp]; if (\$a >= \$b) goto {$labels[$target]};";
                    break;
                case 0xA1: // if_icmplt
                    $offset = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $target = $start + $offset;
                    $stmts[] = "        \$b = \$stack[--\$sp]; \$a = \$stack[--\$sp]; if (\$a < \$b) goto {$labels[$target]};";
                    break;
                case 0xA7: // goto
                    $offset = $this->readSignedShort($bytes, $pc); $pc += 2;
                    $target = $start + $offset;
                    $stmts[] = "        goto {$labels[$target]};";
                    break;
                case 0xAC: // ireturn
                    $stmts[] = "        return \$stack[--\$sp];";
                    break;
                case 0xB1: // return (void)
                    $stmts[] = "        return;";
                    break;
                default:
                    $stmts[] = "        // UNIMPLEMENTED OPCODE 0x" . strtoupper(dechex($op));
                    $advance = $this->opcodeLength($op);
                    $pc += $advance;
                    break;
            }
        }

        $isStatic = true; // simplification for spike; real compiler reads access flags
        $signature = $isStatic ? "public static function {$this->mangleMethod($name)}()" : "public function {$this->mangleMethod($name)}(\$this)";

        $body = implode("\n", $stmts);
        return "    // {$name} {$descriptor}\n    {$signature}\n    {\n{$body}\n    }";
    }

    private function utf8At(int $idx)
    {
        $entry = $this->constantPool[$idx] ?? null;
        if ($entry instanceof Utf8Info) return $entry->getString();
        return '?';
    }

    private function readSignedByte(array $bytes, int $pc): int
    {
        $v = $bytes[$pc];
        return ($v & 0x80) ? $v - 0x100 : $v;
    }

    private function readSignedShort(array $bytes, int $pc): int
    {
        $v = ($bytes[$pc] << 8) | $bytes[$pc + 1];
        return ($v & 0x8000) ? $v - 0x10000 : $v;
    }

    /**
     * Opcode operand-byte count (excluding the opcode itself). Returns
     * 0 for opcodes whose body is just the opcode byte. For variable-
     * length opcodes (tableswitch, lookupswitch, wide), this is a
     * placeholder — the real compiler handles them specially.
     */
    private function opcodeLength(int $op): int
    {
        return match ($op) {
            // 1 byte operand
            0x10, 0x12, 0x15, 0x16, 0x17, 0x18, 0x19, 0x36, 0x37, 0x38, 0x39, 0x3A, 0xA9, 0xBC => 1,
            // 2 byte operands
            0x11, 0x13, 0x14, 0x84, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5, 0xA6, 0xA7, 0xA8, 0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xBB, 0xBD, 0xC0, 0xC1, 0xC6, 0xC7 => 2,
            // 4 byte
            0xB9, 0xBA, 0xC5, 0xC8, 0xC9 => 4,
            default => 0,
        };
    }

    private function mangle(string $classPath): string
    {
        return str_replace(['.', '/', '\\', '$'], '_', $classPath);
    }

    private function mangleMethod(string $name): string
    {
        // <init> / <clinit> can't be PHP method names; rename
        if ($name === '<init>') return '__construct';
        if ($name === '<clinit>') return '__staticConstruct';
        return $name;
    }
}
