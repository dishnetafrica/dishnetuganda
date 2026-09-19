<?php
declare(strict_types=1);
// Zero-dependency autoloader. Dn\Foo\Bar -> src/Foo/Bar.php
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Dn\\', 3) !== 0) { return; }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($path)) { require_once $path; }
});
