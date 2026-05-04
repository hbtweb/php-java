<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Object — Path C placeholder for the AOT shim shape.
 *
 * Underscored simple name because PHP 8 reserves `object`
 * case-insensitively as a class name. Builder::classFqn rewrites
 * `java/lang/Object` to this. Almost all instance methods on Object
 * are IR-lowered or routed via dispatch on the receiver's actual
 * runtime class (PHP scalar / specific shim class), so this file
 * exists primarily to satisfy autoload when AOT-emitted code names
 * `\PHPJava\Aot\Runtime\java\lang\Object_` literally — for instance
 * `instanceof Object_` checks or static-field lookups.
 */
class Object_
{
}
