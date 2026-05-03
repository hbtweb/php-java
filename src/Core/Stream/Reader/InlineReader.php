<?php
declare(strict_types=1);
namespace PHPJava\Core\Stream\Reader;

use PHPJava\Core\JVM\Stream\StreamReaderInterface;
use PHPJava\Core\JVM\Stream\StringByteReader;

class InlineReader implements ReaderInterface
{
    private string $fileName;
    private StringByteReader $reader;

    public function __construct(string $fileName, string $code)
    {
        $this->fileName = $fileName;
        // M1 (`bench/profile-c930e2c.md`) — string-byte reader by
        // offset; no `php://memory` stream + `fwrite` + `rewind` setup,
        // no per-read `fread()` + `unpack()` chain.
        $this->reader = new StringByteReader($code);
    }

    public function getReader(): StreamReaderInterface
    {
        return $this->reader;
    }

    public function getJavaPathName(): string
    {
        return str_replace('/', '.', $this->fileName);
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function __toString(): string
    {
        return $this->fileName;
    }
}
