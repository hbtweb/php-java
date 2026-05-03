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
        return 'hello ' . $L[0] . '! count=' . $L[1];
    }
}
