<?php
declare(strict_types=1);
namespace PHPJava\Core\Stream\Reader;

use PHPJava\Core\JVM\Stream\StreamReaderInterface;
use PHPJava\Core\JVM\Stream\StringByteReader;

class FileReader implements ReaderInterface
{
    private string $fileName;
    private StringByteReader $reader;

    public function __construct(string $file)
    {
        if (!preg_match('/\.class$/', $file, $matches)) {
            // Add extension
            $file = $file . '.class';
        }
        $this->fileName = $file;
        // M1 (`bench/profile-c930e2c.md`) — read whole file into memory
        // up front, then index by offset. Replaces the per-read
        // `fread()` + `unpack()` chain on a stream resource that
        // dominated the interpreter dispatch profile.
        $this->reader = new StringByteReader(file_get_contents($file));
    }

    public function getReader(): StreamReaderInterface
    {
        return $this->reader;
    }

    public function getJavaPathName(): string
    {
        return preg_replace(
            '/\.class$/',
            '',
            basename($this->fileName)
        );
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
