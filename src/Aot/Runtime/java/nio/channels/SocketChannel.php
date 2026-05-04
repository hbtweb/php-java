<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\nio\channels;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.nio.channels.SocketChannel. Regenerate via
 *   php tools/gen-aot-stubs.php java.nio.channels.SocketChannel
 */
abstract class SocketChannel
{
    public function __construct($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function open($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function validOps()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function bind($a0 = null);

    abstract public function setOption($a0 = null, $a1 = null);

    abstract public function shutdownInput();

    abstract public function shutdownOutput();

    abstract public function socket();

    abstract public function isConnected();

    abstract public function isConnectionPending();

    abstract public function connect($a0 = null);

    abstract public function finishConnect();

    abstract public function getRemoteAddress();

    abstract public function read($a0 = null, $a1 = null, $a2 = null);

    abstract public function write($a0 = null, $a1 = null, $a2 = null);

    abstract public function getLocalAddress();
}
