<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $parts = explode('\\', $relativeClass);
    $directory = strtolower(array_shift($parts));
    $file = __DIR__ . '/' . $directory . '/' . implode(DIRECTORY_SEPARATOR, $parts) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/config/session.php';
\App\Config\Session::start();
