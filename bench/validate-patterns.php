<?php
// Validate the dispatch / array / boxing patterns claimed in the
// 2026-05-01 design conversation. Pure PHP, runs standalone.
//
// Tests:
//   1. Dispatch shapes:
//      - virtual method (current PHPJava `_iadd::execute`)
//      - switch ($op)
//      - array of closures (use-by-reference)
//      - array of first-class callables (PHP 8.1+)
//      - array of static method refs ([Class::class, 'method'])
//   2. Array operations:
//      - isset+?? vs array_key_exists vs in_array for hot lookups
//      - $arr[$i] vs $arr[$i] ?? null
//   3. Boxing cost:
//      - PHP scalar arithmetic vs wrapped (Int_-style) arithmetic
//   4. Coll-shape vs raw array iteration cost

const ITERS = 5_000_000;

function bench(string $label, callable $body): void {
    $body(); // warm
    $t0 = hrtime(true);
    for ($i = 0; $i < ITERS; $i++) $body();
    $ns = (hrtime(true) - $t0);
    printf("  %-50s %12.2f ns/op\n", $label, $ns / ITERS);
}

echo "PHP " . PHP_VERSION . " ";
echo extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli')
    ? "(opcache " . (ini_get('opcache.jit') ? "+ JIT={$z}" : "no JIT") . ")"
    : "(no opcache)";
echo "\n\n";

// =============================================================================
// 1. Dispatch shapes
// =============================================================================

echo "1. DISPATCH SHAPES (calling a no-op operation per iter)\n";

// 1a — Virtual method (current PHPJava style)
abstract class AbstractOp { abstract public function exec(int $a, int $b): int; }
final class IaddVirtual extends AbstractOp { public function exec(int $a, int $b): int { return $a + $b; } }

$virtual = new IaddVirtual();
bench('1a virtual method  $obj->exec(1,2)', fn() => $virtual->exec(1, 2));

// 1b — switch ($op)
$switchOp = function (int $op, int $a, int $b): int {
    switch ($op) {
        case 0x60: return $a + $b;
        case 0x64: return $a - $b;
        case 0x68: return $a * $b;
        default: return 0;
    }
};
bench('1b switch dispatch  switchOp(0x60,1,2)', fn() => $switchOp(0x60, 1, 2));

// 1c — Array of closures (use-by-reference)
$state = ['count' => 0];
$closureTable = [
    0x60 => function (int $a, int $b) use (&$state): int { $state['count']++; return $a + $b; },
    0x64 => function (int $a, int $b) use (&$state): int { return $a - $b; },
    0x68 => function (int $a, int $b) use (&$state): int { return $a * $b; },
];
bench('1c closure-with-use  $T[0x60](1,2)', fn() => $closureTable[0x60](1, 2));

// 1d — Array of first-class callable syntax (PHP 8.1+)
final class IaddStatic {
    public static function add(int $a, int $b): int { return $a + $b; }
    public static function sub(int $a, int $b): int { return $a - $b; }
    public static function mul(int $a, int $b): int { return $a * $b; }
}
$fcsTable = [
    0x60 => IaddStatic::add(...),
    0x64 => IaddStatic::sub(...),
    0x68 => IaddStatic::mul(...),
];
bench('1d first-class callable  $T[0x60](1,2)', fn() => $fcsTable[0x60](1, 2));

// 1e — Array of static method refs as [Class::class, 'method']
$refTable = [
    0x60 => [IaddStatic::class, 'add'],
    0x64 => [IaddStatic::class, 'sub'],
    0x68 => [IaddStatic::class, 'mul'],
];
bench('1e static method ref  $T[0x60](1,2)', fn() => ($refTable[0x60])(1, 2));

// 1f — Direct function call (baseline)
function direct_add(int $a, int $b): int { return $a + $b; }
bench('1f direct function  direct_add(1,2)', fn() => direct_add(1, 2));

// 1g — Inline (best case — no dispatch at all)
bench('1g inline           $a + $b', function () {
    static $a = 1, $b = 2; return $a + $b;
});

echo "\n";

// =============================================================================
// 2. Array operations
// =============================================================================

echo "2. ARRAY OPERATIONS (key lookup patterns)\n";

$haystack = array_combine(range(0, 99), array_fill(0, 100, 'val'));

bench('2a $arr[$k] (direct, key exists)', function () use (&$haystack) { return $haystack[42]; });
bench('2b $arr[$k] ?? null (direct + coalesce)', function () use (&$haystack) { return $haystack[42] ?? null; });
bench('2c isset($arr[$k]) ? $arr[$k] : null', function () use (&$haystack) { return isset($haystack[42]) ? $haystack[42] : null; });
bench('2d array_key_exists($k, $arr) check', function () use (&$haystack) { return array_key_exists(42, $haystack); });
bench('2e in_array($v, $arr) — needs full scan', function () use (&$haystack) { return in_array('val', $haystack, true); });

echo "\n";

// =============================================================================
// 3. Boxing cost (Int_ wrapper vs raw scalar)
// =============================================================================

echo "3. BOXING COST (the H6/H7 cost in current PHPJava)\n";

// Wrapper class mimicking PHPJava's Int_ wrapper structure
final class Int_ {
    public function __construct(public int $value) {}
    public static function get(int $v): Int_ { return new Int_($v); }
}

bench('3a raw scalar      $a + $b', fn() => 1 + 2);

bench('3b boxed via new   (new Int_(1))->value + (new Int_(2))->value', function () {
    $a = new Int_(1);
    $b = new Int_(2);
    return $a->value + $b->value;
});

bench('3c boxed via ::get Int_::get(1)->value + Int_::get(2)->value', function () {
    return Int_::get(1)->value + Int_::get(2)->value;
});

echo "\n";

// =============================================================================
// 4. Coll wrapper vs raw array
// =============================================================================

echo "4. COLL-SHAPE vs RAW ARRAY (mesh's Coll pattern)\n";

final class Coll implements \Countable, \ArrayAccess, \IteratorAggregate {
    public function __construct(public array $a, public string $t) {}
    public function count(): int { return \count($this->a); }
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->a); }
    public function offsetExists(mixed $k): bool { return isset($this->a[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->a[$k] ?? null; }
    public function offsetSet(mixed $k, mixed $v): void { $this->a[$k] = $v; }
    public function offsetUnset(mixed $k): void { unset($this->a[$k]); }
}

$rawArr = ['a' => 1, 'b' => 2, 'c' => 3];
$coll = new Coll($rawArr, 'M');

bench('4a raw array       $arr["b"]', fn() => $rawArr['b']);
bench('4b Coll wrapper    $coll["b"] (ArrayAccess)', fn() => $coll['b']);
bench('4c Coll direct     $coll->a["b"]', fn() => $coll->a['b']);
bench('4d count(raw)', fn() => count($rawArr));
bench('4e count(Coll) — calls method', fn() => count($coll));

echo "\n";

// =============================================================================
// 5. Per-class strategy constant-folding (HotSpot tier-up analogue)
// =============================================================================

echo "5. STRATEGY DISPATCH (constant-folding per call site)\n";

// Strategy A: re-check enum every call (won't constant-fold)
enum Strategy { case A; case B; case C; }

$pickedStrategy = Strategy::B;

$variantDispatch = function (Strategy $s, int $x) {
    return match ($s) {
        Strategy::A => $x + 1,
        Strategy::B => $x * 2,
        Strategy::C => $x - 1,
    };
};
bench('5a runtime enum match (variant changes)', function () use ($variantDispatch, $pickedStrategy) {
    return $variantDispatch($pickedStrategy, 42);
});

// Strategy B: closure resolved once, called many (constant per call site)
$resolvedStrategy = match ($pickedStrategy) {
    Strategy::A => fn(int $x) => $x + 1,
    Strategy::B => fn(int $x) => $x * 2,
    Strategy::C => fn(int $x) => $x - 1,
};
bench('5b resolved closure (variant fixed)', fn() => $resolvedStrategy(42));

echo "\nDONE.\n";
