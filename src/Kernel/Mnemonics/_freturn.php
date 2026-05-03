<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _freturn extends AbstractOperationCode implements OperationCodeInterface
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
        // Return raw PHP float per CONTRACTS.md §1. JVM float is 32-bit
        // IEEE 754; PHP float is 64-bit. Documented divergence in
        // JVM-PHP-DELTA.md. Defensive unwrap during transition.
        $this->returnValue = (float) Normalizer::getPrimitiveValue(
            $this->popFromOperandStack()
        );
    }
}
