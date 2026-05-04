<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang\reflect;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.lang.reflect.Member. Regenerate via
 *   php tools/gen-aot-stubs.php java.lang.reflect.Member
 */
interface Member
{
    const PUBLIC = null;
    const DECLARED = null;

    public function getDeclaringClass();
    public function getName();
    public function getModifiers();
    public function accessFlags();
    public function isSynthetic();
}
