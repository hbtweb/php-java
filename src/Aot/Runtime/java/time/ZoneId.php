<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\time;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.time.ZoneId. Regenerate via
 *   php tools/gen-aot-stubs.php java.time.ZoneId
 */
abstract class ZoneId
{
    public static $SHORT_IDS = null;

    public static function systemDefault()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function getAvailableZoneIds()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function of($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function ofOffset($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function from($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function __construct()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getId();

    public function getDisplayName($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getRules();

    public function normalized()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getOffset($a0 = null);

    public function equals($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function hashCode()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function toString()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function write($a0 = null);
}
