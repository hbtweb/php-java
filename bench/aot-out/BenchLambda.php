<?php
namespace PHPJava\Aot\Generated;

final class BenchLambda
{
    // run ()I
    public static function run()
    {
$L = [0];
        $stack = []; $sp = 0;
L_0:
        $stack[$sp++] = new \PHPJava\Aot\Generated\BenchLambda__Lambda_0();
        $L[0] = $stack[--$sp];
        $stack[$sp++] = $L[0];
        $__obj = $stack[--$sp]; $stack[$sp++] = $__obj->getAsInt();
        return $stack[--$sp];
    }

    // withCapture (I)I
    public static function withCapture($__a0)
    {
$L = [$__a0, 0];
        $stack = []; $sp = 0;
L_0:
        $stack[$sp++] = $L[0];
        $__c0 = $stack[--$sp]; $stack[$sp++] = new \PHPJava\Aot\Generated\BenchLambda__Lambda_1($__c0);
        $L[1] = $stack[--$sp];
        $stack[$sp++] = $L[1];
        $__obj = $stack[--$sp]; $stack[$sp++] = $__obj->getAsInt();
        return $stack[--$sp];
    }

    // lambda$withCapture$0 (I)I
    public static function lambda_S_withCapture_S_0($__a0)
    {
$L = [$__a0];
        $stack = []; $sp = 0;
L_0:
        return $L[0] + 100;
    }

    // lambda$run$0 ()I
    public static function lambda_S_run_S_0()
    {
$L = [];
        $stack = []; $sp = 0;
L_0:
        return 42;
    }
}

namespace PHPJava\Aot\Generated;

final class BenchLambda__Lambda_0
{

    public function getAsInt() {
        return \PHPJava\Aot\Generated\BenchLambda::lambda_S_run_S_0();
    }
}

namespace PHPJava\Aot\Generated;

final class BenchLambda__Lambda_1
{
    private $c0;
    public function __construct($c0) {
        $this->c0 = $c0;
    }
    public function getAsInt() {
        return \PHPJava\Aot\Generated\BenchLambda::lambda_S_withCapture_S_0($this->c0);
    }
}
