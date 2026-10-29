<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\AdminModel;

final class AuthController
{
    public function __construct(private AdminModel $admins)
    {
    }

    public function login(): void
    {
        $input = Request::json();
        if ($input === null) {
            JsonResponse::send(['error' => 'El cuerpo debe ser JSON válido.'], 400);
            return;
        }
        $email = trim((string) ($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
            JsonResponse::send(['error' => 'Correo o contraseña inválidos.'], 422);
            return;
        }

        $admin = $this->admins->findByEmail($email);
        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
            JsonResponse::send(['error' => 'Credenciales incorrectas.'], 401);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        JsonResponse::send([
            'data' => ['id' => (int) $admin['id'], 'name' => $admin['name'], 'email' => $admin['email']],
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }

    public function logout(): void
    {
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
        JsonResponse::send(['message' => 'Sesión cerrada.']);
    }
}
