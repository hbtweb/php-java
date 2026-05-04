<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\lang;

/**
 * java.lang.Void — Path C placeholder for the AOT shim shape.
 *
 * Underscored simple name because PHP 8 reserves `void`
 * case-insensitively as a class name. Builder::classFqn rewrites
 * `java/lang/Void` to this. Java's Void is uninstantiable; its only
 * member is the static TYPE field (a Class<Void>). Reflection on
 * void-returning methods routes through here. Empty body suffices
 * until a fixture surfaces method-handle / reflective dispatch on
 * void return types.
 */
final class Void_
{
    public static $TYPE = null;
}
