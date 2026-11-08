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
    require_login();

    $allowedRoles = is_array($roles) ? $roles : [$roles];
    try {
        $pdo = require dirname(__DIR__) . '/config/conexion.php';
        $statement = $pdo->prepare(
            'SELECT rol FROM escuela_usuarios WHERE id_usuario = :id_usuario'
        );
        $statement->execute(['id_usuario' => $_SESSION['id_usuario']]);
        $currentRole = $statement->fetchColumn();
    } catch (\PDOException $exception) {
        error_log('Role validation failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('No se pudo validar el acceso. Inténtalo más tarde.');
    }

    if (!is_string($currentRole)) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }

    $_SESSION['rol'] = $currentRole;
    if (!in_array($currentRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta página.');
    }
}
