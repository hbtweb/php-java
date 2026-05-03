<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _dreturn extends AbstractOperationCode implements OperationCodeInterface
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
        // Return raw PHP float per CONTRACTS.md §1. JVM double = PHP
        // float = 64-bit IEEE 754; semantics match.
        $this->returnValue = (float) Normalizer::getPrimitiveValue(
            $this->popFromOperandStack()
        );
    }
}
