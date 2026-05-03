<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _iadd extends AbstractOperationCode implements OperationCodeInterface
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
        $value2 = (int) Normalizer::getPrimitiveValue($this->popFromOperandStack());
        $value1 = (int) Normalizer::getPrimitiveValue($this->popFromOperandStack());

        // Push the raw int — sub-step #12 (drop primitive wrappers from
        // operand stack). Consumers use Normalizer::getPrimitiveValue
        // defensively so they accept either shape during the transition.
        $result = $value1 + $value2; $this->pushToOperandStack(($result << 32) >> 32);
    }
}
