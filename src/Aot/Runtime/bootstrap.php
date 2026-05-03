<?php
declare(strict_types=1);

// AOT-clean stdlib shim layer.
//
// AOT-emitted code lands here for JDK targets (java/*, javax/*, jdk/*,
// sun/*, com/sun/*) — see Compiler::classFqn(). These classes have native
// PHP shapes:
//
//   - Static fields are direct PHP static fields. `getstatic
//     java/lang/System.out` emits `\PHPJava\Aot\Runtime\java\lang\System::$out`.
//   - Methods take their natural Java parameters. `invokevirtual
//     PrintStream.println(String)` emits `$obj->println($s)` — no
//     methodSignature-string disambiguator like the interpreter shim.
//
// The interpreter's shim layer (\PHPJava\Packages\java\*) stays
// untouched until Week 2's boxing/wrapper subtraction pass; see
// docs/STATUS.md "What's open".

namespace PHPJava\Aot\Runtime\java\lang;

class System
{
    public static ?\PHPJava\Aot\Runtime\java\io\PrintStream $out = null;
    public static ?\PHPJava\Aot\Runtime\java\io\PrintStream $err = null;
}

// Throwable hierarchy. Java's checked vs unchecked distinction is a
// javac concern, not a runtime one — PHP catch matches by class name.
// We piggyback on PHP's native Throwable hierarchy: Throwable → Exception
// (RuntimeException, IllegalArgumentException, etc.) → custom subclasses.
//
// `__construct(string $message = "")` matches Java's String-arg constructor;
// the Throwable cause-chain (`Throwable cause` arg) is mapped to PHP's
// $previous. Fields beyond message/cause come back via getMessage() etc.
class Throwable_ extends \Exception {}
class Exception_ extends \Exception {}
class RuntimeException extends \RuntimeException {}
class IllegalArgumentException extends \InvalidArgumentException {}
class IllegalStateException extends \LogicException {}
class NullPointerException extends \TypeError {}
class ArithmeticException extends \DivisionByZeroError {}
class ArrayIndexOutOfBoundsException extends \OutOfBoundsException {}
class ClassCastException extends \TypeError {}
class IndexOutOfBoundsException extends \OutOfBoundsException {}
class NumberFormatException extends \InvalidArgumentException {}
class UnsupportedOperationException extends \BadMethodCallException {}

namespace PHPJava\Aot\Runtime\java\io;

class PrintStream
{
    /** @var resource */
    private $stream;

    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function println($x = null): void
    {
        \fwrite($this->stream, ((string)$x) . "\n");
    }

    public function print($x = null): void
    {
        \fwrite($this->stream, (string)$x);
    }
}

namespace PHPJava\Aot\Runtime;

// One-time init. Idempotent — safe to require_once any number of times.
if (\PHPJava\Aot\Runtime\java\lang\System::$out === null) {
    \PHPJava\Aot\Runtime\java\lang\System::$out =
        new \PHPJava\Aot\Runtime\java\io\PrintStream(\STDOUT);
    \PHPJava\Aot\Runtime\java\lang\System::$err =
        new \PHPJava\Aot\Runtime\java\io\PrintStream(\STDERR);
}
