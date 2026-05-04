<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.concurrent.ScheduledExecutorService. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.concurrent.ScheduledExecutorService
 */
interface ScheduledExecutorService
{
    public function schedule($a0 = null, $a1 = null, $a2 = null);
    public function scheduleAtFixedRate($a0 = null, $a1 = null, $a2 = null, $a3 = null);
    public function scheduleWithFixedDelay($a0 = null, $a1 = null, $a2 = null, $a3 = null);
}
