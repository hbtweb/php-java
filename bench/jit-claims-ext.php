<?php
// Extension-overhead battery — bench Java-stdlib-equivalent operations
// against their native-PHP-int counterparts. The question: how much
// slowdown do we pay for each extension we shim through?
//
// Focus on the load-bearing ones — BigInteger (bcmath/gmp), regex
// (pcre), char-level string ops (mbstring), hashing/crypto (hash/
// openssl/sodium).

const N = 200_000;
const WARMUP = 2_000;

function timeit_ext(string $label, callable $body): array {
    for ($i = 0; $i < WARMUP; $i++) $body();
    $start = microtime(true);
    for ($i = 0; $i < N; $i++) $body();
    $ns = (microtime(true) - $start) * 1e9;
    return ['label' => $label, 'ns' => $ns / N];
}

// ── BigInteger arithmetic — for java.math.BigInteger ──────────────
// Test add of two ~10-digit numbers (fits in PHP int on 64-bit, so
// the comparison is cleanly "extension overhead" not "needed for
// correctness").

// PHP int (native — 64-bit signed)
function bigint_php(): int { return 1234567890 + 9876543210; }

// BCMath (string-encoded arbitrary precision)
function bigint_bc(): string { return \bcadd('1234567890', '9876543210'); }

// GMP (object-encoded arbitrary precision)
function bigint_gmp(): \GMP { return \gmp_add('1234567890', '9876543210'); }

// String concat (a documented Java BigInteger fallback)
function bigint_str(): string { return (string)(intval('1234567890') + intval('9876543210')); }

// ── BigInteger multiplication (more JIT-relevant than add) ───────
function bigmul_php(): int { return 12345 * 67890; }
function bigmul_bc(): string { return \bcmul('12345', '67890'); }
function bigmul_gmp(): \GMP { return \gmp_mul('12345', '67890'); }

// ── BigInteger pow (where JVM falls behind big-int libs) ─────────
function bigpow_php(): int|float { return 12345 ** 5; } // overflows int
function bigpow_bc(): string { return \bcpow('12345', '5'); }
function bigpow_gmp(): \GMP { return \gmp_pow('12345', 5); }

// ── String char ops — for Java String operations ─────────────────
$s10 = str_repeat('a', 10);

function strlen_native(): int { global $s10; return strlen($s10); }
function strlen_mb(): int { global $s10; return mb_strlen($s10); }
function substr_native(): string { global $s10; return substr($s10, 2, 5); }
function substr_mb(): string { global $s10; return mb_substr($s10, 2, 5); }
function strpos_native(): int|false { global $s10; return strpos($s10, 'a', 1); }

// ── Regex — for java.util.regex.Pattern ──────────────────────────
$emailLike = "user_123@example.com";
function regex_match(): int|false { global $emailLike; return preg_match('/^[\w]+@\w+\.\w+$/', $emailLike); }
function string_match(): bool { global $emailLike; return strpos($emailLike, '@') !== false; }

// ── Hashing — for java.security.MessageDigest ────────────────────
$digestInput = "the quick brown fox jumps over the lazy dog";
function hash_md5(): string { global $digestInput; return hash('md5', $digestInput); }
function hash_sha256(): string { global $digestInput; return hash('sha256', $digestInput); }
function hash_xxh3(): string { global $digestInput; return hash('xxh3', $digestInput); }
function hash_crc32(): string { global $digestInput; return hash('crc32', $digestInput); }

// ── OpenSSL crypto — for java.crypto.Cipher / Mac ────────────────
$key = str_repeat('k', 32);
$plaintext = "small payload";
function aes_encrypt(): string|false { global $key, $plaintext; return openssl_encrypt($plaintext, 'aes-256-gcm', $key, 0, str_repeat('i', 12), $tag); }
function hmac_sha256(): string { global $key, $plaintext; return hash_hmac('sha256', $plaintext, $key); }

// ── Sodium (modern primitives) — relevant for new crypto code ────
$sodKey = sodium_crypto_generichash_keygen();
function sodium_hash(): string { global $sodKey, $plaintext; return sodium_crypto_generichash($plaintext, $sodKey); }

// ── ZIP/inflate — for java.util.zip ──────────────────────────────
$compressInput = str_repeat("the quick brown fox", 50);
function compress_round(): string|false { global $compressInput; return gzdeflate($compressInput); }

// ── Run battery ─────────────────────────────────────────────────

$tests = [
    // [label, baseline (PHP int), ext variant, ratio-shows]
    ['bigint-add-bc',  fn() => bigint_php(),     fn() => bigint_bc()],
    ['bigint-add-gmp', fn() => bigint_php(),     fn() => bigint_gmp()],
    ['bigint-mul-bc',  fn() => bigmul_php(),     fn() => bigmul_bc()],
    ['bigint-mul-gmp', fn() => bigmul_php(),     fn() => bigmul_gmp()],
    ['bigint-pow-bc',  fn() => bigpow_php(),     fn() => bigpow_bc()],
    ['bigint-pow-gmp', fn() => bigpow_php(),     fn() => bigpow_gmp()],
    ['mbstrlen',       fn() => strlen_native(),  fn() => strlen_mb()],
    ['mbsubstr',       fn() => substr_native(),  fn() => substr_mb()],
    ['regex-match',    fn() => string_match(),   fn() => regex_match()],
    ['hash-md5',       fn() => $emailLike,       fn() => hash_md5()],
    ['hash-sha256',    fn() => $emailLike,       fn() => hash_sha256()],
    ['hash-xxh3',      fn() => $emailLike,       fn() => hash_xxh3()],
    ['hash-crc32',     fn() => $emailLike,       fn() => hash_crc32()],
    ['hmac-sha256',    fn() => $emailLike,       fn() => hmac_sha256()],
    ['aes-256-gcm',    fn() => $emailLike,       fn() => aes_encrypt()],
    ['sodium-blake2b', fn() => $emailLike,       fn() => sodium_hash()],
    ['gzdeflate',      fn() => $emailLike,       fn() => compress_round()],
];

printf("PHP %s, opcache=%s, JIT=%s\n",
    PHP_VERSION,
    ini_get('opcache.enable_cli') ? '1' : '0',
    ini_get('opcache.jit') ?: 'off');
echo str_repeat('-', 80), "\n";
printf("%-18s %14s %14s %12s\n", 'test', 'native (ns)', 'ext (ns)', 'ext/native');
echo str_repeat('-', 80), "\n";

foreach ($tests as [$label, $native, $ext]) {
    $rn = timeit_ext($label . '-N', $native);
    $re = timeit_ext($label . '-X', $ext);
    $ratio = $rn['ns'] > 0 ? $re['ns'] / $rn['ns'] : INF;
    printf("%-18s %12.2f ns %12.2f ns   %8.2fx\n",
        $label, $rn['ns'], $re['ns'], $ratio);
}
