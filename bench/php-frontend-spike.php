<?php
declare(strict_types=1);

/**
 * Spike: PHP-source-to-IR lifter for PHPJava shims.
 *
 * Walking-skeleton frontend demonstrating the architecture in this
 * conversation: any input becomes IR, the compiler resolves and
 * optimises uniformly, no shim/user split. Substrate inspired by
 * ~/GitHub/hbtcomputers.com.au/mesh/src/php/mesh/php/expr.php — a
 * table-driven Pratt parser on top of PHP's native token_get_all().
 *
 * Scope: enough to lift the existing Math::abs body to a single
 * Ternary-tree expression that the InlinePass would substitute at
 * every call site. Production scale-up (full PHP subset, integration
 * with the AOT compile pipeline, mtime cache) is the 2-week build
 * the spike validates.
 *
 * Run from repo root:
 *   php bench/php-frontend-spike.php
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

// ─── IR (spike-local — once validated, fold into src/Aot/Ir/Node.php) ──────

const N_LIT       = 'lit';        // ['lit', value] — int|float|string|bool|null
const N_VAR       = 'var';        // ['var', name]
const N_CONST     = 'const';      // ['const', name] — PHP_INT_MIN, NAN, etc.
const N_STATIC    = 'static';     // ['static', class, name] — Foo::BAR
const N_BINOP     = 'binop';      // ['binop', op, left, right]
const N_UNARYOP   = 'unaryop';    // ['unaryop', op, operand]
const N_TERNARY   = 'ternary';    // ['ternary', cond, then, else]
const N_PHPFNCALL = 'phpfn';      // ['phpfn', name, [args...], pure?]
const N_REFUSED   = 'refused';    // ['refused', reason] — frontend can't lift

// ─── Pratt operator tables ─────────────────────────────────────────────────

// Left-binding power per operator. Higher = tighter. Loose subset from PHP.
const BP_LOWEST = 0;
const BP_TERNARY = 4;
const BP_LOGICAL_OR = 6;
const BP_LOGICAL_AND = 7;
const BP_BITOR = 8; const BP_BITXOR = 9; const BP_BITAND = 10;
const BP_EQUALITY = 11;     // ==, ===, !=, !==
const BP_COMPARISON = 12;   // <, <=, >, >=, <=>
const BP_SHIFT = 13;
const BP_ADDITIVE = 14;     // +, -, .
const BP_MUL = 15;          // *, /, %
const BP_UNARY = 17;        // -, !, ~
const BP_CALL = 20;         // ()

const PURE_PHP_FNS = [
    // Whitelist: known-pure PHP builtins safe to inline. Production
    // scale-up extends this with annotations on shim files.
    'is_int' => true, 'is_string' => true, 'is_float' => true,
    'is_bool' => true, 'is_null' => true, 'is_nan' => true,
    'is_finite' => true, 'is_infinite' => true,
    'abs' => true, 'sqrt' => true, 'floor' => true, 'ceil' => true,
    'min' => true, 'max' => true, 'intdiv' => true, 'fmod' => true,
    'fdiv' => true, 'strtolower' => true, 'strtoupper' => true,
];

// ─── Parser state ──────────────────────────────────────────────────────────

final class PhpFrontend
{
    /** @var array<int, array|string> tokens from token_get_all */
    private array $toks;
    private int $end;

    /** Parsed result keyed by methodName → ['params' => [string], 'body' => Expr|null]. */
    public array $methods = [];

    public function __construct(string $src)
    {
        $this->toks = \array_values(\token_get_all($src));
        $this->end  = \count($this->toks);
        $this->parseFile();
    }

    // ─── Statement / class / method ────────────────────────────────────────

    private function parseFile(): void
    {
        $p = 0;
        while ($p < $this->end) {
            $t = $this->toks[$p];
            if (\is_array($t) && $t[0] === \T_CLASS) {
                $p = $this->parseClassBody($p);
                continue;
            }
            $p++;
        }
    }

    private function parseClassBody(int $p): int
    {
        // Skip to opening brace.
        while ($p < $this->end && $this->toks[$p] !== '{') $p++;
        if ($p >= $this->end) return $p;
        $p++;  // past '{'

        $depth = 1;
        while ($p < $this->end && $depth > 0) {
            $t = $this->toks[$p];
            if ($t === '{') { $depth++; $p++; continue; }
            if ($t === '}') { $depth--; $p++; continue; }
            // Look for `public static function NAME` (skip non-static, ctors).
            if (\is_array($t) && $t[0] === \T_FUNCTION) {
                $p = $this->parseMethod($p);
                continue;
            }
            $p++;
        }
        return $p;
    }

    private function parseMethod(int $p): int
    {
        // function NAME(params) { body }
        $p++;  // past 'function'
        $p = $this->skipWs($p);
        $name = '';
        if ($p < $this->end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_STRING) {
            $name = $this->toks[$p][1];
            $p++;
        }
        $p = $this->skipWs($p);
        // Param list: '(' VAR (, VAR)* ')'
        $params = [];
        if ($p < $this->end && $this->toks[$p] === '(') {
            $p++;
            while ($p < $this->end && $this->toks[$p] !== ')') {
                $t = $this->toks[$p];
                if (\is_array($t) && $t[0] === \T_VARIABLE) {
                    $params[] = \substr($t[1], 1);  // strip leading $
                }
                $p++;
            }
            $p++;  // past ')'
        }
        // Skip return type annotation, attributes — anything before '{'.
        while ($p < $this->end && $this->toks[$p] !== '{' && $this->toks[$p] !== ';') $p++;
        if ($p >= $this->end || $this->toks[$p] === ';') return $p + 1;  // abstract
        $p++;  // past '{'

        // Find matching '}'
        $bodyStart = $p;
        $depth = 1;
        while ($p < $this->end && $depth > 0) {
            $t = $this->toks[$p];
            if ($t === '{') $depth++;
            elseif ($t === '}') $depth--;
            if ($depth === 0) break;
            $p++;
        }
        $bodyEnd = $p;

        $body = $this->liftBlock($bodyStart, $bodyEnd);
        $this->methods[$name] = ['params' => $params, 'body' => $body];

        return $p + 1;  // past '}'
    }

    // ─── Block lifter — control-flow → ternary tree ───────────────────────

    /**
     * Lift a sequence of statements between [start, end) to a single Expr.
     *
     * Pattern matched:
     *   if (cond) { return X; } <REST>     →  ternary(cond, X, <REST>)
     *   if (cond) return X;     <REST>     →  ternary(cond, X, <REST>)
     *   if (cond) { B1 } else { B2 }       →  ternary(cond, lift(B1), lift(B2))
     *   return X;                          →  X
     *   { B }                              →  lift(B)
     *
     * Anything else (loops, side-effecting stmts, multiple non-tail-return
     * stmts, throws, switches with non-trivial body) → ['refused', reason].
     * Production frontend extends this with stack-of-stmts plus phi lowering.
     */
    private function liftBlock(int $start, int $end): array
    {
        $p = $this->skipWs($start, $end);
        if ($p >= $end) return [N_REFUSED, 'empty block'];

        $t = $this->toks[$p];
        // `if (cond) ...`
        if (\is_array($t) && $t[0] === \T_IF) {
            return $this->liftIf($p, $end);
        }
        // `return E;`
        if (\is_array($t) && $t[0] === \T_RETURN) {
            $p = $this->skipWs($p + 1, $end);
            if ($p < $end && $this->toks[$p] === ';') return [N_LIT, null];
            [$expr, $np] = $this->parseExpr($p, $end, BP_LOWEST);
            $np = $this->skipWs($np, $end);
            if ($np < $end && $this->toks[$np] !== ';' && $this->toks[$np] !== '}') {
                return [N_REFUSED, 'unexpected token after return expr'];
            }
            return $expr;
        }
        // `throw E;`
        if (\is_array($t) && $t[0] === \T_THROW) {
            return [N_REFUSED, 'throw not yet liftable (needs Throw IR node)'];
        }
        return [N_REFUSED, 'unsupported statement: ' . (\is_array($t) ? \token_name($t[0]) : $t)];
    }

    private function liftIf(int $p, int $end): array
    {
        // if (cond) THEN [else ELSE] [REST]
        $p++;  // past 'if'
        $p = $this->skipWs($p, $end);
        if ($p >= $end || $this->toks[$p] !== '(') return [N_REFUSED, 'missing ('];
        // Find matching ')'
        $cp = $this->matchParen($p, $end, '(', ')');
        [$cond, $_] = $this->parseExpr($p + 1, $cp, BP_LOWEST);
        $p = $cp + 1;  // past ')'

        // THEN
        $p = $this->skipWs($p, $end);
        [$thenExpr, $afterThen] = $this->liftBranch($p, $end);

        // optional `else`
        $afterElse = $afterThen;
        $afterElse = $this->skipWs($afterElse, $end);
        if ($afterElse < $end && \is_array($this->toks[$afterElse]) && $this->toks[$afterElse][0] === \T_ELSE) {
            $afterElse++;
            $afterElse = $this->skipWs($afterElse, $end);
            [$elseExpr, $afterElse] = $this->liftBranch($afterElse, $end);
            return [N_TERNARY, $cond, $thenExpr, $elseExpr];
        }

        // No else — REST is the fall-through. Pattern:
        //   if (cond) { return X; } REST  →  ternary(cond, X, REST)
        // Only valid if THEN ends with a return (otherwise we'd need
        // dataflow merge logic). The lifter signals via thenExpr being
        // a value-bearing expr; if THEN didn't return, refuse.
        if ($thenExpr[0] === N_REFUSED) return $thenExpr;
        $rest = $this->liftBlock($afterThen, $end);
        if ($rest[0] === N_REFUSED) return $rest;
        return [N_TERNARY, $cond, $thenExpr, $rest];
    }

    /**
     * A branch is either { stmts } or a single statement. Returns
     * [liftedExpr, posAfterBranch].
     */
    private function liftBranch(int $p, int $end): array
    {
        if ($p >= $end) return [[N_REFUSED, 'eof in branch'], $p];
        if ($this->toks[$p] === '{') {
            $cp = $this->matchParen($p, $end, '{', '}');
            $expr = $this->liftBlock($p + 1, $cp);
            return [$expr, $cp + 1];
        }
        // Single-statement form: `if (c) return X;`
        $expr = $this->liftBlock($p, $end);
        // Skip the consumed statement — find the trailing ';'
        $sp = $p;
        while ($sp < $end && $this->toks[$sp] !== ';') $sp++;
        return [$expr, $sp + 1];
    }

    // ─── Pratt expression parser ──────────────────────────────────────────

    private function parseExpr(int $p, int $end, int $rbp): array
    {
        $p = $this->skipWs($p, $end);
        [$left, $p] = $this->parseNud($p, $end);
        while ($p < $end) {
            $p = $this->skipWs($p, $end);
            if ($p >= $end) break;
            $bp = $this->ledBp($this->toks[$p]);
            if ($bp <= $rbp) break;
            [$left, $p] = $this->parseLed($left, $p, $end);
        }
        return [$left, $p];
    }

    private function parseNud(int $p, int $end): array
    {
        $t = $this->toks[$p];

        // Prefix unary
        if ($t === '-' || $t === '!' || $t === '+' || $t === '~') {
            [$operand, $np] = $this->parseExpr($p + 1, $end, BP_UNARY);
            return [[N_UNARYOP, $t, $operand], $np];
        }

        if (\is_array($t)) {
            switch ($t[0]) {
                case \T_LNUMBER:                   return [[N_LIT, (int) $t[1]], $p + 1];
                case \T_DNUMBER:                   return [[N_LIT, (float) $t[1]], $p + 1];
                case \T_CONSTANT_ENCAPSED_STRING:  return [[N_LIT, \stripslashes(\substr($t[1], 1, -1))], $p + 1];
                case \T_VARIABLE:                  return [[N_VAR, \substr($t[1], 1)], $p + 1];
                case \T_NS_SEPARATOR:              // `\foo(...)` or `\PHP_INT_MIN`
                    return $this->parseNudName($p + 1, $end, true);
                case \T_STRING:
                case \T_NAME_QUALIFIED:
                case \T_NAME_FULLY_QUALIFIED:
                    return $this->parseNudName($p, $end, false);
            }
        }

        if ($t === '(') {
            [$inner, $np] = $this->parseExpr($p + 1, $end, BP_LOWEST);
            $np = $this->skipWs($np, $end);
            if ($np < $end && $this->toks[$np] === ')') $np++;
            return [$inner, $np];
        }

        return [[N_REFUSED, 'unexpected nud: ' . (\is_array($t) ? \token_name($t[0]) : $t)], $p + 1];
    }

    /** Identifier in nud position — `name`, `name(args)`, `Foo::BAR`, `\Foo::method(...)`, `true`/`false`/`null`. */
    private function parseNudName(int $p, int $end, bool $rooted): array
    {
        $name = '';
        while ($p < $end) {
            $t = $this->toks[$p];
            if ($t === '\\') { $name .= '\\'; $p++; continue; }
            if (\is_array($t) && \in_array($t[0], [\T_STRING, \T_NS_SEPARATOR, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $t[1];
                $p++;
                continue;
            }
            break;
        }
        // Class const? `Foo::BAR`
        $p = $this->skipWs($p, $end);
        if ($p < $end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_DOUBLE_COLON) {
            $p++;
            $p = $this->skipWs($p, $end);
            $cm = '';
            if ($p < $end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_STRING) {
                $cm = $this->toks[$p][1];
                $p++;
            }
            // Static method call?  Foo::method(args)
            $sp = $this->skipWs($p, $end);
            if ($sp < $end && $this->toks[$sp] === '(') {
                [$args, $ap] = $this->parseArgList($sp + 1, $end);
                return [[N_PHPFNCALL, $this->normaliseName($name) . '::' . $cm, $args, false], $ap];
            }
            return [[N_STATIC, $this->normaliseName($name), $cm], $p];
        }
        // Function call? name(args)
        $sp = $this->skipWs($p, $end);
        if ($sp < $end && $this->toks[$sp] === '(') {
            [$args, $ap] = $this->parseArgList($sp + 1, $end);
            $bare = \ltrim($name, '\\');
            $pure = isset(PURE_PHP_FNS[$bare]);
            return [[N_PHPFNCALL, '\\' . $bare, $args, $pure], $ap];
        }
        // Bare identifier — true/false/null literal or named constant.
        $lower = \strtolower(\ltrim($name, '\\'));
        if ($lower === 'true')  return [[N_LIT, true],  $p];
        if ($lower === 'false') return [[N_LIT, false], $p];
        if ($lower === 'null')  return [[N_LIT, null],  $p];
        return [[N_CONST, $this->normaliseName($name)], $p];
    }

    private function normaliseName(string $name): string
    {
        // Add leading backslash if missing — internal IR is FQN.
        return $name[0] === '\\' ? $name : '\\' . \ltrim($name, '\\');
    }

    private function parseArgList(int $p, int $end): array
    {
        $args = [];
        $p = $this->skipWs($p, $end);
        if ($p < $end && $this->toks[$p] === ')') return [$args, $p + 1];
        while ($p < $end) {
            [$arg, $p] = $this->parseExpr($p, $end, BP_LOWEST);
            $args[] = $arg;
            $p = $this->skipWs($p, $end);
            if ($p >= $end) break;
            if ($this->toks[$p] === ',') { $p++; continue; }
            if ($this->toks[$p] === ')') { $p++; break; }
            break;
        }
        return [$args, $p];
    }

    private function ledBp(mixed $t): int
    {
        if (\is_string($t)) {
            return match ($t) {
                '+', '-', '.' => BP_ADDITIVE,
                '*', '/', '%' => BP_MUL,
                '<', '>'      => BP_COMPARISON,
                '|'           => BP_BITOR,
                '^'           => BP_BITXOR,
                '&'           => BP_BITAND,
                '?'           => BP_TERNARY,
                default       => 0,
            };
        }
        if (\is_array($t)) {
            return match ($t[0]) {
                \T_BOOLEAN_AND, \T_LOGICAL_AND => BP_LOGICAL_AND,
                \T_BOOLEAN_OR,  \T_LOGICAL_OR  => BP_LOGICAL_OR,
                \T_IS_IDENTICAL, \T_IS_NOT_IDENTICAL,
                \T_IS_EQUAL, \T_IS_NOT_EQUAL    => BP_EQUALITY,
                \T_IS_SMALLER_OR_EQUAL,
                \T_IS_GREATER_OR_EQUAL, \T_SPACESHIP => BP_COMPARISON,
                \T_SL, \T_SR                    => BP_SHIFT,
                default                          => 0,
            };
        }
        return 0;
    }

    private function parseLed(array $left, int $p, int $end): array
    {
        $t = $this->toks[$p];
        $bp = $this->ledBp($t);

        // Ternary: cond ? then : else
        if ($t === '?') {
            [$thenE, $np] = $this->parseExpr($p + 1, $end, BP_LOWEST);
            $np = $this->skipWs($np, $end);
            if ($np < $end && $this->toks[$np] === ':') $np++;
            [$elseE, $np] = $this->parseExpr($np, $end, BP_TERNARY - 1);
            return [[N_TERNARY, $left, $thenE, $elseE], $np];
        }

        // Binary
        $op = \is_string($t) ? $t : match ($t[0]) {
            \T_BOOLEAN_AND => '&&', \T_BOOLEAN_OR => '||',
            \T_IS_IDENTICAL => '===', \T_IS_NOT_IDENTICAL => '!==',
            \T_IS_EQUAL => '==', \T_IS_NOT_EQUAL => '!=',
            \T_IS_SMALLER_OR_EQUAL => '<=', \T_IS_GREATER_OR_EQUAL => '>=',
            \T_SPACESHIP => '<=>',
            \T_SL => '<<', \T_SR => '>>',
            \T_LOGICAL_AND => 'and', \T_LOGICAL_OR => 'or',
            default => '?',
        };
        [$right, $np] = $this->parseExpr($p + 1, $end, $bp);
        return [[N_BINOP, $op, $left, $right], $np];
    }

    // ─── token helpers ────────────────────────────────────────────────────

    private function skipWs(int $p, ?int $end = null): int
    {
        $end ??= $this->end;
        while ($p < $end) {
            $t = $this->toks[$p];
            if (\is_array($t) && \in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $p++;
                continue;
            }
            break;
        }
        return $p;
    }

    private function matchParen(int $p, int $end, string $open, string $close): int
    {
        $depth = 0;
        while ($p < $end) {
            $t = $this->toks[$p];
            if ($t === $open) $depth++;
            elseif ($t === $close) {
                $depth--;
                if ($depth === 0) return $p;
            }
            $p++;
        }
        return $end;
    }
}

// ─── IR emitter — substitute params, render PHP ────────────────────────────

function emit(array $expr, array $argMap): string
{
    return match ($expr[0]) {
        N_LIT     => emitLit($expr[1]),
        N_VAR     => $argMap[$expr[1]] ?? ('$' . $expr[1]),
        N_CONST   => $expr[1],
        N_STATIC  => $expr[1] . '::' . $expr[2],
        N_BINOP   => '(' . emit($expr[2], $argMap) . ' ' . $expr[1] . ' ' . emit($expr[3], $argMap) . ')',
        N_UNARYOP => '(' . $expr[1] . emit($expr[2], $argMap) . ')',
        N_TERNARY => '(' . emit($expr[1], $argMap) . ' ? ' . emit($expr[2], $argMap) . ' : ' . emit($expr[3], $argMap) . ')',
        N_PHPFNCALL => $expr[1] . '(' . \implode(', ', \array_map(fn ($a) => emit($a, $argMap), $expr[2])) . ')',
        N_REFUSED => '/* REFUSED: ' . $expr[1] . ' */ null',
    };
}

function emitLit(mixed $v): string
{
    if ($v === null)        return 'null';
    if ($v === true)        return 'true';
    if ($v === false)       return 'false';
    if (\is_int($v))        return (string) $v;
    if (\is_float($v))      {
        if (\is_nan($v))    return 'NAN';
        if ($v === \INF)    return 'INF';
        if ($v === -\INF)   return '-INF';
        return \var_export($v, true);
    }
    if (\is_string($v))     return \var_export($v, true);
    return 'null';
}

// ─── Drive the spike ───────────────────────────────────────────────────────

$src = \file_get_contents(__DIR__ . '/../src/Aot/Runtime/java/lang/Math.php');
$front = new PhpFrontend($src);

echo "Lifted methods from Math.php:\n";
foreach ($front->methods as $name => $info) {
    $body = $info['body'];
    $status = ($body[0] === N_REFUSED) ? "REFUSED ({$body[1]})" : 'lifted';
    \printf("  %-20s  params=[%s]  %s\n", $name, \implode(',', $info['params']), $status);
}

echo "\n== Lifted Math::abs body (substituted with \$x) ==\n";
$abs = $front->methods['abs'];
$inlined = emit($abs['body'], ['a' => '$x']);
echo "  $inlined\n";

// ─── Validate semantics: spot-check ──
echo "\n== Semantic spot-check (lifted vs shim) ==\n";
$shim = ['\PHPJava\Aot\Runtime\java\lang\Math', 'abs'];
$test = function ($x) use ($abs, $shim) {
    $code = 'return ' . emit($abs['body'], ['a' => '$x']) . ';';
    $lifted = (function ($x) use ($code) { return eval($code); })($x);
    $shimResult = $shim($x);
    $match = ($lifted === $shimResult)
        || (\is_float($lifted) && \is_nan($lifted) && \is_float($shimResult) && \is_nan($shimResult));
    \printf("  abs(%-22s)  lifted=%-12s shim=%-12s  %s\n",
        \var_export($x, true),
        \var_export($lifted, true),
        \var_export($shimResult, true),
        $match ? 'MATCH' : 'DIVERGE');
};
$test(-42);
$test(0);
$test(\PHP_INT_MIN);
$test(-42.5);
$test(\NAN);
$test(\INF);

// ─── Bench: lifted-inlined vs shim dispatch ──
echo "\n== Bench (1M iters x 7 reps, median ns/op) ==\n";
$N = 1_000_000;
$REPS = 7;
$bench = function (string $label, callable $body) use ($N, $REPS) {
    $body();
    $samples = [];
    for ($r = 0; $r < $REPS; $r++) {
        $t = \hrtime(true);
        $body();
        $samples[] = (\hrtime(true) - $t) / $N;
    }
    \sort($samples);
    \printf("  %-50s  %7.2f ns/op\n", $label, $samples[(int) (\count($samples) / 2)]);
};

$bench('shim dispatch (status quo)', function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \PHPJava\Aot\Runtime\java\lang\Math::abs($i - 500_000);
});

$inlinedFn = eval('return function ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) {
        $a = $i - 500_000;
        $sum += ' . emit($abs['body'], ['a' => '$a']) . ';
    }
};');
$bench('lifted via PhpFrontend (eval-emit)', fn () => $inlinedFn($N));
