<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang\reflect;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.lang.reflect.Executable. Regenerate via
 *   php tools/gen-aot-stubs.php java.lang.reflect.Executable
 */
abstract class Executable
{

    public function __construct()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getAnnotationBytes();

    abstract public function hasGenericInformation();

    abstract public function getGenericInfo();

    public function equalParamTypes($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function parseParameterAnnotations($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function printModifiersIfNonzero($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function sharedToString($a0 = null, $a1 = null, $a2 = null, $a3 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function specificToStringHeader($a0 = null);

    public static function typeVarBounds($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function sharedToGenericString($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function specificToGenericStringHeader($a0 = null);

    abstract public function getDeclaringClass();

    abstract public function getName();

    abstract public function getModifiers();

    public function accessFlags()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getTypeParameters();

    abstract public function getSharedParameterTypes();

    abstract public function getSharedExceptionTypes();

    abstract public function getParameterTypes();

    abstract public function getParameterCount();

    public function getGenericParameterTypes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAllGenericParameterTypes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getParameters()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function hasRealParameterData()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getTypeAnnotationBytes0()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getTypeAnnotationBytes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getExceptionTypes();

    public function getGenericExceptionTypes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function toGenericString();

    public function isVarArgs()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function isSynthetic()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getParameterAnnotations();

    public function sharedGetParameterAnnotations($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function handleParameterNumberMismatch($a0 = null, $a1 = null);

    public function getAnnotation($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAnnotationsByType($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getDeclaredAnnotations()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    abstract public function getAnnotatedReturnType();

    public function getAnnotatedReturnType0($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAnnotatedReceiverType()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function parameterize($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAnnotatedParameterTypes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAnnotatedExceptionTypes()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }
}
