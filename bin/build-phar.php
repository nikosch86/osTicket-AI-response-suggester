<?php
// Build a signed .phar from the staged plugin tree produced by `make build`.
// Run via:  php -d phar.readonly=0 bin/build-phar.php <version>

if ($argc < 2) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 bin/build-phar.php <version>\n");
    exit(1);
}

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is On — re-invoke with `php -d phar.readonly=0`\n");
    exit(1);
}

$version    = $argv[1];
$pluginName = 'ai-response-suggester';
$repoRoot   = realpath(__DIR__ . '/..');
$stagedDir  = "$repoRoot/dist/staging/$pluginName";
$pharPath   = "$repoRoot/dist/$pluginName-$version.phar";

if (!is_dir($stagedDir)) {
    fwrite(STDERR, "Expected staged tree at $stagedDir — invoke via `make build` so the tarball staging is in place.\n");
    exit(1);
}

@unlink($pharPath);

$phar = new Phar($pharPath, 0, "$pluginName-$version.phar");
$phar->startBuffering();
$phar->buildFromDirectory($stagedDir);
$phar->setStub(Phar::createDefaultStub('plugin.php'));
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();

printf("Built %s (%d files, signature: %s)\n",
    $pharPath,
    count($phar),
    $phar->getSignature()['hash_type']
);
