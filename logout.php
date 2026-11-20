<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$providedToken = $_POST['csrf_token'] ?? '';
$expectedToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($providedToken) || !is_string($expectedToken) || $expectedToken === ''
    || !hash_equals($expectedToken, $providedToken)) {
    // La sesión sigue intacta: no se cierra con un token inválido, pero se
    // devuelve al usuario a una página que imprime el token vigente.
    error_log('Logout CSRF mismatch (session ' . (empty($_SESSION['id_usuario']) ? 'sin usuario' : 'activa') . ').');
    header('Cache-Control: no-store');
    header('Location: ' . (empty($_SESSION['id_usuario']) ? 'login.php' : 'dashboard.php'));
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
