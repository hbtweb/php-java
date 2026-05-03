<?php
// End-to-end record fixture: BenchRecord uses a Java record (Point)
// with synthetic equals/hashCode/toString generated via
// java.lang.runtime.ObjectMethods.bootstrap. This exercises:
//
//   - Instance-method emit (gap #1) — Point's <init>, x(), y(),
//     equals(), hashCode(), toString() are all instance methods.
//   - Field declarations via #[\AllowDynamicProperties] (gap #2-A)
//     — Point.<init> putfields x and y.
//   - ObjectMethods bootstrap recognition (work-plan #7).
//   - super(java/lang/Record).<init> elision in <init>.
//
// If any of those is broken, this test fails.

require_once __DIR__ . '/../vendor/autoload.php';

use PHPJava\Aot\Loader;
use PHPJava\Kernel\Resolvers\ClassResolver;

ClassResolver::add([
    [ClassResolver::RESOURCE_TYPE_FILE, __DIR__ . '/fixtures'],
]);

// Point.class is the inner — must AOT-compile the inner class first
// (it's referenced by the outer's `new Point(...)` calls).
Loader::loadClass('BenchRecord$Point');

$ok1 = Loader::callStatic('BenchRecord', 'eqSame') === 1;
echo "BenchRecord::eqSame() = " . Loader::callStatic('BenchRecord', 'eqSame')
    . "    " . ($ok1 ? "PASS" : "FAIL") . "\n";

$ok2 = Loader::callStatic('BenchRecord', 'eqDiff') === 0;
echo "BenchRecord::eqDiff() = " . Loader::callStatic('BenchRecord', 'eqDiff')
    . "    " . ($ok2 ? "PASS" : "FAIL") . "\n";

$ok3 = Loader::callStatic('BenchRecord', 'hashCodeOk') === 1;
echo "BenchRecord::hashCodeOk() = " . Loader::callStatic('BenchRecord', 'hashCodeOk')
    . "    " . ($ok3 ? "PASS" : "FAIL") . "\n";

$str = Loader::callStatic('BenchRecord', 'toStr');
$ok4 = $str === 'Point[x=3, y=5]';
echo "BenchRecord::toStr() = '{$str}'    " . ($ok4 ? "PASS" : "FAIL") . "\n";

exit($ok1 && $ok2 && $ok3 && $ok4 ? 0 : 1);
