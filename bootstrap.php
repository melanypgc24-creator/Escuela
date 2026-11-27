<?php
declare(strict_types=1);

date_default_timezone_set('America/Matamoros');

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

// Devuelve el token CSRF de la sesión y lo crea si no existe, para que ningún
// formulario se imprima con un token vacío que fallaría siempre al validarse.
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
