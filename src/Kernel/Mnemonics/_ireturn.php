<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _ireturn extends AbstractOperationCode implements OperationCodeInterface
{
    public function getOperands(): ?Operands
    {
        parent::getOperands();
        if ($this->operands !== null) {
            return $this->operands;
        }
        return $this->operands = new Operands();
    }

    // return an integer from a method
    public function execute(): void
    {
        parent::execute();
        // Per CONTRACTS.md §1: return native PHP scalar. Defensively
        // unwrap via Normalizer in case a caller still pushes a Char_/
        // Short_/Byte_/_Boolean wrapper (the int-arithmetic + iload +
        // iconst Mnemonics now push raw ints; mixed-shape during the
        // wrapper-removal transition).
        $this->returnValue = (int) Normalizer::getPrimitiveValue(
            $this->popFromOperandStack()
        );
    }
}
