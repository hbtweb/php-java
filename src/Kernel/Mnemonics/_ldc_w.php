<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Mnemonics;

use PHPJava\Kernel\Structures\FloatInfo;
use PHPJava\Kernel\Structures\IntegerInfo;
use PHPJava\Kernel\Structures\StringInfo;
use PHPJava\Kernel\Structures\Utf8Info;
use PHPJava\Kernel\Types\Int_;

final class _ldc_w extends AbstractOperationCode implements OperationCodeInterface
{
    protected $isStackingOperation = true;

    public function getOperands(): ?Operands
    {
        parent::getOperands();
        if ($this->operands !== null) {
            return $this->operands;
        }
        $indexbyte = $this->readUnsignedShort();

        return $this->operands = new Operands(
            ['indexbyte', $indexbyte, ['indexbyte1', 'indexbyte2']]
        );
    }

    public function execute(): void
    {
        parent::execute();
        $cpInfo = $this->getConstantPool();
        $data = $cpInfo[$this->getOperands()['indexbyte']];

        $value = null;

        if ($data instanceof StringInfo) {
            $value = $cpInfo[$data->getStringIndex()];

            if ($value instanceof Utf8Info) {
                $value = $value->getStringObject();
            }
        } elseif (($data instanceof IntegerInfo)) {
            $value = Int_::get($data->getBytes());
        } elseif ($data instanceof FloatInfo) {
            // Pre-existing bug: was `instanceof Float_` (the type-tag class),
            // never matched a constant-pool entry. Should be FloatInfo (the
            // CP struct). Fixed alongside the wrapper-removal since we're
            // already touching this branch.
            $value = (float) $data->getBytes();
        } else {
            $value = $cpInfo[$data->getClassIndex()];
        }

        $this->pushToOperandStack($value);
    }
}
