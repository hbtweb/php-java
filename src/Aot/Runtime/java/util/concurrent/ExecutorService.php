<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.concurrent.ExecutorService. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.concurrent.ExecutorService
 */
interface ExecutorService
{
    public function shutdown();
    public function shutdownNow();
    public function isShutdown();
    public function isTerminated();
    public function awaitTermination($a0 = null, $a1 = null);
    public function submit($a0 = null, $a1 = null);
    public function invokeAll($a0 = null, $a1 = null, $a2 = null);
    public function invokeAny($a0 = null, $a1 = null, $a2 = null);
    public function close();
}
