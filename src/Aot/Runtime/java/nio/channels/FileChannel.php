<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\channels;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.channels.FileChannel. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.channels.FileChannel
 */
abstract class FileChannel
{

    public function __construct()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function open($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function read($a0 = null, $a1 = null, $a2 = null);

    abstract public function write($a0 = null, $a1 = null, $a2 = null);

    abstract public function position($a0 = null);

    abstract public function size();

    abstract public function truncate($a0 = null);

    abstract public function force($a0 = null);

    abstract public function transferTo($a0 = null, $a1 = null, $a2 = null);

    abstract public function transferFrom($a0 = null, $a1 = null, $a2 = null);

    public function map($a0 = null, $a1 = null, $a2 = null, $a3 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function lock($a0 = null, $a1 = null, $a2 = null);

    abstract public function tryLock($a0 = null, $a1 = null, $a2 = null);
}
