<?php

/*
 * Builds machine/dist/pingpong-machine.phar and its checksum:
 *
 *   php -d phar.readonly=0 machine/build.php
 *
 * The phar holds the machine agent and the one class it shares with the
 * Laravel agent (Host), with an autoloader of its own: no Composer, no
 * vendor directory, nothing else to install.
 */

$root = dirname(__DIR__);
$dist = __DIR__.'/dist';
$target = "{$dist}/pingpong-machine.phar";

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Run with: php -d phar.readonly=0 machine/build.php\n");
    exit(1);
}

@mkdir($dist, 0755, true);
@unlink($target);

$files = ['bin/pingpong-machine' => __DIR__.'/bin/pingpong-machine', 'src/Host.php' => "{$root}/src/Host.php"];

foreach (glob(__DIR__.'/src/*.php') ?: [] as $file) {
    $files['machine/'.basename($file)] = $file;
}

ksort($files);

$phar = new Phar($target, 0, 'pingpong-machine.phar');
$phar->startBuffering();

foreach ($files as $name => $file) {
    $contents = (string) file_get_contents($file);

    /* The shebang of the script would be printed: the stub has its own. */
    $phar->addFromString($name, $name === 'bin/pingpong-machine' ? (preg_replace('/^#!.*\n/', '', $contents) ?? $contents) : $contents);
}

$phar->setStub(<<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('pingpong-machine.phar');
spl_autoload_register(function (string $class): void {
    $map = [
        'KobaltDigital\\PingPong\\Machine\\' => 'phar://pingpong-machine.phar/machine/',
        'KobaltDigital\\PingPong\\' => 'phar://pingpong-machine.phar/src/',
    ];
    foreach ($map as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
require 'phar://pingpong-machine.phar/bin/pingpong-machine';
__HALT_COMPILER();
STUB);

$phar->stopBuffering();
unset($phar);

chmod($target, 0755);
file_put_contents("{$target}.sha256", hash_file('sha256', $target)."  pingpong-machine.phar\n");

echo 'Built ', $target, ' (', count($files), " files)\n";
