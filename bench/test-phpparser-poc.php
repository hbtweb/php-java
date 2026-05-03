<?php
// Alternative-to-IR proof-of-concept: use nikic/php-parser to
// manipulate our emitted PHP at the AST level, instead of a custom
// IR. Shows what an opt looks like via this approach.
//
// Test: take a slightly-suboptimal emit and apply a transform that
// would be hard at the string layer but trivial at AST level —
// constant-fold `$L[N] = K1 + K2;` into `$L[N] = K3;`.

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$source = <<<'PHP'
<?php
namespace PHPJava\Aot\Generated;
final class Demo {
    public static function compute() {
        $L = [0, 0];
        $stack = []; $sp = 0;
        $L[0] = 7 + 13;
        $L[1] = 5 * 4;
        $L[0] = $L[0] + $L[1];
        return $L[0];
    }
}
PHP;

$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$ast = $parser->parse($source);

// Visitor: fold BinaryOp where both sides are scalar literals.
$visitor = new class extends NodeVisitorAbstract {
    public int $folded = 0;
    public function leaveNode(Node $node) {
        if ($node instanceof Node\Expr\BinaryOp\Plus
            && $node->left instanceof Node\Scalar\LNumber
            && $node->right instanceof Node\Scalar\LNumber) {
            $this->folded++;
            return new Node\Scalar\LNumber($node->left->value + $node->right->value);
        }
        if ($node instanceof Node\Expr\BinaryOp\Mul
            && $node->left instanceof Node\Scalar\LNumber
            && $node->right instanceof Node\Scalar\LNumber) {
            $this->folded++;
            return new Node\Scalar\LNumber($node->left->value * $node->right->value);
        }
        return null;
    }
};

$traverser = new NodeTraverser();
$traverser->addVisitor($visitor);
$ast = $traverser->traverse($ast);

$printer = new Standard();
$out = $printer->prettyPrintFile($ast);

echo "=== Input ===\n";
echo $source;
echo "\n=== Output (after constant-fold pass via PhpParser AST) ===\n";
echo $out;
echo "\n=== Stats ===\n";
echo "Folded {$visitor->folded} binary-op-of-literals\n";

// Sanity: run the result and check it returns the expected value.
$tmp = sys_get_temp_dir() . '/PhpParserDemo.php';
file_put_contents($tmp, $out);
require_once $tmp;
$result = \PHPJava\Aot\Generated\Demo::compute();
$expected = (7 + 13) + (5 * 4); // 40
echo "compute() = {$result}  (expected {$expected})\n";
echo $result === $expected ? "PASS\n" : "FAIL\n";
exit($result === $expected ? 0 : 1);
