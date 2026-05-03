<?php
namespace PHPJava\Aot\Generated;

#[\AllowDynamicProperties]
final class BenchLambda
{
    // __construct ()V
    public function __construct()
    {
$L = [$this];
        $stack = []; $sp = 0;
L_0:
        return;
    }

    // run ()I
    public static function run()
    {
$L = [0];
        $stack = []; $sp = 0;
L_0:
        $L[0] = new \PHPJava\Aot\Ir\Generated\BenchLambda__Lambda_0();
        return $L[0]->getAsInt();
    }

    // withCapture (I)I
    public static function withCapture($__a0)
    {
$L = [$__a0, 0];
        $stack = []; $sp = 0;
L_0:
        $L[1] = new \PHPJava\Aot\Ir\Generated\BenchLambda__Lambda_1($L[0]);
        return $L[1]->getAsInt();
    }

    // lambda_S_withCapture_S_0 (I)I
    public static function lambda_S_withCapture_S_0($__a0)
    {
$L = [$__a0];
        $stack = []; $sp = 0;
L_0:
        return $L[0] + 100 << 32 >> 32;
    }

    // lambda_S_run_S_0 ()I
    public static function lambda_S_run_S_0()
    {
$L = [];
        $stack = []; $sp = 0;
L_0:
        return 42;
    }
}

namespace PHPJava\Aot\Ir\Generated;

final class BenchLambda__Lambda_0
{

    public function getAsInt() {
        return \PHPJava\Aot\Generated\BenchLambda::lambda_S_run_S_0();
    }
}

namespace PHPJava\Aot\Ir\Generated;

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
