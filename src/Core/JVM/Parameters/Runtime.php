<?php
declare(strict_types=1);
namespace PHPJava\Core\JVM\Parameters;

use Monolog\Logger;

final class Runtime
{
    const ENTRYPOINT = null;

    const MAX_STACK_EXCEEDED = 9999;
    const MAX_EXECUTION_TIME = 5;
    const STRICT = true;
    const DRY_RUN_ATTRIBUTE = false;

    const OPERATIONS_ENABLE_TRACE = false;
    const OPERATIONS_TEMPORARY_CODE_STREAM = 'php://memory';

    const VALIDATION_METHOD_ARGUMENTS_COUNT_ONLY = false;

    const OUTPUT_HANDLER = 'php://stdout';
    const OUTPUT_HEAPSPACE = false;

    const LOG_PATH = 'php://stderr';
    const LOG_LEVEL = Logger::EMERGENCY;

    const LOAD_ATTRIBUTES = [
        'Code',
        'Exceptions',
        'SourceFile',
        'InnerClasses',
        'BootstrapMethods',
        // Java 11+ — load past the parser. AttributeInfo.php:45 silently
        // skips anything outside this list; Java 11+ class files require
        // these attribute parsers to be reachable.
        'NestHost',
        'NestMembers',
        // Java 16+ records, Java 17+ sealed.
        'Record',
        'PermittedSubclasses',
    ];

    const PHP_PACKAGES_MAPS = [
        'String' => 'String_',
        'Object' => 'Object_',
        'Void' => 'Void_',
    ];

    const CLASS_INITIALIZER_CLASS_MAPS = [
        '__construct' => '<init>',
        '__staticConstruct' => '<clinit>',
    ];

    const PHP_PACKAGES_NAMESPACE = 'PHPJava\\Packages';
    const MNEMONIC_NAMESPACE = 'PHPJava\\Kernel\\Mnemonics';

    // Phase E (2026-05-04) deleted the legacy PHP→bytecode stack
    // (src/Compiler/Lang/Assembler/, Builder/, Emulator/). The
    // EMULATOR_MNEMONIC_NAMESPACE / BUILD_PACKAGE_NAMESPACE /
    // PHP_STANDARD_CLASS_NAME constants that pointed into it are gone
    // with the code. Phase D (interpreter delete) is next; will retire
    // MNEMONIC_NAMESPACE.

    const PREFIX_STATIC = 'static_';
    const PREFIX_DEFAULT = '__default_';
}
