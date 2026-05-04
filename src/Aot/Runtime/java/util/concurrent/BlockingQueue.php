<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.concurrent.BlockingQueue. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.concurrent.BlockingQueue
 */
interface BlockingQueue
{
    public function add($a0 = null);
    public function put($a0 = null);
    public function offer($a0 = null, $a1 = null, $a2 = null);
    public function take();
    public function poll($a0 = null, $a1 = null);
    public function remainingCapacity();
    public function remove($a0 = null);
    public function contains($a0 = null);
    public function drainTo($a0 = null, $a1 = null);
}
