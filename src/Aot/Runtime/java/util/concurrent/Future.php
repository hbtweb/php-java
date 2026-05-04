<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.concurrent.Future. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.concurrent.Future
 */
interface Future
{
    public function cancel($a0 = null);
    public function isCancelled();
    public function isDone();
    public function get($a0 = null, $a1 = null);
    public function resultNow();
    public function exceptionNow();
    public function state();
}
