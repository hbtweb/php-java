<?php
declare(strict_types=1);
namespace PHPJava\Aot\Ir\Flat;

// Flat-array IR — alternative to the OOP IR for compile-speed-critical
// scenarios. Each node is a tagged array `[kind, ...fields]` instead
// of an object. Construction is array-literal speed (no class lookup,
// no constructor call, no readonly-property setup); dispatch is a
// match on integer kind tag (PHP 8 match → hashed jump table).
//
// Trade-off vs OOP IR: faster construction, no type safety. For
// compile-at-runtime use cases (defineClass(byte[]), hot recompile),
// this matters. Build-time AOT can use either.

// ── Kind tags (small integers — fastest match dispatch) ─────────
const KIND_INT_LIT     = 0;
const KIND_FLOAT_LIT   = 1;
const KIND_STRING_LIT  = 2;
const KIND_NULL_LIT    = 3;
const KIND_LOCAL_READ  = 4;
const KIND_PARAM_READ  = 5;
const KIND_BINOP       = 6;
const KIND_UNARYOP     = 7;
const KIND_STATIC_CALL = 8;
const KIND_INSTANCE_CALL = 9;
const KIND_NEW         = 10;
const KIND_FIELD_READ  = 11;
const KIND_STATIC_FIELD_READ = 12;
const KIND_CAUGHT      = 13;
// Stmts
const KIND_STORE_LOCAL = 100;
const KIND_IINC        = 101;
const KIND_EXPR_STMT   = 102;
const KIND_STORE_STATIC_FIELD = 103;
const KIND_STORE_FIELD = 104;
// Terminators
const KIND_GOTO        = 200;
const KIND_COND_GOTO   = 201;
const KIND_RETURN      = 202;
const KIND_THROW       = 203;

// Factory functions — use these instead of `new IntLit(...)` etc.
// Inlined into hot Builder paths, the function-call overhead is
// fast (~10-30 ns/call).
function intLit(int $v): array { return [KIND_INT_LIT, $v]; }
function floatLit(float $v): array { return [KIND_FLOAT_LIT, $v]; }
function stringLit(string $v): array { return [KIND_STRING_LIT, $v]; }
function nullLit(): array { return [KIND_NULL_LIT]; }
function localRead(int $slot): array { return [KIND_LOCAL_READ, $slot]; }
function binOp(string $op, array $l, array $r): array { return [KIND_BINOP, $op, $l, $r]; }
function unaryOp(string $op, array $x): array { return [KIND_UNARYOP, $op, $x]; }
function staticCall(string $cls, string $m, array $args): array { return [KIND_STATIC_CALL, $cls, $m, $args]; }
function newObj(string $cls, array $args): array { return [KIND_NEW, $cls, $args]; }
function caughtException(): array { return [KIND_CAUGHT]; }
function storeLocal(int $slot, array $value): array { return [KIND_STORE_LOCAL, $slot, $value]; }
function iincLocal(int $slot, int $delta): array { return [KIND_IINC, $slot, $delta]; }
function gotoTerm(int $target): array { return [KIND_GOTO, $target]; }
function condGotoTerm(array $cond, int $thenPc, int $elsePc): array { return [KIND_COND_GOTO, $cond, $thenPc, $elsePc]; }
function returnTerm(?array $value): array { return [KIND_RETURN, $value]; }

/** Lower a flat-array IR module to PHP source. */
final class FlatLowerer
{
    public function lower(array $module): string
    {
        $methods = [];
        foreach ($module['methods'] as $method) {
            $methods[] = $this->lowerMethod($method);
        }
        $body = implode("\n\n", $methods);
        return "<?php\nnamespace {$module['namespace']};\n\nfinal class {$module['className']}\n{\n{$body}\n}\n";
    }

    public function lowerMethod(array $method): string
    {
        $params = $method['params'];
        $maxLocals = $method['maxLocals'];
        $blocks = $method['blocks'];
        ksort($blocks);
        $orderedPcs = array_keys($blocks);

        // Live label set
        $liveLabels = [];
        foreach ($blocks as $bb) {
            $term = $bb['term'] ?? null;
            if ($term !== null) {
                if ($term[0] === KIND_GOTO) $liveLabels[$term[1]] = true;
                elseif ($term[0] === KIND_COND_GOTO) $liveLabels[$term[2]] = true;
            }
        }

        $initVals = [];
        for ($i = 0; $i < $maxLocals; $i++) {
            $initVals[] = $i < count($params) ? $params[$i] : '0';
        }
        $localList = $maxLocals > 0 ? '[' . implode(', ', $initVals) . ']' : '[]';

        $out = "    public static function {$method['name']}(" . implode(', ', $params) . ")\n    {\n";
        $out .= "\$L = {$localList};\n";
        foreach ($orderedPcs as $i => $pc) {
            $bb = $blocks[$pc];
            if (isset($liveLabels[$pc])) {
                $out .= "L_{$pc}:\n";
            }
            foreach ($bb['stmts'] as $s) {
                $out .= '        ' . $this->stmt($s) . "\n";
            }
            $term = $bb['term'] ?? null;
            if ($term !== null) {
                $nextPc = $orderedPcs[$i + 1] ?? null;
                if ($term[0] === KIND_GOTO && $term[1] === $nextPc) continue;
                $out .= '        ' . $this->term($term) . "\n";
            }
        }
        $out .= "    }";
        return $out;
    }

    public function stmt(array $s): string
    {
        return match ($s[0]) {
            KIND_STORE_LOCAL => "\$L[{$s[1]}] = " . $this->expr($s[2]) . ";",
            KIND_IINC => "\$L[{$s[1]}] += {$s[2]};",
            KIND_EXPR_STMT => $this->expr($s[1]) . ";",
        };
    }

    public function term(array $t): string
    {
        return match ($t[0]) {
            KIND_GOTO => "goto L_{$t[1]};",
            KIND_COND_GOTO => "if (" . $this->expr($t[1]) . ") goto L_{$t[2]};",
            KIND_RETURN => $t[1] === null ? "return;" : "return " . $this->expr($t[1]) . ";",
            KIND_THROW => "throw " . $this->expr($t[1]) . ";",
        };
    }

    public function expr(array $e): string
    {
        return match ($e[0]) {
            KIND_INT_LIT => (string)$e[1],
            KIND_FLOAT_LIT => is_finite($e[1]) ? (string)$e[1] : 'NAN',
            KIND_STRING_LIT => var_export($e[1], true),
            KIND_NULL_LIT => 'null',
            KIND_LOCAL_READ => "\$L[{$e[1]}]",
            KIND_BINOP => $this->expr($e[2]) . " {$e[1]} " . $this->expr($e[3]),
            KIND_UNARYOP => "{$e[1]}" . $this->expr($e[2]),
            KIND_STATIC_CALL => $e[2] === ''
                ? "{$e[1]}(" . implode(', ', array_map([$this, 'expr'], $e[3])) . ")"
                : "{$e[1]}::{$e[2]}(" . implode(', ', array_map([$this, 'expr'], $e[3])) . ")",
            KIND_NEW => "new {$e[1]}(" . implode(', ', array_map([$this, 'expr'], $e[2])) . ")",
            KIND_CAUGHT => '$__e',
        };
    }
}
