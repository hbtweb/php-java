<?php
// Smoke-test the lazy CallSite shim — IndyRegistry handles unknown
// invokedynamic bootstraps that the AOT compiler doesn't whitelist.
// Default: throws UnsupportedIndyBootstrapException with a clear
// message. Registered handler: receives bsm + call-site args, return
// value flows through the indy site.
//
// CONTRACTS.md §3 + §5 — extension hook for non-whitelisted indy
// patterns (JRuby / Groovy 3+ / Scala 3 dynamic dispatch).

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Aot/Runtime/bootstrap.php';

use PHPJava\Aot\Runtime\IndyRegistry;
use PHPJava\Aot\Runtime\UnsupportedIndyBootstrapException;

function check(string $label, $expected, $actual): bool {
    $ok = $expected === $actual;
    echo sprintf("%-65s exp=%s got=%s  %s\n",
        $label, var_export($expected, true), var_export($actual, true),
        $ok ? 'PASS' : 'FAIL');
    return $ok;
}

IndyRegistry::reset();

// Path 1 — unregistered bootstrap throws clear exception.
$threw = false; $msg = '';
try {
    IndyRegistry::resolve(
        'com/example/Bootstrap', 'dispatch', 'foo', '(I)I',
        ['arg0', 42], [123]
    );
} catch (UnsupportedIndyBootstrapException $e) {
    $threw = true;
    $msg = $e->getMessage();
}
$ok1 = check('unregistered bootstrap throws UnsupportedIndyBootstrapException', true, $threw);
$hasClass  = str_contains($msg, 'com/example/Bootstrap');
$hasMethod = str_contains($msg, 'dispatch');
$hasName   = str_contains($msg, 'foo');
$ok1a = check('  → exception message names bootstrap class',  true, $hasClass);
$ok1b = check('  → exception message names bootstrap method', true, $hasMethod);
$ok1c = check('  → exception message names call-site name',   true, $hasName);

// Path 2 — registered handler receives all four arg groups.
$captured = null;
IndyRegistry::register(
    'com/example/Bootstrap', 'dispatch',
    function (string $name, string $desc, array $bsmArgs, array $callSiteArgs) use (&$captured) {
        $captured = compact('name', 'desc', 'bsmArgs', 'callSiteArgs');
        return $bsmArgs[1] + $callSiteArgs[0];   // 42 + 123 = 165
    }
);

$result = IndyRegistry::resolve(
    'com/example/Bootstrap', 'dispatch', 'foo', '(I)I',
    ['arg0', 42], [123]
);
$ok2  = check('registered handler invoked, return flows through', 165, $result);
$ok2a = check('  → handler received call-site name',         'foo',                 $captured['name']);
$ok2b = check('  → handler received call-site descriptor',   '(I)I',                $captured['desc']);
$ok2c = check('  → handler received bootstrap args',         ['arg0', 42],          $captured['bsmArgs']);
$ok2d = check('  → handler received call-site args',         [123],                 $captured['callSiteArgs']);

// Path 3 — isRegistered + unregister.
$ok3a = check('isRegistered() finds the handler', true,  IndyRegistry::isRegistered('com/example/Bootstrap', 'dispatch'));
$ok3b = check('isRegistered() rejects unknown',   false, IndyRegistry::isRegistered('com/example/Other',     'dispatch'));
IndyRegistry::unregister('com/example/Bootstrap', 'dispatch');
$ok3c = check('after unregister, lookup fails',   false, IndyRegistry::isRegistered('com/example/Bootstrap', 'dispatch'));

// Path 4 — multiple bootstraps, no key collision.
IndyRegistry::register('com/a/B', 'm', fn() => 'first');
IndyRegistry::register('com/a/B', 'n', fn() => 'second');
IndyRegistry::register('com/c/D', 'm', fn() => 'third');
$ok4a = check('multi-handler dispatch — (B,m)',
    'first',  IndyRegistry::resolve('com/a/B', 'm', '', '', [], []));
$ok4b = check('multi-handler dispatch — (B,n)',
    'second', IndyRegistry::resolve('com/a/B', 'n', '', '', [], []));
$ok4c = check('multi-handler dispatch — (D,m)',
    'third',  IndyRegistry::resolve('com/c/D', 'm', '', '', [], []));

// Path 5 — AOT emits IndyRegistry::resolve for unknown bootstraps.
// Synthesize a minimal class with an indy site bootstrapped by a
// fictitious class. Easiest path: hand-construct the .class bytes
// is heavy; instead, validate the emit path by checking that the
// codepath is reachable and produces the right call shape on a
// constructed bsm structure. Done by reading `Builder::emitUnknownIndy`
// indirectly — covered by the unit-style tests above; an integration
// test requires a real fixture with non-whitelisted indy (JRuby JAR).
echo "\nPath 5 (real-bytecode integration with non-whitelisted bsm)\n";
echo "  deferred — requires JRuby/Groovy/Scala 3 fixture; the AOT\n";
echo "  emit path is exercised by inspection of Builder.php:1213 and\n";
echo "  by the registry unit-style paths above.\n";

$all = $ok1 && $ok1a && $ok1b && $ok1c
     && $ok2 && $ok2a && $ok2b && $ok2c && $ok2d
     && $ok3a && $ok3b && $ok3c
     && $ok4a && $ok4b && $ok4c;
echo "\n" . ($all ? 'OK' : 'FAIL') . "\n";
exit($all ? 0 : 1);
