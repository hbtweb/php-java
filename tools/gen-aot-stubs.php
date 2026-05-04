<?php
declare(strict_types=1);

/**
 * Path C of docs/LAYERS.md §License posture: signature-only PHP stubs for
 * JDK classes so AOT-emitted code can resolve `\PHPJava\Aot\Runtime\<fqn>`
 * without "class not found" runtime errors. Each method body throws
 * NotImplementedException — no implementation copy. API signatures aren't
 * copyrightable; OpenJDK's GPL+CPE doesn't reach to signature shape.
 *
 * Driven by `javap -p` (signature-only — no bytecode dump). Output:
 *   src/Aot/Runtime/<package-path>/<Leaf>.php
 *
 * Usage:
 *   php tools/gen-aot-stubs.php java.lang.Boolean java.io.IOException
 *   php tools/gen-aot-stubs.php --file=/tmp/stubs-to-generate.txt
 *   php tools/gen-aot-stubs.php --dry-run java.lang.Boolean
 *
 * Flags:
 *   --dry-run        print to stdout, don't write files
 *   --file=<path>    read class FQNs (one per line) from file
 *   --force          overwrite existing files (default: skip)
 *
 * Skipped automatically:
 *   - PHP-reserved leaf names (Object, Class, Float, Void, Iterable, …) —
 *     these need a Builder.classFqn remap to use `<Name>_`; out of scope
 *     for the bulk stub batch.
 *   - Private/synthetic methods (lambda$, access$ prefix).
 *   - Inner-class members (separate javap calls per nested type).
 */

const ROOT_DIR  = __DIR__ . '/../src/Aot/Runtime';
const NS_PREFIX = 'PHPJava\\Aot\\Runtime\\';

/**
 * PHP-forbidden class names (case-insensitive — PHP itself folds case).
 * Combines: (a) reserved type names that can never be class names
 * (object/string/int/...), (b) PHP keywords used in declarations
 * (list/function/match/...). Java leaf names matching any of these
 * collide at the class-decl site and must be renamed; for the bulk
 * stub batch we skip them — handling needs a Builder.classFqn remap
 * to reach the renamed class via the AOT call boundary.
 */
const PHP_RESERVED_CLASS_NAMES = [
    // Type / value reserved names
    'object', 'string', 'class', 'float', 'void', 'iterable', 'mixed',
    'never', 'callable', 'bool', 'int', 'true', 'false', 'null', 'array',
    'readonly', 'self', 'static', 'parent', 'enum',
    // PHP language keywords that PHP rejects as class names
    'list', 'function', 'match', 'case', 'switch', 'for', 'foreach',
    'echo', 'use', 'trait', 'try', 'catch', 'finally', 'throw', 'return',
    'if', 'else', 'elseif', 'while', 'do', 'break', 'continue', 'goto',
    'declare', 'namespace', 'interface', 'abstract', 'final', 'public',
    'private', 'protected', 'var', 'global', 'const', 'fn', 'yield',
    'instanceof', 'insteadof', 'and', 'or', 'xor', 'new', 'clone',
    'isset', 'unset', 'empty', 'print', 'eval', 'exit', 'die',
    'include', 'include_once', 'require', 'require_once',
    'extends', 'implements', 'as',
];

const METHOD_MODIFIERS = [
    'public', 'private', 'protected', 'static', 'final', 'abstract',
    'native', 'synchronized', 'default', 'strictfp',
];
const FIELD_MODIFIERS = [
    'public', 'private', 'protected', 'static', 'final', 'transient',
    'volatile',
];

function isReservedLeaf(string $leaf): bool {
    return in_array(strtolower($leaf), PHP_RESERVED_CLASS_NAMES, true);
}

function fqnToFile(string $javaFqn): string {
    $parts = explode('.', $javaFqn);
    $leaf  = array_pop($parts);
    return ROOT_DIR . '/' . implode('/', $parts) . '/' . $leaf . '.php';
}

/**
 * Strip balanced `<...>` blocks. Iterates inside-out so nested generics
 * collapse correctly: `Map<String, List<Integer>>` → `Map`.
 */
function stripGenerics(string $s): string {
    while (preg_match('/<[^<>]*>/', $s)) {
        $s = preg_replace('/<[^<>]*>/', '', $s);
    }
    return $s;
}

/** @return string[]|null */
function runJavap(string $fqn): ?array {
    $out = [];
    $rc  = 0;
    exec('javap -p ' . escapeshellarg($fqn) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0 || count($out) < 2) {
        return null;
    }
    return $out;
}

/**
 * @param string[] $lines
 * @return array{kind:string, modifiers:string[], fqn:?string, super:?string,
 *     interfaces:string[], fields:array, methods:array}
 */
function parseJavap(array $lines): array {
    $result = [
        'kind' => 'class', 'modifiers' => [], 'fqn' => null,
        'super' => null, 'interfaces' => [],
        'fields' => [], 'methods' => [],
    ];

    $headerIdx = null;
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, 'Compiled from')) continue;
        if (preg_match('/\b(class|interface|enum|@interface)\b/', $t)) {
            $headerIdx = $i;
            break;
        }
    }
    if ($headerIdx === null) return $result;

    $header = stripGenerics($lines[$headerIdx]);
    $header = preg_replace('/\s+/', ' ', trim($header));
    $header = rtrim($header, '{} ');

    if (!preg_match(
        '/^(.*?)\b(class|interface|enum|@interface)\s+([\w.$]+)(?:\s+extends\s+([\w.$,\s]+?))?(?:\s+implements\s+([\w.$,\s]+?))?\s*$/',
        $header,
        $m
    )) {
        return $result;
    }
    $result['modifiers']  = array_values(array_filter(explode(' ', trim($m[1]))));
    $result['kind']       = $m[2] === '@interface' ? 'annotation' : $m[2];
    $result['fqn']        = $m[3];
    if (!empty(trim($m[4] ?? ''))) {
        $result['super'] = trim($m[4]);
    }
    if (!empty(trim($m[5] ?? ''))) {
        $result['interfaces'] = array_map('trim', explode(',', $m[5]));
    }

    for ($i = $headerIdx + 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '' || $line === '}') continue;
        $line = rtrim(stripGenerics($line), ';');
        $line = trim($line);
        if ($line === '') continue;

        if (str_contains($line, '(')) {
            $method = parseMethod($line, $result['fqn']);
            if ($method !== null) $result['methods'][] = $method;
        } else {
            $field = parseField($line);
            if ($field !== null) $result['fields'][] = $field;
        }
    }
    return $result;
}

/**
 * Parse a method signature line.
 *   public static boolean parseBoolean(java.lang.String)
 *   public java.lang.Boolean(boolean)                            <- ctor
 *   public final void wait() throws java.lang.InterruptedException
 *
 * @return array{name:string, modifiers:string[], argCount:int, isConstructor:bool, isStatic:bool, isAbstract:bool}|null
 */
function parseMethod(string $line, ?string $classFqn): ?array {
    $line = preg_replace('/\s+throws\s+.*$/', '', $line);
    if (!preg_match('/^(.*?)\s*\(([^)]*)\)\s*$/', $line, $m)) return null;
    $head = trim($m[1]);
    $args = trim($m[2]);
    if ($head === '') return null;

    $tokens = preg_split('/\s+/', $head);
    $last   = array_pop($tokens);

    $modifiers = [];
    while (!empty($tokens) && in_array($tokens[0], METHOD_MODIFIERS, true)) {
        $modifiers[] = array_shift($tokens);
    }
    $returnType = implode(' ', $tokens);

    $shortClassName = null;
    if ($classFqn !== null) {
        $bits = preg_split('/[.$]/', $classFqn);
        $shortClassName = end($bits);
    }

    $isConstructor = false;
    $name = $last;
    if ($returnType === '' && $shortClassName !== null) {
        // Constructor: head is (modifiers...) FQN — e.g.
        //   "public java.lang.Boolean" — `last` was the FQN; the short
        // class name is the trailing component after . or $.
        $bits = preg_split('/[.$]/', $name);
        $shortMethodName = end($bits);
        if ($shortMethodName === $shortClassName) {
            $isConstructor = true;
            $name = '__construct';
        }
    }

    // Filter synthetic methods (`$deserializeLambda$`, `$1$privateField`).
    // javap -p surfaces them but they aren't part of the API surface.
    if (!$isConstructor) {
        if (str_contains($name, '$')) return null;
        if (!preg_match('/^[a-zA-Z_][\w]*$/', $name)) return null;
    }

    $argCount = $args === '' ? 0 : count(explode(',', $args));

    return [
        'name'          => $name,
        'modifiers'     => $modifiers,
        'argCount'      => $argCount,
        'isConstructor' => $isConstructor,
        'isStatic'      => in_array('static', $modifiers, true),
        'isAbstract'    => in_array('abstract', $modifiers, true),
    ];
}

/**
 * Parse a field line.
 *   public static final java.lang.Boolean TRUE
 *   private final boolean value
 *
 * @return array{name:string, modifiers:string[], isStatic:bool, isPublic:bool}|null
 */
function parseField(string $line): ?array {
    $tokens = preg_split('/\s+/', trim($line));
    if (count($tokens) < 2) return null;
    $name = array_pop($tokens);
    $modifiers = [];
    foreach ($tokens as $tok) {
        if (in_array($tok, FIELD_MODIFIERS, true)) {
            $modifiers[] = $tok;
        } else {
            break; // type token
        }
    }
    // Filter synthetic / inner-class-link fields ($assertionsDisabled,
    // this$0, val$x). These leak through javap -p but aren't part of
    // the API surface, and `$` in the name breaks PHP variable syntax.
    if (str_contains($name, '$')) return null;
    if (!preg_match('/^[a-zA-Z_][\w]*$/', $name)) return null;
    $isPrivate   = in_array('private',   $modifiers, true);
    $isProtected = in_array('protected', $modifiers, true);
    return [
        'name'      => $name,
        'modifiers' => $modifiers,
        'isStatic'  => in_array('static', $modifiers, true),
        'isPublic'  => !$isPrivate && !$isProtected,
    ];
}

/**
 * Render the parsed signature to PHP source.
 */
function renderStub(array $parsed, string $javaFqn): string {
    $parts = explode('.', $javaFqn);
    $leaf  = array_pop($parts);
    $namespace = NS_PREFIX . implode('\\', $parts);

    $kind        = $parsed['kind'];
    $modifiers   = $parsed['modifiers'];
    $isInterface = ($kind === 'interface' || $kind === 'annotation');
    $isAbstract  = in_array('abstract', $modifiers, true);
    $isFinal     = in_array('final',    $modifiers, true);

    // Standalone stubs — no extends/implements. Path C correctness (clean
    // class-load + method dispatch) doesn't need the full hierarchy; that
    // belongs to real implementations once the oracle harness lands.
    // Keeps the stub batch from cascading into transitive closure work.
    $declMods = [];
    if (!$isInterface && $isAbstract) $declMods[] = 'abstract';
    if (!$isInterface && $isFinal)    $declMods[] = 'final';

    $declType = $isInterface ? 'interface' : 'class';
    $declLine = trim((empty($declMods) ? '' : implode(' ', $declMods) . ' ')
        . $declType . ' ' . $leaf);

    $body = [];

    foreach ($parsed['fields'] as $f) {
        if (!$f['isPublic']) continue;
        if ($isInterface) {
            // Java interface fields are implicit public static final → const
            $body[] = "    const {$f['name']} = null;";
        } elseif ($f['isStatic']) {
            $body[] = "    public static \${$f['name']} = null;";
        } else {
            $body[] = "    public \${$f['name']} = null;";
        }
    }
    if (!empty($parsed['fields'])) $body[] = '';

    // Dedup overloads by mangled-name. Java overloads collapse to one PHP
    // method (highest arity); per-overload dispatch belongs in the AOT-
    // emitted dispatcher, not in JDK stubs. The stub's job is "method
    // exists when called"; arg-shape matching is the AOT compiler's job.
    $seen = [];
    foreach ($parsed['methods'] as $m) {
        if (str_starts_with($m['name'], 'lambda$')) continue;
        if (str_starts_with($m['name'], 'access$')) continue;
        if (in_array('private', $m['modifiers'], true) && !$m['isConstructor']) continue;

        $methodName = $m['name'];
        if (isset($seen[$methodName])) {
            // Keep the higher-arity version
            if ($seen[$methodName] >= $m['argCount']) continue;
        }
        $seen[$methodName] = $m['argCount'];
    }
    // Re-walk to emit, this time choosing the highest-arity instance.
    $emitted = [];
    foreach ($parsed['methods'] as $m) {
        if (str_starts_with($m['name'], 'lambda$')) continue;
        if (str_starts_with($m['name'], 'access$')) continue;
        if (in_array('private', $m['modifiers'], true) && !$m['isConstructor']) continue;

        $methodName = $m['name'];
        if (!isset($seen[$methodName]) || $seen[$methodName] !== $m['argCount']) {
            continue; // not the chosen overload
        }
        if (isset($emitted[$methodName])) continue;
        $emitted[$methodName] = true;

        $args = [];
        for ($k = 0; $k < $m['argCount']; $k++) {
            $args[] = "\$a{$k} = null";
        }
        $argList = implode(', ', $args);

        $modPhp = ['public'];
        if ($m['isStatic']) $modPhp[] = 'static';

        if ($isInterface) {
            // PHP interfaces: declarations only. Drop static (Java
            // interface static methods don't transfer cleanly — caller
            // dispatches against the implementation class).
            $body[] = "    public function {$methodName}({$argList});";
            continue;
        }

        if ($m['isAbstract']) {
            $body[] = "    abstract " . implode(' ', $modPhp)
                . " function {$methodName}({$argList});";
            $body[] = '';
            continue;
        }

        $body[] = "    " . implode(' ', $modPhp) . " function {$methodName}({$argList})";
        $body[] = "    {";
        $body[] = "        throw new \\PHPJava\\Exceptions\\NotImplementedException(__METHOD__);";
        $body[] = "    }";
        $body[] = '';
    }

    // Trim trailing blank
    while (!empty($body) && end($body) === '') array_pop($body);

    $bodyStr = empty($body) ? '' : "\n" . implode("\n", $body) . "\n";

    return <<<PHP
<?php
declare(strict_types=1);
namespace {$namespace};

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of {$javaFqn}. Regenerate via
 *   php tools/gen-aot-stubs.php {$javaFqn}
 */
{$declLine}
{{$bodyStr}}

PHP;
}

// ── main ─────────────────────────────────────────────────────────────

$dryRun  = false;
$force   = false;
$file    = null;
$classes = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')          $dryRun = true;
    elseif ($arg === '--force')        $force  = true;
    elseif (str_starts_with($arg, '--file=')) $file = substr($arg, 7);
    elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unknown flag: {$arg}\n");
        exit(2);
    }
    else $classes[] = $arg;
}
if ($file !== null) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read --file={$file}\n");
        exit(2);
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $classes[] = $line;
        }
    }
}
if (empty($classes)) {
    fwrite(STDERR, "Usage: php tools/gen-aot-stubs.php [--dry-run] [--force] [--file=PATH] CLASS_FQN…\n");
    exit(2);
}

$generated = 0;
$skipped   = 0;
$failed    = 0;
$reserved  = 0;
foreach ($classes as $cls) {
    $parts = explode('.', $cls);
    $leaf  = end($parts);
    if (isReservedLeaf($leaf)) {
        fwrite(STDERR, sprintf("RESERVED  %s — leaf '%s' is PHP-reserved; skipping\n", $cls, $leaf));
        $reserved++;
        continue;
    }

    $lines = runJavap($cls);
    if ($lines === null) {
        fwrite(STDERR, "JAVAP-FAIL {$cls}\n");
        $failed++;
        continue;
    }
    $parsed = parseJavap($lines);
    if ($parsed['fqn'] === null) {
        fwrite(STDERR, "PARSE-FAIL {$cls} — header didn't match\n");
        $failed++;
        continue;
    }

    $php  = renderStub($parsed, $cls);
    $path = fqnToFile($cls);

    if ($dryRun) {
        echo "=== {$cls} → {$path} ===\n{$php}\n";
        continue;
    }

    if (!$force && file_exists($path)) {
        fwrite(STDERR, "EXISTS    {$cls} ({$path}) — pass --force to overwrite\n");
        $skipped++;
        continue;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($path, $php);
    $generated++;
}

fwrite(STDERR, sprintf(
    "Generated: %d, skipped (exists): %d, reserved-skip: %d, failed: %d\n",
    $generated, $skipped, $reserved, $failed
));
exit($failed > 0 ? 1 : 0);
