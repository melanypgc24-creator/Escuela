<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store');

try {
    require __DIR__ . '/routes/api.php';
} catch (Throwable $exception) {
    error_log((string) $exception);
    \App\Core\JsonResponse::send(['error' => 'Ocurrió un error interno.'], 500);
}
