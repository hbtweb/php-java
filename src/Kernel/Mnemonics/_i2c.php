<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _i2c extends AbstractOperationCode implements OperationCodeInterface
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
        $value = (int) Normalizer::getPrimitiveValue(
            $this->popFromOperandStack()
        );

        // JVM i2c: lower 16 bits zero-extended (char is unsigned).
        $this->pushToOperandStack($value & 0xFFFF);
    }
}
