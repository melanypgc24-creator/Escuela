<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\JsonResponse;

final class AuthMiddleware
{
    public static function requireAuthenticated(): void
    {
        if (empty($_SESSION['admin_id'])) {
            JsonResponse::send(['error' => 'Autenticación requerida.'], 401);
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
