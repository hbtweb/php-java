<?php
// Measure the remaining bottlenecks not covered by earlier benches:
// - String operations (Java String API on PHP)
// - Method invocation full chain (descriptor + dispatch)
// - Exception throw+catch
// - Numeric tower (GMP vs overflow-checked int vs raw int)
// - Static field access (with lazy <clinit>)
// - Array creation cost (Java arrays of various types)

const REPS = 100_000;

function bench(string $label, callable $body): float {
    $body();
    $t0 = hrtime(true);
    for ($i = 0; $i < REPS; $i++) $body();
    return (hrtime(true) - $t0) / REPS;
}

echo "PHP " . PHP_VERSION . " ";
echo (extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli'))
    ? "(opcache; jit=" . (ini_get('opcache.jit') ?: 'off') . ")"
    : "(no opcache)";
echo "\n\n";

// =============================================================================
// 1. String operations — Java String API mapped to PHP
// =============================================================================

echo "1. STRING OPERATIONS (Java String API costs)\n";

$haystack = "the quick brown fox jumps over the lazy dog";
$needle   = "fox";

printf("  %-50s %8.0f ns/op\n", "1a strlen / String.length()",                bench('', fn() => strlen($haystack)));
printf("  %-50s %8.0f ns/op\n", "1b strpos / String.indexOf()",                bench('', fn() => strpos($haystack, $needle)));
printf("  %-50s %8.0f ns/op\n", "1c substr / String.substring()",              bench('', fn() => substr($haystack, 4, 5)));
printf("  %-50s %8.0f ns/op\n", "1d str_replace / String.replace()",           bench('', fn() => str_replace('o', 'O', $haystack)));
printf("  %-50s %8.0f ns/op\n", "1e $a . $b / String concat (Java 9+ indy)",   bench('', fn() => "prefix " . $haystack . " suffix"));
printf("  %-50s %8.0f ns/op\n", "1f sprintf",                                   bench('', fn() => sprintf('%s = %d', 'val', 42)));
printf("  %-50s %8.0f ns/op\n", "1g strcmp / String.compareTo()",              bench('', fn() => strcmp($haystack, "another string")));
printf("  %-50s %8.0f ns/op\n", "1h hash crc32 / String.hashCode()-ish",       bench('', fn() => crc32($haystack)));
printf("  %-50s %8.0f ns/op\n", "1i mb_strlen UTF-8 / Java String UTF-16",     bench('', fn() => mb_strlen($haystack)));
printf("  %-50s %8.0f ns/op\n", "1j explode / String.split()",                  bench('', fn() => explode(' ', $haystack)));
echo "\n";

// =============================================================================
// 2. Method invocation full chain
// =============================================================================

echo "2. METHOD INVOCATION (cross-method dispatch costs)\n";

class CallTarget {
    public function instMethod(int $a, int $b): int { return $a + $b; }
    public static function staticMethod(int $a, int $b): int { return $a + $b; }
    public final function finalMethod(int $a, int $b): int { return $a + $b; }
}
$obj = new CallTarget();

printf("  %-50s %8.0f ns/op\n", "2a direct PHP function call",                     bench('', fn() => CallTarget::staticMethod(1, 2)));
printf("  %-50s %8.0f ns/op\n", "2b instance method via \$obj",                     bench('', fn() => $obj->instMethod(1, 2)));
printf("  %-50s %8.0f ns/op\n", "2c final instance method (no virtual)",            bench('', fn() => $obj->finalMethod(1, 2)));
printf("  %-50s %8.0f ns/op\n", "2d call_user_func_array",                          bench('', fn() => call_user_func_array([$obj, 'instMethod'], [1, 2])));

$rm = new ReflectionMethod($obj, 'instMethod');
printf("  %-50s %8.0f ns/op\n", "2e ReflectionMethod::invoke",                      bench('', fn() => $rm->invoke($obj, 1, 2)));

$rm2 = new ReflectionMethod(CallTarget::class, 'staticMethod');
printf("  %-50s %8.0f ns/op\n", "2f ReflectionMethod::invoke (static)",             bench('', fn() => $rm2->invoke(null, 1, 2)));

// Simulate full PHPJava chain: load → getInvoker → getStatic → getMethods → call
class FakeInvoker {
    public function __construct(private object $target, private string $method) {}
    public function call(...$args): mixed { return ($this->target)->{$this->method}(...$args); }
}
$inv = new FakeInvoker($obj, 'instMethod');
printf("  %-50s %8.0f ns/op\n", "2g chained invoker ->call(...)",                   bench('', fn() => $inv->call(1, 2)));

echo "\n";

// =============================================================================
// 3. Exception throw + catch
// =============================================================================

echo "3. EXCEPTION (throw + catch costs)\n";

class CustomException extends Exception {}

// Pre-allocated exception (avoid alloc cost in measurement)
$preAlloc = new CustomException('pre');

printf("  %-50s %8.0f ns/op\n", "3a throw + catch new exception",                   bench('', function () {
    try { throw new CustomException('x'); } catch (CustomException $e) { return; }
}));

printf("  %-50s %8.0f ns/op\n", "3b throw + catch pre-allocated",                   bench('', function () use ($preAlloc) {
    try { throw $preAlloc; } catch (CustomException $e) { return; }
}));

printf("  %-50s %8.0f ns/op\n", "3c try (no throw) — overhead alone",               bench('', function () {
    try { return 1; } catch (CustomException $e) { return 0; }
}));

printf("  %-50s %8.0f ns/op\n", "3d if-check (Java pre-condition style)",           bench('', function () {
    return 5 > 0 ? 1 : 0;
}));

echo "\n";

// =============================================================================
// 4. Numeric tower — int / long / BigInteger
// =============================================================================

echo "4. NUMERIC TOWER (int arithmetic + overflow handling)\n";

printf("  %-50s %8.0f ns/op\n", "4a raw PHP int + (no overflow check)",             bench('', fn() => 1234567 + 7654321));
printf("  %-50s %8.0f ns/op\n", "4b PHP int + with overflow mask (Java 32-bit)",    bench('', function () {
    $r = (1234567 + 7654321) & 0xFFFFFFFF;
    return ($r & 0x80000000) ? $r - 0x100000000 : $r;
}));
printf("  %-50s %8.0f ns/op\n", "4c PHP int + with PHP_INT_MAX check",               bench('', function () {
    $a = 1234567; $b = 7654321;
    $r = $a + $b;
    return ($r < $a || $r < $b) ? null : $r;  // overflow detection
}));
if (extension_loaded('gmp')) {
    $gmpA = gmp_init('123456789012345678901234');
    $gmpB = gmp_init('987654321098765432109876');
    printf("  %-50s %8.0f ns/op\n", "4d GMP add (BigInteger equivalent)",            bench('', fn() => gmp_add($gmpA, $gmpB)));
    printf("  %-50s %8.0f ns/op\n", "4e GMP construct (each call)",                  bench('', fn() => gmp_init('999999999999')));
} else {
    echo "  4d/4e GMP not loaded — skipping BigInteger measurements\n";
}
if (extension_loaded('bcmath')) {
    printf("  %-50s %8.0f ns/op\n", "4f bcmath add (BigDecimal equivalent)",         bench('', fn() => bcadd('123456789.123', '987654321.987', 6)));
} else {
    echo "  4f bcmath not loaded — skipping BigDecimal measurements\n";
}

echo "\n";

// =============================================================================
// 5. Static field access (PHP class const, static prop, GLOBAL)
// =============================================================================

echo "5. STATIC FIELD ACCESS\n";

class Statics {
    const C = 42;
    public static int $S = 42;
}
$GLOBALS['G'] = 42;

printf("  %-50s %8.0f ns/op\n", "5a class const Statics::C",                        bench('', fn() => Statics::C));
printf("  %-50s %8.0f ns/op\n", "5b static property Statics::\$S",                   bench('', fn() => Statics::$S));
printf("  %-50s %8.0f ns/op\n", "5c static property write Statics::\$S = ...",       bench('', function () { Statics::$S = 42; }));
printf("  %-50s %8.0f ns/op\n", "5d \$GLOBALS read",                                 bench('', fn() => $GLOBALS['G']));
printf("  %-50s %8.0f ns/op\n", "5e \$GLOBALS write",                                bench('', function () { $GLOBALS['G'] = 42; }));

echo "\n";

// =============================================================================
// 6. Array creation costs
// =============================================================================

echo "6. ARRAY CREATION (Java array allocation in PHP)\n";

printf("  %-50s %8.0f ns/op\n", "6a empty []",                                      bench('', fn() => []));
printf("  %-50s %8.0f ns/op\n", "6b array literal [0,0,0,0,0]",                     bench('', fn() => [0, 0, 0, 0, 0]));
printf("  %-50s %8.0f ns/op\n", "6c array_fill(0, 100, 0) = new int[100]",          bench('', fn() => array_fill(0, 100, 0)));
printf("  %-50s %8.0f ns/op\n", "6d new SplFixedArray(100)",                         bench('', fn() => new SplFixedArray(100)));
printf("  %-50s %8.0f ns/op\n", "6e str_repeat(\"\\x00\", 100) — byte buffer",       bench('', fn() => str_repeat("\x00", 100)));
printf("  %-50s %8.0f ns/op\n", "6f range(0, 99)",                                   bench('', fn() => range(0, 99)));

echo "\n";

// =============================================================================
// 7. Type checks (Java instanceof / PHP equivalents)
// =============================================================================

echo "7. TYPE CHECKS (instanceof variants)\n";

$obj = new CustomException('x');
$arr = ['a', 'b'];
$str = "hello";
$int = 42;

printf("  %-50s %8.0f ns/op\n", "7a instanceof on object",                          bench('', fn() => $obj instanceof CustomException));
printf("  %-50s %8.0f ns/op\n", "7b instanceof on string (false)",                  bench('', fn() => $str instanceof CustomException));
printf("  %-50s %8.0f ns/op\n", "7c is_int(\$int)",                                 bench('', fn() => is_int($int)));
printf("  %-50s %8.0f ns/op\n", "7d is_string(\$str)",                              bench('', fn() => is_string($str)));
printf("  %-50s %8.0f ns/op\n", "7e is_array(\$arr)",                               bench('', fn() => is_array($arr)));
printf("  %-50s %8.0f ns/op\n", "7f gettype(\$int) === 'integer'",                  bench('', fn() => gettype(42) === 'integer'));
printf("  %-50s %8.0f ns/op\n", "7g get_class(\$obj) === ...",                      bench('', fn() => get_class($obj) === 'CustomException'));

echo "\n";

// =============================================================================
// 8. Microtime / system clock (System.currentTimeMillis equivalent)
// =============================================================================

echo "8. CLOCK + ID GENERATION\n";

printf("  %-50s %8.0f ns/op\n", "8a microtime(true)",                               bench('', fn() => microtime(true)));
printf("  %-50s %8.0f ns/op\n", "8b hrtime(true)",                                  bench('', fn() => hrtime(true)));
printf("  %-50s %8.0f ns/op\n", "8c microtime() string",                            bench('', fn() => microtime()));
printf("  %-50s %8.0f ns/op\n", "8d random_int(0, 100)",                            bench('', fn() => random_int(0, 100)));
printf("  %-50s %8.0f ns/op\n", "8e mt_rand(0, 100)",                               bench('', fn() => mt_rand(0, 100)));
printf("  %-50s %8.0f ns/op\n", "8f spl_object_id(\$obj)",                          bench('', fn() => spl_object_id($obj)));
