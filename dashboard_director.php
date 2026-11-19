<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('reports.read');

try {
    $pdo = require __DIR__ . '/config/conexion.php';
    $summary = (new \App\Models\DashboardModel($pdo))->summary();
} catch (\Throwable $exception) {
    error_log('Director dashboard failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudieron cargar los reportes.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = $escape($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de dirección | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <h1>Panel de dirección</h1>
        <p>Consulta de indicadores generales. Este panel no permite modificar registros.</p>
        <dl>
            <dt>Alumnos activos</dt><dd><?= $escape($summary['alumnos_activos']) ?></dd>
            <dt>Tarifas activas</dt><dd><?= $escape($summary['tarifas_activas']) ?></dd>
            <dt>Cargos pendientes</dt><dd><?= $escape($summary['cargos_pendientes']) ?></dd>
            <dt>Pagos activos del mes</dt><dd>$<?= $escape($summary['pagos_activos_mes']) ?></dd>
            <dt>Pagos históricos sin clasificar este mes</dt><dd><?= $escape($summary['pagos_historicos_sin_clasificar_mes']) ?></dd>
        </dl>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </main>
</body>
</html>
