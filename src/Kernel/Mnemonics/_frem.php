<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _frem extends AbstractOperationCode implements OperationCodeInterface
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

        // Per JVM spec: frem uses the IEEE 754 floor-mod definition.
        // PHP's `%` is integer-modulo (truncates), so use fmod() for
        // float remainders.
        $this->pushToOperandStack((float) fmod($leftOperand, $rightOperand));
    }
}
