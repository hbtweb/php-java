<?php
// HelloWorld end-to-end: AOT-compile + run + verify output.
//
// First fixture exercising the full path:
//   - getstatic java/lang/System.out
//   - ldc "hello from phpjava"
//   - invokevirtual PrintStream.println(String)
//   - if_icmpgt branch
//   - iadd loop accumulator
//   - System.out.println(int)
//
// Expected stdout:
//   hello from phpjava
//   55

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Compiler;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

$compiler = new Compiler();
$emitted = $compiler->compileClass('HelloWorld');

$outDir = __DIR__ . '/aot-out';
if (!is_dir($outDir)) mkdir($outDir, 0755, true);
$outPath = $outDir . '/HelloWorld.php';
file_put_contents($outPath, $emitted);

echo "=== Emitted PHP for HelloWorld ===\n";
echo $emitted;
echo "\n=== Output (subprocess capture — PrintStream writes to STDOUT fd, not PHP's output buffer) ===\n";

// Spawn a subprocess that runs HelloWorld::main(), capture its real
// stdout. We can't ob_start() this — fwrite(STDOUT, ...) bypasses PHP's
// output buffering because it writes to the OS file descriptor directly.
$runner = <<<'PHP'
<?php
require_once $argv[1];
require_once $argv[2];
// Java's main takes String[] args; pass empty array.
\PHPJava\Aot\Generated\HelloWorld::main([]);
PHP;
$runnerFile = sys_get_temp_dir() . '/aot-helloworld-run.php';
file_put_contents($runnerFile, $runner);

$cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($runnerFile)
     . ' ' . escapeshellarg(__DIR__ . '/../src/Aot/Runtime/bootstrap.php')
     . ' ' . escapeshellarg($outPath);
$output = shell_exec($cmd . ' 2>&1');
echo $output;
@unlink($runnerFile);

echo "=== Result ===\n";
$lines = explode("\n", trim((string)$output));
$expected = ['hello from phpjava', '55'];
$pass = count($lines) === 2 && $lines[0] === $expected[0] && $lines[1] === $expected[1];
echo $pass ? "PASS\n" : "FAIL — got " . var_export($lines, true) . "\n";
exit($pass ? 0 : 1);
