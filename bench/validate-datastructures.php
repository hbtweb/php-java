<?php
// What does each native PHP data structure reward?
// Six tight-loop variants, same workload (push, push, +, store).

const ITERS = 1_000_000;

function bench(string $label, callable $body): void {
    $body();  // warm
    $t0 = hrtime(true);
    for ($i = 0; $i < ITERS; $i++) $body();
    $ns = (hrtime(true) - $t0) / ITERS;
    printf("  %-50s %8.2f ns/op\n", $label, $ns);
}

echo "PHP " . PHP_VERSION . " ";
echo (extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli'))
    ? "(opcache; jit=" . (ini_get('opcache.jit') ?: 'off') . ")"
    : "(no opcache)";
echo "\n\n";

// =============================================================================
// 1. Operand stack representations
// =============================================================================

echo "1. OPERAND STACK (4 push/pop ops per iteration)\n";

bench('1a regular array  $stack[$sp++] / $stack[--$sp]', function () {
    $stack = []; $sp = 0;
    $stack[$sp++] = 1;
    $stack[$sp++] = 2;
    $b = $stack[--$sp]; $stack[$sp - 1] += $b;
    return $stack[--$sp];
});

bench('1b SplFixedArray  $stack[$sp++]', function () {
    static $stack = null;
    if ($stack === null) $stack = new SplFixedArray(16);
    $sp = 0;
    $stack[$sp++] = 1;
    $stack[$sp++] = 2;
    $b = $stack[--$sp]; $stack[$sp - 1] = $stack[$sp - 1] + $b;
    return $stack[--$sp];
});

bench('1c locals as PHP vars (no stack array)', function () {
    $a = 1; $b = 2;
    $c = $a + $b;
    return $c;
});

bench('1d preallocated array fill', function () {
    $stack = array_fill(0, 16, 0);
    $sp = 0;
    $stack[$sp++] = 1;
    $stack[$sp++] = 2;
    $b = $stack[--$sp]; $stack[$sp - 1] += $b;
    return $stack[--$sp];
});

echo "\n";

// =============================================================================
// 2. Bytecode reading: string vs array of ints
// =============================================================================

echo "2. BYTECODE READ (5 bytes consumed per iter)\n";

$bytecode = "\x03\x3B\x10\x05\x60";  // raw bytes
$ints = array_values(unpack('C*', $bytecode));

bench('2a string + ord($s[$pc++])', function () use ($bytecode) {
    $pc = 0;
    $a = ord($bytecode[$pc++]);
    $b = ord($bytecode[$pc++]);
    $c = ord($bytecode[$pc++]);
    $d = ord($bytecode[$pc++]);
    $e = ord($bytecode[$pc++]);
    return $a + $b + $c + $d + $e;
});

bench('2b int array $ints[$pc++]', function () use ($ints) {
    $pc = 0;
    $a = $ints[$pc++];
    $b = $ints[$pc++];
    $c = $ints[$pc++];
    $d = $ints[$pc++];
    $e = $ints[$pc++];
    return $a + $b + $c + $d + $e;
});

bench('2c unpack on demand', function () use ($bytecode) {
    [$a, $b, $c, $d, $e] = array_values(unpack('C5', $bytecode));
    return $a + $b + $c + $d + $e;
});

echo "\n";

// =============================================================================
// 3. Constant pool / lookup table access
// =============================================================================

echo "3. LOOKUP TABLES (10 lookups per iter)\n";

$cp = array_combine(range(0, 99), array_map(fn($i) => "value_{$i}", range(0, 99)));
$cpFixed = SplFixedArray::fromArray(array_values($cp));

bench('3a regular array  $cp[$idx]', function () use (&$cp) {
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += strlen($cp[$i * 7]);
    return $sum;
});

bench('3b SplFixedArray  $cpFixed[$idx]', function () use (&$cpFixed) {
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += strlen($cpFixed[$i * 7]);
    return $sum;
});

bench('3c WeakMap (object keys)', function () {
    static $wm = null;
    if ($wm === null) {
        $wm = new WeakMap();
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $k = (object)['i' => $i];
            $keys[] = $k;
            $wm[$k] = "value_{$i}";
        }
        // Stash keys to keep them alive
        $GLOBALS['__wm_keys'] = $keys;
    }
    $keys = $GLOBALS['__wm_keys'];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += strlen($wm[$keys[$i * 7]]);
    return $sum;
});

echo "\n";

// =============================================================================
// 4. Object property access vs array
// =============================================================================

echo "4. OBJECT PROPERTY vs ARRAY (read + write 4 fields)\n";

class FrameObj {
    public array $stack = [];
    public int $sp = 0;
    public array $L = [];
    public int $pc = 0;
}

bench('4a array $f["sp"]++', function () {
    $f = ['stack' => [], 'sp' => 0, 'L' => [], 'pc' => 0];
    $f['stack'][$f['sp']++] = 1;
    $f['stack'][$f['sp']++] = 2;
    $f['pc'] += 1;
    $f['L'][0] = $f['stack'][--$f['sp']];
    return $f['pc'];
});

bench('4b stdClass $f->sp++', function () {
    $f = new stdClass();
    $f->stack = []; $f->sp = 0; $f->L = []; $f->pc = 0;
    $f->stack[$f->sp++] = 1;
    $f->stack[$f->sp++] = 2;
    $f->pc += 1;
    $f->L[0] = $f->stack[--$f->sp];
    return $f->pc;
});

bench('4c typed FrameObj $f->sp++', function () {
    $f = new FrameObj();
    $f->stack[$f->sp++] = 1;
    $f->stack[$f->sp++] = 2;
    $f->pc += 1;
    $f->L[0] = $f->stack[--$f->sp];
    return $f->pc;
});

bench('4d locals only (no frame at all)', function () {
    $stack = []; $sp = 0; $L = []; $pc = 0;
    $stack[$sp++] = 1;
    $stack[$sp++] = 2;
    $pc += 1;
    $L[0] = $stack[--$sp];
    return $pc;
});

echo "\n";

// =============================================================================
// 5. Iteration patterns
// =============================================================================

echo "5. ITERATION over 100 elements (sum)\n";

$arr = range(0, 99);
$fixed = SplFixedArray::fromArray($arr);

bench('5a foreach ($arr as $v)', function () use (&$arr) {
    $s = 0; foreach ($arr as $v) $s += $v; return $s;
});

bench('5b for + index ($arr[$i])', function () use (&$arr) {
    $s = 0; $n = count($arr);
    for ($i = 0; $i < $n; $i++) $s += $arr[$i];
    return $s;
});

bench('5c array_sum', function () use (&$arr) {
    return array_sum($arr);
});

bench('5d foreach SplFixedArray', function () use ($fixed) {
    $s = 0; foreach ($fixed as $v) $s += $v; return $s;
});
