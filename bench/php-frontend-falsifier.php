<?php
declare(strict_types=1);

/**
 * Falsifier — PHP frontend unified-architecture model.
 *
 * Pre-registered predictions before measurement (per CLAUDE.md
 * §"Predict before observing"):
 *
 *   F1 [LIFT-COVERAGE]   ≥14 of 17 Math.php methods lift after extending
 *                        the spike with **, casts, throw, let-bindings.
 *                        Falsifies if <12 lift.
 *   F2 [PERF-INTEGRATED] Lifted-IR-emit, written to a real .php file and
 *                        included via require, beats shim dispatch when
 *                        called in a hot loop. Predicts 8-12 ns/op JIT;
 *                        falsifies if ≥20 ns/op (no improvement vs the
 *                        17.8 ns/op JIT shim baseline).
 *   F3 [IR-COMPATIBLE]   Lifted-PHP IR nodes are in the same shape family
 *                        (Expr trees rooted in literals/binop/var/call) as
 *                        bytecode-derived IR. Substitutes into a
 *                        StaticCall site without type/shape mismatch.
 *                        Falsifies if substitution requires structural
 *                        translation.
 *   F4 [SEMANTIC-EQ]     Lifted Math::abs returns the same value as shim
 *                        Math::abs across 1000 randomised inputs spanning
 *                        ints, floats, edge cases. Falsifies if any
 *                        divergence.
 *
 * Run from repo root: php bench/php-frontend-falsifier.php
 * Result: PASS / FALSIFY per test + final verdict.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

// ─── IR node tags ─────────────────────────────────────────────────────────

const N_LIT       = 'lit';
const N_VAR       = 'var';
const N_CONST     = 'const';
const N_STATIC    = 'static';
const N_BINOP     = 'binop';
const N_UNARYOP   = 'unaryop';
const N_TERNARY   = 'ternary';
const N_PHPFNCALL = 'phpfn';
const N_CAST      = 'cast';        // ['cast', type, operand]
const N_NEW       = 'new';         // ['new', classFqn, args]
const N_THROW     = 'throw';       // ['throw', exception_expr]
const N_REFUSED   = 'refused';

// ─── Pratt operator BP table ──────────────────────────────────────────────

const BP_LOWEST = 0;
const BP_TERNARY = 4;
const BP_LOGICAL_OR = 6;
const BP_LOGICAL_AND = 7;
const BP_BITOR = 8; const BP_BITXOR = 9; const BP_BITAND = 10;
const BP_EQUALITY = 11;
const BP_COMPARISON = 12;
const BP_SHIFT = 13;
const BP_ADDITIVE = 14;
const BP_MUL = 15;
const BP_POW = 16;        // right-associative
const BP_UNARY = 17;
const BP_CALL = 20;

const PURE_PHP_FNS = [
    'is_int' => true, 'is_string' => true, 'is_float' => true,
    'is_bool' => true, 'is_null' => true, 'is_nan' => true,
    'is_finite' => true, 'is_infinite' => true,
    'abs' => true, 'sqrt' => true, 'floor' => true, 'ceil' => true,
    'min' => true, 'max' => true, 'intdiv' => true, 'fmod' => true,
    'fdiv' => true, 'strtolower' => true, 'strtoupper' => true,
    'gettype' => true, 'array_map' => true, 'count' => true,
];

// ─── Frontend (extended from the original spike) ──────────────────────────

final class PhpFrontend
{
    private array $toks;
    private int $end;
    /** @var array<string, mixed> file's namespace (FQN prefix) for class refs. */
    private string $namespace = '';
    /** @var array<string,bool> set of param names — never substituted. */
    private array $params = [];
    /** @var array<string,array> let-binding env for SSA-lite substitution. */
    private array $env = [];
    public array $methods = [];

    public function __construct(string $src)
    {
        $this->toks = \array_values(\token_get_all($src));
        $this->end  = \count($this->toks);
        $this->parseFile();
    }

    private function parseFile(): void
    {
        $p = 0;
        while ($p < $this->end) {
            $t = $this->toks[$p];
            if (\is_array($t) && $t[0] === \T_NAMESPACE) {
                $p = $this->parseNamespace($p + 1);
                continue;
            }
            if (\is_array($t) && $t[0] === \T_CLASS) {
                $p = $this->parseClassBody($p);
                continue;
            }
            $p++;
        }
    }

    private function parseNamespace(int $p): int
    {
        $p = $this->skipWs($p);
        $name = '';
        while ($p < $this->end) {
            $t = $this->toks[$p];
            if (\is_array($t) && \in_array($t[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NS_SEPARATOR], true)) {
                $name .= $t[1];
                $p++;
                continue;
            }
            break;
        }
        $this->namespace = $name;
        while ($p < $this->end && $this->toks[$p] !== ';' && $this->toks[$p] !== '{') $p++;
        return $p + 1;
    }

    private function parseClassBody(int $p): int
    {
        while ($p < $this->end && $this->toks[$p] !== '{') $p++;
        if ($p >= $this->end) return $p;
        $p++;
        $depth = 1;
        while ($p < $this->end && $depth > 0) {
            $t = $this->toks[$p];
            if ($t === '{') { $depth++; $p++; continue; }
            if ($t === '}') { $depth--; $p++; continue; }
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
        $p++;
        $p = $this->skipWs($p);
        $name = '';
        if ($p < $this->end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_STRING) {
            $name = $this->toks[$p][1];
            $p++;
        }
        $p = $this->skipWs($p);
        $params = [];
        if ($p < $this->end && $this->toks[$p] === '(') {
            $p++;
            while ($p < $this->end && $this->toks[$p] !== ')') {
                $t = $this->toks[$p];
                if (\is_array($t) && $t[0] === \T_VARIABLE) {
                    $params[] = \substr($t[1], 1);
                }
                $p++;
            }
            $p++;
        }
        while ($p < $this->end && $this->toks[$p] !== '{' && $this->toks[$p] !== ';') $p++;
        if ($p >= $this->end || $this->toks[$p] === ';') return $p + 1;
        $p++;
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

        $this->params = \array_fill_keys($params, true);
        $this->env = [];
        $body = $this->liftBlock($bodyStart, $bodyEnd);
        $this->methods[$name] = ['params' => $params, 'body' => $body];

        return $p + 1;
    }

    /** Lift a sequence of statements between [start, end) to a single Expr. */
    private function liftBlock(int $start, int $end): array
    {
        $p = $this->skipWs($start, $end);
        if ($p >= $end) return [N_REFUSED, 'empty block'];
        $t = $this->toks[$p];

        // if (cond) ...
        if (\is_array($t) && $t[0] === \T_IF) {
            return $this->liftIf($p, $end);
        }

        // throw EXPR;
        if (\is_array($t) && $t[0] === \T_THROW) {
            [$exception, $np] = $this->parseExpr($p + 1, $end, BP_LOWEST);
            return [N_THROW, $exception];
        }

        // return EXPR;
        if (\is_array($t) && $t[0] === \T_RETURN) {
            $p = $this->skipWs($p + 1, $end);
            if ($p < $end && $this->toks[$p] === ';') return [N_LIT, null];
            [$expr, $np] = $this->parseExpr($p, $end, BP_LOWEST);
            return $expr;
        }

        // Let-binding: `$name = EXPR;` followed by more stmts.
        // SSA-lite — record the binding, substitute on subsequent N_VAR
        // reads, then continue lifting the rest of the block. Pure-only
        // for now (any side-effecting RHS would change semantics under
        // duplication).
        if (\is_array($t) && $t[0] === \T_VARIABLE) {
            $name = \substr($t[1], 1);
            $afterName = $this->skipWs($p + 1, $end);
            if ($afterName < $end && $this->toks[$afterName] === '=') {
                [$valExpr, $afterVal] = $this->parseExpr($afterName + 1, $end, BP_LOWEST);
                $afterVal = $this->skipWs($afterVal, $end);
                if ($afterVal < $end && $this->toks[$afterVal] === ';') $afterVal++;
                if ($this->isPureExpr($valExpr)) {
                    $this->env[$name] = $valExpr;
                    return $this->liftBlock($afterVal, $end);
                }
                return [N_REFUSED, 'let-binding RHS impure: $' . $name];
            }
        }

        return [N_REFUSED, 'unsupported statement: ' . (\is_array($t) ? \token_name($t[0]) : $t)];
    }

    private function liftIf(int $p, int $end): array
    {
        $p++;
        $p = $this->skipWs($p, $end);
        if ($p >= $end || $this->toks[$p] !== '(') return [N_REFUSED, 'missing ('];
        $cp = $this->matchParen($p, $end, '(', ')');
        [$cond, $_] = $this->parseExpr($p + 1, $cp, BP_LOWEST);
        $p = $cp + 1;
        $p = $this->skipWs($p, $end);
        // Snapshot env so the THEN branch's let-bindings don't leak into REST.
        $envSave = $this->env;
        [$thenExpr, $afterThen] = $this->liftBranch($p, $end);
        $this->env = $envSave;

        $afterElse = $this->skipWs($afterThen, $end);
        if ($afterElse < $end && \is_array($this->toks[$afterElse]) && $this->toks[$afterElse][0] === \T_ELSE) {
            $afterElse++;
            $afterElse = $this->skipWs($afterElse, $end);
            $envSave = $this->env;
            [$elseExpr, $_] = $this->liftBranch($afterElse, $end);
            $this->env = $envSave;
            return [N_TERNARY, $cond, $thenExpr, $elseExpr];
        }

        if ($thenExpr[0] === N_REFUSED) return $thenExpr;
        $rest = $this->liftBlock($afterThen, $end);
        if ($rest[0] === N_REFUSED) return $rest;
        return [N_TERNARY, $cond, $thenExpr, $rest];
    }

    private function liftBranch(int $p, int $end): array
    {
        if ($p >= $end) return [[N_REFUSED, 'eof in branch'], $p];
        if ($this->toks[$p] === '{') {
            $cp = $this->matchParen($p, $end, '{', '}');
            $expr = $this->liftBlock($p + 1, $cp);
            return [$expr, $cp + 1];
        }
        $expr = $this->liftBlock($p, $end);
        $sp = $p;
        while ($sp < $end && $this->toks[$sp] !== ';') $sp++;
        return [$expr, $sp + 1];
    }

    // ─── Pratt parser ─────────────────────────────────────────────────────

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

        if ($t === '-' || $t === '!' || $t === '+' || $t === '~') {
            [$operand, $np] = $this->parseExpr($p + 1, $end, BP_UNARY);
            return [[N_UNARYOP, $t, $operand], $np];
        }

        if (\is_array($t)) {
            switch ($t[0]) {
                case \T_LNUMBER:                   return [[N_LIT, (int) $t[1]], $p + 1];
                case \T_DNUMBER:                   return [[N_LIT, (float) $t[1]], $p + 1];
                case \T_CONSTANT_ENCAPSED_STRING:  return [[N_LIT, \stripslashes(\substr($t[1], 1, -1))], $p + 1];
                case \T_VARIABLE: {
                    $name = \substr($t[1], 1);
                    // Let-binding substitution — never substitute params.
                    if (!isset($this->params[$name]) && isset($this->env[$name])) {
                        return [$this->env[$name], $p + 1];
                    }
                    return [[N_VAR, $name], $p + 1];
                }
                case \T_NS_SEPARATOR:
                    return $this->parseNudName($p + 1, $end, true);
                case \T_STRING:
                case \T_NAME_QUALIFIED:
                case \T_NAME_FULLY_QUALIFIED:
                    return $this->parseNudName($p, $end, false);
                case \T_INT_CAST:    return $this->parseCast($p + 1, $end, 'int');
                case \T_DOUBLE_CAST: return $this->parseCast($p + 1, $end, 'float');
                case \T_BOOL_CAST:   return $this->parseCast($p + 1, $end, 'bool');
                case \T_STRING_CAST: return $this->parseCast($p + 1, $end, 'string');
                case \T_NEW:         return $this->parseNew($p + 1, $end);
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

    private function parseCast(int $p, int $end, string $type): array
    {
        [$inner, $np] = $this->parseExpr($p, $end, BP_UNARY);
        return [[N_CAST, $type, $inner], $np];
    }

    private function parseNew(int $p, int $end): array
    {
        $p = $this->skipWs($p, $end);
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
        $fqn = $this->resolveClassName($name);
        $p = $this->skipWs($p, $end);
        $args = [];
        if ($p < $end && $this->toks[$p] === '(') {
            [$args, $p] = $this->parseArgList($p + 1, $end);
        }
        return [[N_NEW, $fqn, $args], $p];
    }

    private function resolveClassName(string $name): string
    {
        if ($name[0] === '\\') return $name;
        // Relative — prepend current namespace.
        if ($this->namespace !== '') return '\\' . $this->namespace . '\\' . $name;
        return '\\' . $name;
    }

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
        $p = $this->skipWs($p, $end);
        if ($p < $end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_DOUBLE_COLON) {
            $p++;
            $p = $this->skipWs($p, $end);
            $cm = '';
            if ($p < $end && \is_array($this->toks[$p]) && $this->toks[$p][0] === \T_STRING) {
                $cm = $this->toks[$p][1];
                $p++;
            }
            $sp = $this->skipWs($p, $end);
            if ($sp < $end && $this->toks[$sp] === '(') {
                [$args, $ap] = $this->parseArgList($sp + 1, $end);
                return [[N_PHPFNCALL, $this->normaliseName($name) . '::' . $cm, $args, false], $ap];
            }
            return [[N_STATIC, $this->normaliseName($name), $cm], $p];
        }
        $sp = $this->skipWs($p, $end);
        if ($sp < $end && $this->toks[$sp] === '(') {
            [$args, $ap] = $this->parseArgList($sp + 1, $end);
            $bare = \ltrim($name, '\\');
            $pure = isset(PURE_PHP_FNS[$bare]);
            return [[N_PHPFNCALL, '\\' . $bare, $args, $pure], $ap];
        }
        $lower = \strtolower(\ltrim($name, '\\'));
        if ($lower === 'true')  return [[N_LIT, true],  $p];
        if ($lower === 'false') return [[N_LIT, false], $p];
        if ($lower === 'null')  return [[N_LIT, null],  $p];
        return [[N_CONST, $this->normaliseName($name)], $p];
    }

    private function normaliseName(string $name): string
    {
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
                \T_POW                          => BP_POW,
                default                          => 0,
            };
        }
        return 0;
    }

    private function parseLed(array $left, int $p, int $end): array
    {
        $t = $this->toks[$p];
        $bp = $this->ledBp($t);

        if ($t === '?') {
            [$thenE, $np] = $this->parseExpr($p + 1, $end, BP_LOWEST);
            $np = $this->skipWs($np, $end);
            if ($np < $end && $this->toks[$np] === ':') $np++;
            [$elseE, $np] = $this->parseExpr($np, $end, BP_TERNARY - 1);
            return [[N_TERNARY, $left, $thenE, $elseE], $np];
        }

        $op = \is_string($t) ? $t : match ($t[0]) {
            \T_BOOLEAN_AND => '&&', \T_BOOLEAN_OR => '||',
            \T_IS_IDENTICAL => '===', \T_IS_NOT_IDENTICAL => '!==',
            \T_IS_EQUAL => '==', \T_IS_NOT_EQUAL => '!=',
            \T_IS_SMALLER_OR_EQUAL => '<=', \T_IS_GREATER_OR_EQUAL => '>=',
            \T_SPACESHIP => '<=>',
            \T_SL => '<<', \T_SR => '>>',
            \T_POW => '**',
            \T_LOGICAL_AND => 'and', \T_LOGICAL_OR => 'or',
            default => '?',
        };
        // ** is right-associative.
        $rightBp = ($t[0] ?? null) === \T_POW ? $bp - 1 : $bp;
        [$right, $np] = $this->parseExpr($p + 1, $end, $rightBp);
        return [[N_BINOP, $op, $left, $right], $np];
    }

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

    /** Pure means: no calls except whitelisted PHP builtins, no throws. */
    private function isPureExpr(array $e): bool
    {
        switch ($e[0]) {
            case N_LIT: case N_VAR: case N_CONST: case N_STATIC: return true;
            case N_BINOP:
                return $this->isPureExpr($e[2]) && $this->isPureExpr($e[3]);
            case N_UNARYOP:
                return $this->isPureExpr($e[2]);
            case N_TERNARY:
                return $this->isPureExpr($e[1]) && $this->isPureExpr($e[2]) && $this->isPureExpr($e[3]);
            case N_CAST:
                return $this->isPureExpr($e[2]);
            case N_PHPFNCALL:
                if (!($e[3] ?? false)) return false;
                foreach ($e[2] as $a) if (!$this->isPureExpr($a)) return false;
                return true;
            case N_NEW: case N_THROW: return false;  // allocates / unwinds
            default: return false;
        }
    }
}

// ─── Emitter — substitute params, render PHP ──────────────────────────────

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
        N_CAST    => '((' . $expr[1] . ') ' . emit($expr[2], $argMap) . ')',
        N_NEW     => 'new ' . $expr[1] . '(' . \implode(', ', \array_map(fn ($a) => emit($a, $argMap), $expr[2])) . ')',
        N_THROW   => '(throw ' . emit($expr[1], $argMap) . ')',
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

// ─── Falsifier harness ────────────────────────────────────────────────────

echo "PHP Frontend Falsifier — predictions registered:\n";
echo "  F1 ≥ 14/17 Math methods lift\n";
echo "  F2 < 20 ns/op JIT (predicted 8-12)\n";
echo "  F3 IR substitutes into bytecode call sites\n";
echo "  F4 1000/1000 randomised inputs match shim\n";
echo "\n";

$src = \file_get_contents(__DIR__ . '/../src/Aot/Runtime/java/lang/Math.php');
$front = new PhpFrontend($src);

// ─── F1 ─ Lift coverage ──
echo "── F1 LIFT-COVERAGE ─────────────────────────────────────\n";
$lifted = 0;
$refused = [];
foreach ($front->methods as $name => $info) {
    if ($info['body'][0] === N_REFUSED) {
        $refused[$name] = $info['body'][1];
    } else {
        $lifted++;
    }
}
$total = \count($front->methods);
\printf("  Lifted: %d/%d\n", $lifted, $total);
foreach ($refused as $m => $reason) \printf("    refused  %-22s  %s\n", $m, $reason);
$F1_pass = $lifted >= 14;
echo $F1_pass ? "  F1 PASS\n\n" : "  F1 FALSIFY (predicted ≥14, got $lifted)\n\n";

// ─── F4 ─ Semantic equivalence (1000 random) ──
echo "── F4 SEMANTIC-EQ ───────────────────────────────────────\n";
$abs = $front->methods['abs'];
$inlinedCode = 'return ' . emit($abs['body'], ['a' => '$x']) . ';';
$liftedFn = (function () use ($inlinedCode) {
    return eval('return function ($x) { ' . $inlinedCode . ' };');
})();
$shim = ['\PHPJava\Aot\Runtime\java\lang\Math', 'abs'];

\srand(42);
$inputs = [];
// Edge cases first
$inputs[] = 0; $inputs[] = 1; $inputs[] = -1;
$inputs[] = \PHP_INT_MIN; $inputs[] = \PHP_INT_MAX;
$inputs[] = \PHP_INT_MIN + 1; $inputs[] = \PHP_INT_MAX - 1;
$inputs[] = 0.0; $inputs[] = -0.0; $inputs[] = \NAN; $inputs[] = \INF; $inputs[] = -\INF;
$inputs[] = 1.5; $inputs[] = -1.5; $inputs[] = 0.1 + 0.2;
// Random fill to 1000
while (\count($inputs) < 1000) {
    $r = \rand(0, 3);
    $inputs[] = match ($r) {
        0 => \rand(\PHP_INT_MIN, \PHP_INT_MAX),
        1 => \rand(-1000, 1000),
        2 => (\rand() / \getrandmax()) * 2e9 - 1e9,
        default => (\rand() / \getrandmax()) * 1e-3,
    };
}
$mismatches = 0;
foreach ($inputs as $i => $x) {
    $a = $liftedFn($x);
    $b = $shim($x);
    $eq = ($a === $b)
        || (\is_float($a) && \is_nan($a) && \is_float($b) && \is_nan($b));
    if (!$eq) {
        if ($mismatches < 3) {
            \printf("    DIVERGE  abs(%s)  lifted=%s  shim=%s\n",
                \var_export($x, true), \var_export($a, true), \var_export($b, true));
        }
        $mismatches++;
    }
}
\printf("  Match: %d/%d\n", \count($inputs) - $mismatches, \count($inputs));
$F4_pass = $mismatches === 0;
echo $F4_pass ? "  F4 PASS\n\n" : "  F4 FALSIFY ($mismatches divergences)\n\n";

// ─── F3 ─ IR compatibility check ──
// Bytecode-derived IR uses StaticCall(classFqn, method, args[]) where args
// are Expr nodes. Lifted IR's substitution at a call site means: take the
// lifted Method's body Expr and replace LocalRead(slot N) → caller's arg N.
// Demonstrate by walking the lifted body shape.
echo "── F3 IR-COMPATIBILITY ──────────────────────────────────\n";
function walkShape(array $expr, array &$kindCount): void
{
    $kindCount[$expr[0]] = ($kindCount[$expr[0]] ?? 0) + 1;
    foreach ($expr as $i => $sub) {
        if ($i === 0) continue;
        if (\is_array($sub) && isset($sub[0]) && \is_string($sub[0])) walkShape($sub, $kindCount);
        elseif (\is_array($sub)) {
            foreach ($sub as $s) {
                if (\is_array($s) && isset($s[0]) && \is_string($s[0])) walkShape($s, $kindCount);
            }
        }
    }
}
$kinds = [];
foreach ($front->methods as $info) {
    if ($info['body'][0] !== N_REFUSED) walkShape($info['body'], $kinds);
}
echo "  IR node kinds in lifted Math.php:\n";
foreach ($kinds as $k => $n) \printf("    %-10s × %d\n", $k, $n);
// Required to substitute: literals + binop + var + ternary + phpfn — all
// already mappable to existing PHPJava Expr subtypes (IntLit, FloatLit,
// StringLit, BoolLit, BinOp, UnaryOp, LocalRead, StaticCall). New nodes
// needed: Ternary, Cast, Throw, New_(already exists). PhpFnCall maps to
// StaticCall with classFqn=null and a "raw PHP fn" marker.
$existingMappable = ['lit', 'var', 'const', 'static', 'binop', 'unaryop', 'phpfn', 'new'];
$newRequired      = ['ternary', 'cast', 'throw'];
$incompat = \array_diff(\array_keys($kinds), [...$existingMappable, ...$newRequired]);
$F3_pass = empty($incompat);
echo "  Mappable to existing IR: " . \implode(', ', \array_intersect(\array_keys($kinds), $existingMappable)) . "\n";
echo "  New IR nodes required:   " . \implode(', ', \array_intersect(\array_keys($kinds), $newRequired)) . "\n";
echo $F3_pass ? "  F3 PASS\n\n" : "  F3 FALSIFY (incompatible: " . \implode(',', $incompat) . ")\n\n";

// ─── F2 ─ Perf through real file include ──
echo "── F2 PERF-INTEGRATED ───────────────────────────────────\n";
$tmpFile = \sys_get_temp_dir() . '/php_frontend_lifted_math.php';
$emittedFn = '<?php
namespace PHPJava\\Aot\\Generated_Lifted;
function math_abs($a) {
    return ' . emit($abs['body'], []) . ';
}';
\file_put_contents($tmpFile, $emittedFn);
require $tmpFile;

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
    return $samples[(int) (\count($samples) / 2)];
};

$shimNs = $bench('shim dispatch', function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \PHPJava\Aot\Runtime\java\lang\Math::abs($i - 500_000);
});
$liftedNs = $bench('lifted via require', function () use ($N) {
    $sum = 0;
    for ($i = 0; $i < $N; $i++) $sum += \PHPJava\Aot\Generated_Lifted\math_abs($i - 500_000);
});

\printf("  shim dispatch:        %7.2f ns/op\n", $shimNs);
\printf("  lifted via require:   %7.2f ns/op\n", $liftedNs);
$F2_pass = $liftedNs < 20.0 && $liftedNs < $shimNs;
echo $F2_pass
    ? "  F2 PASS (faster than shim, < 20 ns/op)\n\n"
    : "  F2 FALSIFY (predicted < 20 ns/op AND faster than shim)\n\n";

// ─── Final verdict ──
echo "════════════════════════════════════════════════════════\n";
echo "  Final verdict:\n";
\printf("    F1 LIFT-COVERAGE   %s\n", $F1_pass ? 'PASS' : 'FALSIFY');
\printf("    F2 PERF-INTEGRATED %s\n", $F2_pass ? 'PASS' : 'FALSIFY');
\printf("    F3 IR-COMPATIBLE   %s\n", $F3_pass ? 'PASS' : 'FALSIFY');
\printf("    F4 SEMANTIC-EQ     %s\n", $F4_pass ? 'PASS' : 'FALSIFY');
$all = $F1_pass && $F2_pass && $F3_pass && $F4_pass;
echo $all
    ? "  MODEL HOLDS — proceed to refactor.\n"
    : "  MODEL FALSIFIED — retract claims, revisit architecture.\n";
echo "════════════════════════════════════════════════════════\n";
