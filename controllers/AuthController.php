<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Models\UserModel;

final class AuthController
{
    public function __construct(private UserModel $users)
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

        $user = $this->users->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password'])) {
            JsonResponse::send(['error' => 'Credenciales incorrectas.'], 401);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['id_usuario'] = (int) $user['id_usuario'];
        $_SESSION['correo'] = $user['correo'];
        $_SESSION['rol'] = $user['rol'];
        $_SESSION['id_alumno'] = $user['id_alumno'] === null ? null : (int) $user['id_alumno'];
        $_SESSION['id_profesor'] = $user['id_profesor'] === null ? null : (int) $user['id_profesor'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        JsonResponse::send([
            'data' => [
                'id_usuario' => (int) $user['id_usuario'],
                'correo' => $user['correo'],
                'rol' => $user['rol'],
            ],
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
