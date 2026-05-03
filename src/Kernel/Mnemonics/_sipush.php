<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

final class _sipush extends AbstractOperationCode implements OperationCodeInterface
{
    protected $isStackingOperation = true;

    public function getOperands(): ?Operands
    {
        parent::getOperands();
        if ($this->operands !== null) {
            return $this->operands;
        }
        $byte = $this->readShort();

        return $this->operands = new Operands(
            ['byte', $byte, ['byte1', 'byte2']]
        );
    }

    public function execute(): void
    {
        parent::execute();
        $this->pushToOperandStack((int) $this->getOperands()['byte']);
    }
}
