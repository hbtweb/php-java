<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Filters\Normalizer;

final class _iinc extends AbstractOperationCode implements OperationCodeInterface
{
    public function getOperands(): ?Operands
    {
        parent::getOperands();
        if ($this->operands !== null) {
            return $this->operands;
        }
        $index = $this->readUnsignedByte();
        $const = $this->readByte();

        return $this->operands = new Operands(
            ['index', $index, ['index']],
            ['const', $const, ['const']]
        );
    }

    public function execute(): void
    {
        parent::execute();
        $index = $this->getOperands()['index'];
        $const = $this->getOperands()['const'];

        $value = (int) Normalizer::getPrimitiveValue($this->getLocalStorage($index));

        $result = $value + $const;
        $this->setLocalStorage($index, ($result << 32) >> 32);
    }
}
