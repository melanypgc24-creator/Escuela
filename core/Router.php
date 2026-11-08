<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use Throwable;

final class Router
{
    private array $routes = [];

    public function add(
        string $method,
        string $pattern,
        callable $handler,
        bool $authenticated = true,
        bool $administratorOnly = false
    ): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'authenticated' => $authenticated,
            'administrator_only' => $administratorOnly,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method) || !preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            $params = array_filter($matches, static fn ($key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
            try {
                if ($route['authenticated']) {
                    AuthMiddleware::requireAuthenticated();
                    if ($route['administrator_only']) {
                        AuthMiddleware::requireAdministrator();
                    }
                    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                        AuthMiddleware::requireValidCsrfToken();
                    }
                }
                ($route['handler'])($params);
            } catch (Throwable $exception) {
                error_log((string) $exception);
                JsonResponse::send(['error' => 'Ocurrió un error interno.'], 500);
            }
            return;
        }

        JsonResponse::send(['error' => 'Ruta no encontrada.'], 404);
    }
}
