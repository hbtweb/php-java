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
        throw new \PHPJava\Aot\Runtime\java\lang\RuntimeException('test');
        } catch (\PHPJava\Aot\Runtime\java\lang\RuntimeException $__e) { $sp = 0; $stack[$sp++] = $__e; goto L_10; }
L_10:
        $L[0] = $__e;
        return 42;
    }
}
