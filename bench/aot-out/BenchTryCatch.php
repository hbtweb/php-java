<?php
namespace PHPJava\Aot\Generated;

final class BenchTryCatch
{
    // run ()I
    public static function run()
    {
$L = [0];
        $stack = []; $sp = 0;
L_0:
        try {
        $stack[$sp++] = (new \ReflectionClass(\PHPJava\Aot\Runtime\java\lang\RuntimeException::class))->newInstanceWithoutConstructor();
        $stack[$sp] = $stack[$sp - 1]; $sp++;
        $stack[$sp++] = 'test';
        $__a0 = $stack[--$sp]; $__obj = $stack[--$sp]; $__obj->__construct($__a0);
        throw $stack[--$sp];
        } catch (\PHPJava\Aot\Runtime\java\lang\RuntimeException $__e) { $sp = 0; $stack[$sp++] = $__e; goto L_10; }
L_10:
        $L[0] = $stack[--$sp];
        return 42;
    }
}
