<?php
namespace PHPJava\Aot\Generated;

final class BenchConcat
{
    // greet (Ljava/lang/String;I)Ljava/lang/String;
    public static function greet($__a0, $__a1)
    {
$L = [$__a0, $__a1];
        $stack = []; $sp = 0;
L_0:
        $stack[$sp++] = $L[0];
        $stack[$sp++] = $L[1];
        $__a1 = $stack[--$sp]; $__a0 = $stack[--$sp]; $stack[$sp++] = 'hello ' . (string)$__a0 . '! count=' . (string)$__a1;
        return $stack[--$sp];
    }
}
