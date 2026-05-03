<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _drem extends AbstractOperationCode implements OperationCodeInterface
{
    protected $isStackingOperation = true;

    public function getOperands(): ?Operands
    {
        parent::getOperands();
        if ($this->operands !== null) {
            return $this->operands;
        }
        return $this->operands = new Operands();
    }

    public function execute(): void
    {
        parent::execute();
        $rightOperand = Normalizer::getPrimitiveValue($this->popFromOperandStack());
        $leftOperand = Normalizer::getPrimitiveValue($this->popFromOperandStack());

        // Per JVM spec drem uses IEEE 754 floor-mod; use fmod() for
        // float remainders (PHP's `%` is integer modulo only).
        $this->pushToOperandStack((float) fmod($leftOperand, $rightOperand));
    }
}
