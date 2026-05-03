<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _lreturn extends AbstractOperationCode implements OperationCodeInterface
{
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
        // Return raw PHP int — JVM long = PHP int = 64-bit, no mask
        // (CONTRACTS.md §1). Defensive unwrap during the wrapper-removal
        // transition in case a caller still pushes Long_.
        $this->returnValue = (int) Normalizer::getPrimitiveValue(
            $this->popFromOperandStack()
        );
    }
}
