<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Models\AdminModel;
use PDOException;
use RuntimeException;

final class WebAuthController
{
    public function login(): void
    {
        if (!empty($_SESSION['admin_id'])) {
            $this->redirect('dashboard.php');
        }

        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $csrfToken = $_POST['csrf_token'] ?? '';
            $expectedToken = $_SESSION['csrf_token'] ?? '';
            if (!is_string($csrfToken) || !is_string($expectedToken) || !hash_equals($expectedToken, $csrfToken)) {
                http_response_code(403);
                $error = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
            } else {
                $email = $_POST['email'] ?? '';
                $password = $_POST['password'] ?? '';

                if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
                    $error = 'Escribe un correo válido y tu contraseña.';
                } else {
                    $email = trim($email);
                    try {
                        $admin = (new AdminModel(Database::connect()))->findByEmail($email);
                        if ($admin === null || !password_verify($password, $admin['password_hash'])) {
                            $error = 'Correo o contraseña incorrectos.';
                        } else {
                            session_regenerate_id(true);
                            $_SESSION['admin_id'] = (int) $admin['id'];
                            $_SESSION['admin_name'] = $admin['name'];
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            $this->redirect('dashboard.php');
                        }
                    } catch (RuntimeException | PDOException $exception) {
                        error_log('Web login failed: ' . $exception->getMessage());
                        http_response_code(503);
                        $error = 'No se pudo validar el acceso. Verifica que la base de datos esté configurada.';
                    }
                }
            }
        }

        require dirname(__DIR__) . '/views/auth/login.php';
    }

    public function dashboard(): void
    {
        if (empty($_SESSION['admin_id'])) {
            $this->redirect('login.php');
        }

        require dirname(__DIR__) . '/views/dashboard.php';
    }

    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Método no permitido.');
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        $expectedToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($csrfToken) || !is_string($expectedToken) || $expectedToken === '' || !hash_equals($expectedToken, $csrfToken)) {
            http_response_code(403);
            exit('La solicitud no pudo validarse. Recarga la página e inténtalo de nuevo.');
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
        $this->redirect('login.php');
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location);
        exit;
    }
}
