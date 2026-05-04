<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\function;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.function.Predicate. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.function.Predicate
 */
interface Predicate
{
    public function test($a0 = null);
    public function and($a0 = null);
    public function negate();
    public function or($a0 = null);
    public function isEqual($a0 = null);
    public function not($a0 = null);
}
