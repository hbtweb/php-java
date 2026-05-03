<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _l2i extends AbstractOperationCode implements OperationCodeInterface
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

        // JVM l2i narrows long → int with truncation + 32-bit sign
        // extension. Same mask as Int_::filter applied.
        $this->pushToOperandStack(($value << 32) >> 32);
    }
}
