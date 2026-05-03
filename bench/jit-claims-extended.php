<?php
// Extended JIT-claims battery — HotSpot/C2/Graal optimisations not
// covered by `jit-claims.php`. Same harness: A (depends on opt)
// vs B (manually optimised baseline). A/B ratio < 1.20 = applied;
// 1.20–2.0 = partial; > 2.0 = not applied.

const N = 500_000;
const WARMUP = 5_000;

function timeit_x(string $label, callable $body): array {
    for ($i = 0; $i < WARMUP; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $body();
    $ns = (microtime(true) - $start) * 1e9;
    return ['label' => $label, 'ns' => $ns / N];
}

// ── Test 1: loop unrolling ─────────────────────────────────────────
// HotSpot C2 unrolls tight loops by 4 or 8.
function unroll_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}
function unroll_b(int $n): int {
    $s = 0; $i = 0;
    for (; $i + 3 < $n; $i += 4) {
        $s += $i; $s += $i + 1; $s += $i + 2; $s += $i + 3;
    }
    for (; $i < $n; $i++) $s += $i;
    return $s;
}

// ── Test 2: strength reduction (mul by const power-of-2) ──────────
// HotSpot rewrites `i * 4` to `i << 2`.
function strength_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i * 4;
    return $s;
}
function strength_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i << 2;
    return $s;
}

// ── Test 3: constant folding through branch ───────────────────────
// `if (KNOWN_TRUE)` should fold to the then-branch entirely.
const ALWAYS_TRUE = true;
function constfold_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        if (ALWAYS_TRUE) $s += $i; else $s -= $i;
    }
    return $s;
}
function constfold_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}

// ── Test 4: null check elimination ────────────────────────────────
// HotSpot elides redundant null checks on the same reference.
final class Holder { public int $x = 0; public ?Holder $next = null; }
function null_a(?Holder $h): int {
    if ($h === null) return 0;
    if ($h === null) return 0; // redundant — should be elided
    if ($h === null) return 0; // redundant
    return $h->x;
}
function null_b(?Holder $h): int {
    if ($h === null) return 0;
    return $h->x;
}

// ── Test 5: devirtualisation (single-impl class) ──────────────────
// HotSpot CHA: when only one subclass exists, virtual calls become
// direct. PHP doesn't have CHA, but `final` classes signal monomorphism
// — does the JIT exploit it?
abstract class Shape { abstract public function area(): int; }
final class Square extends Shape {
    public function __construct(private int $side) {}
    public function area(): int { return $this->side * $this->side; }
}
function devirt_a(Shape $s): int { return $s->area(); }
function devirt_b(Square $s): int { return $s->area(); }

// ── Test 6: allocation/object folding (local Box) ─────────────────
// HotSpot scalar replacement: a local-only object with primitive fields
// is replaced by stack-allocated scalars.
final class Box2 { public int $a = 0; public int $b = 0; }
function alloc_a(int $x, int $y): int {
    $b = new Box2();
    $b->a = $x; $b->b = $y;
    return $b->a + $b->b;
}
function alloc_b(int $x, int $y): int {
    $a = $x; $b = $y;
    return $a + $b;
}

// ── Test 7: branch prediction (consistent direction) ──────────────
// HotSpot profile-guided: a branch always taken should have minimal
// cost. PHP JIT profiles too.
function branch_a(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($i >= 0) $s += $i; else $s -= $i; // always then
    }
    return $s;
}
function branch_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}

// ── Test 8: chained property load ─────────────────────────────────
// $a->b->c->d — chains of object-field deref. HotSpot inlines /
// hoists; PHP does ?
final class Linked { public ?Linked $next = null; public int $v = 0; }
$chain = new Linked();
$chain->v = 1;
$chain->next = new Linked();
$chain->next->v = 2;
$chain->next->next = new Linked();
$chain->next->next->v = 3;
function chain_a(Linked $l): int {
    return $l->next->next->v;
}
$leaf = $chain->next->next;
function chain_b(Linked $leaf): int {
    return $leaf->v;
}

// ── Test 9: constant propagation across simple ops ────────────────
function constprop_a(): int {
    $a = 7; $b = 13; $c = $a + $b; return $c * 2;
}
function constprop_b(): int { return 40; }

// ── Test 10: tail-recursion (no TCO in HotSpot, but worth measuring) ─
function tail_a(int $n, int $acc): int {
    if ($n === 0) return $acc;
    return tail_a($n - 1, $acc + $n);
}
function tail_b(int $n): int {
    $acc = 0;
    for ($i = $n; $i > 0; $i--) $acc += $i;
    return $acc;
}

// ── Test 11: range check elimination ───────────────────────────────
// HotSpot hoists array-bounds checks out of monotonic loops.
function range_a(array $a, int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $a[$i];
    return $s;
}
function range_b(array $a, int $n): int {
    $s = 0;
    foreach ($a as $v) $s += $v;
    return $s;
}

// ── Test 12: float math vs int math ────────────────────────────────
// HotSpot specialises float ops. PHP int vs float same speed?
function flop_a(int $n): float {
    $s = 0.0;
    for ($i = 0; $i < $n; $i++) $s += (float)$i * 0.5;
    return $s;
}
function flop_b(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; $i++) $s += $i;
    return $s;
}

$arr = range(0, 99);

$tests = [
    ['unroll',     fn() => unroll_a(100),         fn() => unroll_b(100)],
    ['strength',   fn() => strength_a(100),       fn() => strength_b(100)],
    ['constfold-br', fn() => constfold_a(100),    fn() => constfold_b(100)],
    ['null-check', fn() => null_a(new Holder()),  fn() => null_b(new Holder())],
    ['devirt',     fn() => devirt_a(new Square(5)), fn() => devirt_b(new Square(5))],
    ['alloc-fold', fn() => alloc_a(7, 13),        fn() => alloc_b(7, 13)],
    ['branch-pred',fn() => branch_a(100),         fn() => branch_b(100)],
    ['ptr-chain',  fn() => chain_a($chain),       fn() => chain_b($leaf)],
    ['const-prop', fn() => constprop_a(),         fn() => constprop_b()],
    ['tail-rec',   fn() => tail_a(50, 0),         fn() => tail_b(50)],
    ['range-elim', fn() => range_a($arr, 100),    fn() => range_b($arr, 100)],
    ['flop-vs-iop',fn() => flop_a(100),           fn() => flop_b(100)],
];

printf("PHP %s, opcache=%s, JIT=%s\n",
    PHP_VERSION,
    ini_get('opcache.enable_cli') ? '1' : '0',
    ini_get('opcache.jit') ?: 'off');
echo str_repeat('-', 80), "\n";
printf("%-16s %12s %12s %10s   verdict\n", 'test', 'A (ns)', 'B (ns)', 'A/B');
echo str_repeat('-', 80), "\n";

foreach ($tests as [$label, $a, $b]) {
    $ra = timeit_x($label . '-A', $a);
    $rb = timeit_x($label . '-B', $b);
    $ratio = $rb['ns'] > 0 ? $ra['ns'] / $rb['ns'] : INF;
    $verdict = match (true) {
        $ratio < 1.20 => 'OPT-APPLIED',
        $ratio < 2.00 => 'partial',
        default       => 'NOT-APPLIED',
    };
    printf("%-16s %10.2f ns %10.2f ns   %5.2fx   %s\n",
        $label, $ra['ns'], $rb['ns'], $ratio, $verdict);
}
