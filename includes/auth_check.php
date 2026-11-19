<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function require_login(): void
{
    if (empty($_SESSION['id_usuario'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string|array $roles): void
{
    $currentRole = current_authenticated_role();
    $allowedRoles = is_array($roles) ? $roles : [$roles];
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta página.');
    }
}

function require_permission(string $permission): void
{
    $currentRole = current_authenticated_role();
    if (!\App\Core\Permissions::allows($currentRole, $permission)) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta página.');
    }
}

function require_valid_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === ''
        || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('La solicitud no pudo validarse. Recarga la página e inténtalo de nuevo.');
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function current_authenticated_role(): string
{
    require_login();
    try {
        $pdo = require dirname(__DIR__) . '/config/conexion.php';
        $statement = $pdo->prepare(
            'SELECT rol, id_alumno, id_profesor
             FROM usuarios WHERE id_usuario = :id_usuario'
        );
        $statement->execute(['id_usuario' => $_SESSION['id_usuario']]);
        $user = $statement->fetch();
    } catch (\PDOException $exception) {
        error_log('Role validation failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('No se pudo validar el acceso. Inténtalo más tarde.');
    }

    if (!is_array($user) || !is_string($user['rol'] ?? null)) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }

    $_SESSION['rol'] = $user['rol'];
    $_SESSION['id_alumno'] = $user['id_alumno'] === null ? null : (int) $user['id_alumno'];
    $_SESSION['id_profesor'] = $user['id_profesor'] === null ? null : (int) $user['id_profesor'];
    return $user['rol'];
}
