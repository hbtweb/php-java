<?php
declare(strict_types=1);
namespace PHPJava\Kernel\Structures;

class FloatInfo implements StructureInterface
{
    use \PHPJava\Kernel\Core\BinaryReader;
    use \PHPJava\Kernel\Core\ConstantPool;
    use \PHPJava\Kernel\Core\DebugTool;

    /**
     * @var int
     */
    private $bytes;

    /**
     * @var int
     */
    private $realByte;

    public function execute(): void
    {
        $this->bytes = $this->readUnsignedInt();
    }

    public function getBytes(): float
    {
        if (isset($this->realByte)) {
            return $this->realByte;
        }
        // Decode via IEEE754 round-trip: pack as big-endian uint32, then
        // unpack as a big-endian binary32 ('G'). Manual mantissa/exponent
        // math previously here returned INF for every NaN encoding —
        // see DoubleInfo for the same fix.
        return $this->realByte = \unpack('G', \pack('N', $this->bytes))[1];
    }
}
