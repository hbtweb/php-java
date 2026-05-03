<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _i2s extends AbstractOperationCode implements OperationCodeInterface
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

        // JVM i2s: lower 16 bits with sign extension to 32-bit int.
        $this->pushToOperandStack(($value << 16) >> 16);
    }
}
