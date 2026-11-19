<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Core\Permissions;
use App\Core\JsonResponse;
use PDOException;

final class AuthMiddleware
{
    public static function requireAuthenticated(): void
    {
        if (empty($_SESSION['id_usuario'])) {
            JsonResponse::send(['error' => 'Autenticación requerida.'], 401);
            exit;
        }

        try {
            $statement = Database::connect()->prepare(
                'SELECT rol, id_alumno, id_profesor
                 FROM usuarios WHERE id_usuario = :id_usuario'
            );
            $statement->execute(['id_usuario' => $_SESSION['id_usuario']]);
            $user = $statement->fetch();
        } catch (PDOException $exception) {
            error_log('API role validation failed: ' . $exception->getMessage());
            JsonResponse::send(['error' => 'No se pudo validar el acceso.'], 503);
            exit;
        }

        if (!is_array($user) || !is_string($user['rol'] ?? null)) {
            $_SESSION = [];
            JsonResponse::send(['error' => 'Autenticación requerida.'], 401);
            exit;
        }

        $_SESSION['rol'] = $user['rol'];
        $_SESSION['id_alumno'] = $user['id_alumno'] === null ? null : (int) $user['id_alumno'];
        $_SESSION['id_profesor'] = $user['id_profesor'] === null ? null : (int) $user['id_profesor'];
    }

    public static function requireAdministrator(): void
    {
        if (($_SESSION['rol'] ?? null) !== 'administrador') {
            JsonResponse::send(['error' => 'Permisos insuficientes.'], 403);
            exit;
        }
    }

    public static function requirePermission(string $permission): void
    {
        if (!Permissions::allows((string) ($_SESSION['rol'] ?? ''), $permission)) {
            JsonResponse::send(['error' => 'Permisos insuficientes.'], 403);
            exit;
        }
    }

    public static function requireValidCsrfToken(): void
    {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
            JsonResponse::send(['error' => 'Token CSRF inválido o ausente.'], 403);
            exit;
        }
    }
}
