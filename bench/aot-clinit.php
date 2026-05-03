<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Compiler;
use PHPJava\Aot\Loader;

$bytes = file_get_contents(__DIR__ . '/fixtures/BenchClinit.class');

echo "=== Emitted PHP for BenchClinit ===\n";
echo (new Compiler())->compileBytes('BenchClinit', $bytes);

echo "\n=== Result via Loader::callStatic ===\n";

Loader::reset();
Loader::defineClass('BenchClinit', $bytes);

$counter = Loader::callStatic('BenchClinit', 'getCounter');
$doubled = Loader::callStatic('BenchClinit', 'getDoubled');
$constant = Loader::callStatic('BenchClinit', 'constant');

printf("getCounter()  = %d  (expected 55)\n", $counter);
printf("getDoubled()  = %d  (expected 110)\n", $doubled);
printf("constant()    = %d  (expected 42)\n", $constant);

$ok = ($counter === 55 && $doubled === 110 && $constant === 42);
echo $ok ? "PASS\n" : "FAIL\n";
exit($ok ? 0 : 1);
