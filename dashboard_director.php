<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('reports.read');

header('Cache-Control: no-store');

/** @var array<string, string|int> $summary */
$summary = [
    'alumnos_activos' => 0,
    'tarifas_activas' => 0,
    'cargos_pendientes' => 0,
    'pagos_activos_mes' => '0.00',
    'pagos_historicos_sin_clasificar_mes' => 0,
];
$income = ['diario' => '0.00', 'semanal' => '0.00', 'mensual' => '0.00', 'anual' => '0.00'];
$studentsTotal = 0;
$paymentsToday = 0;
$nextDue = [];
$topDebts = [];
$warnings = [];

// Si el esquema de cobranza aún no está migrado (tabla o columna inexistente) no es un fallo: los indicadores quedan en cero.
$schemaPending = static function (\PDOException $exception): bool {
    return in_array($exception->errorInfo[1] ?? null, [1146, 1054], true);
};
$warn = static function (\PDOException $exception, string $message) use (&$warnings, $schemaPending): void {
    error_log('Director dashboard: ' . $exception->getMessage());
    if (!$schemaPending($exception)) {
        $warnings[] = $message;
    }
};

try {
    $pdo = require __DIR__ . '/config/conexion.php';
    try {
        $summary = (new \App\Models\DashboardModel($pdo))->summary();
    } catch (\PDOException $exception) {
        $warn($exception, 'No se pudieron cargar todos los indicadores base del dashboard.');
    }
    try {
        $studentsTotal = (int) $pdo->query('SELECT COUNT(*) FROM alumnos')->fetchColumn();
    } catch (\PDOException $exception) {
        $warn($exception, 'No se pudo cargar el total de alumnos.');
    }
    try {
        $income = [
            'diario' => (string) $pdo->query(
            'SELECT COALESCE(SUM(monto), 0) FROM pagos
             WHERE (estado = "activo" OR estado IS NULL) AND fecha_pago = CURRENT_DATE'
            )->fetchColumn(),
            'semanal' => (string) $pdo->query(
            'SELECT COALESCE(SUM(monto), 0) FROM pagos
             WHERE (estado = "activo" OR estado IS NULL)
               AND fecha_pago >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)'
            )->fetchColumn(),
            'mensual' => (string) $pdo->query(
            'SELECT COALESCE(SUM(monto), 0) FROM pagos
             WHERE (estado = "activo" OR estado IS NULL)
               AND fecha_pago >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
               AND fecha_pago < DATE_ADD(DATE_FORMAT(CURRENT_DATE, "%Y-%m-01"), INTERVAL 1 MONTH)'
            )->fetchColumn(),
            'anual' => (string) $pdo->query(
            'SELECT COALESCE(SUM(monto), 0) FROM pagos
             WHERE (estado = "activo" OR estado IS NULL)
               AND fecha_pago >= DATE_FORMAT(CURRENT_DATE, "%Y-01-01")
               AND fecha_pago < DATE_ADD(DATE_FORMAT(CURRENT_DATE, "%Y-01-01"), INTERVAL 1 YEAR)'
            )->fetchColumn(),
        ];
        $paymentsToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM pagos WHERE (estado = "activo" OR estado IS NULL) AND fecha_pago = CURRENT_DATE'
        )->fetchColumn();
    } catch (\PDOException $exception) {
        $warn($exception, 'No se pudo cargar el reporte financiero por periodos.');
    }
    try {
        $nextDue = $pdo->query(
        'SELECT cm.periodo, cm.fecha_vencimiento, cm.importe, CONCAT(a.nombre, " ", a.apellidos) AS alumno
         FROM cargos_mensuales cm
         JOIN alumnos a ON a.id_alumno = cm.id_alumno
         WHERE cm.estado IN ("pendiente", "vencido")
           AND cm.fecha_vencimiento BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)
         ORDER BY cm.fecha_vencimiento, cm.id_cargo
         LIMIT 10'
        )->fetchAll();
        $topDebts = array_slice((new \App\Models\DebtModel($pdo))->all(), 0, 10);
    } catch (\PDOException $exception) {
        $warn($exception, 'No se pudieron cargar los vencimientos y adeudos.');
    }
} catch (\Throwable $exception) {
    error_log('Director dashboard failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudieron cargar los reportes.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = $escape(csrf_token());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de dirección | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="role-dashboard-page">
    <header class="role-dashboard-header">
        <div>
            <span class="admin-eyebrow">TEAM CATS · DIRECCIÓN</span>
            <h1>Panel de dirección</h1>
            <p>Indicadores generales y reportes financieros.</p>
        </div>
        <div class="admin-account">
            <span><?= $escape($_SESSION['correo'] ?? '') ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="admin-logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="role-dashboard-content">
        <section class="role-dashboard-welcome">
            <div>
                <span class="admin-eyebrow">RESUMEN · <?= $escape(date('d/m/Y')) ?></span>
                <h2>Vista general de la escuela</h2>
                <p>Consulta de indicadores generales. Este panel no permite modificar registros.</p>
            </div>
        </section>

        <?php foreach ($warnings as $warning): ?>
            <p class="auth-error admin-message" role="alert"><?= $escape($warning) ?></p>
        <?php endforeach; ?>

        <section class="role-dashboard-stats" aria-label="Indicadores generales">
            <article><span>Alumnos inscritos</span><strong><?= number_format($studentsTotal) ?></strong></article>
            <article><span>Alumnos activos</span><strong><?= number_format((int) $summary['alumnos_activos']) ?></strong></article>
            <article><span>Pagos registrados hoy</span><strong><?= number_format($paymentsToday) ?></strong></article>
            <article><span>Adeudos pendientes o vencidos</span><strong><?= number_format((int) $summary['cargos_pendientes']) ?></strong></article>
        </section>

        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading">
                <h2>Reporte financiero (RF-08)</h2>
                <span class="admin-hint">Tarifas activas: <?= number_format((int) $summary['tarifas_activas']) ?></span>
            </div>
            <div class="role-dashboard-section-body">
                <div class="role-dashboard-stats" aria-label="Ingresos por periodo">
                    <article><span>Ingreso diario</span><strong>$<?= $escape($income['diario']) ?></strong></article>
                    <article><span>Ingreso semanal</span><strong>$<?= $escape($income['semanal']) ?></strong></article>
                    <article><span>Ingreso mensual</span><strong>$<?= $escape($income['mensual']) ?></strong></article>
                    <article><span>Ingreso anual</span><strong>$<?= $escape($income['anual']) ?></strong></article>
                </div>
                <p class="admin-hint">Pagos históricos sin clasificar este mes: <?= number_format((int) $summary['pagos_historicos_sin_clasificar_mes']) ?></p>
            </div>
        </section>

        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading"><h2>Próximos vencimientos · 7 días</h2></div>
            <?php if ($nextDue === []): ?>
                <div class="role-dashboard-empty">No hay vencimientos próximos registrados.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Alumno</th><th>Periodo</th><th>Vencimiento</th><th>Importe</th></tr></thead>
                        <tbody>
                            <?php foreach ($nextDue as $row): ?>
                                <tr>
                                    <td data-label="Alumno"><?= $escape($row['alumno'] ?? '') ?></td>
                                    <td data-label="Periodo"><?= $escape($row['periodo'] ?? '') ?></td>
                                    <td data-label="Vencimiento"><?= $escape($row['fecha_vencimiento'] ?? '') ?></td>
                                    <td data-label="Importe">$<?= $escape($row['importe'] ?? '0.00') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading"><h2>Adeudos principales</h2></div>
            <?php if ($topDebts === []): ?>
                <div class="role-dashboard-empty">No hay adeudos pendientes.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Alumno</th><th>Periodo</th><th>Saldo pendiente</th><th>Estado</th></tr></thead>
                        <tbody>
                            <?php foreach ($topDebts as $debt): ?>
                                <tr>
                                    <td data-label="Alumno"><?= $escape($debt['alumno'] ?? '') ?></td>
                                    <td data-label="Periodo"><?= $escape($debt['periodo'] ?? '') ?></td>
                                    <td data-label="Saldo pendiente">$<?= $escape($debt['saldo_pendiente'] ?? '0.00') ?></td>
                                    <td data-label="Estado"><?= $escape(ucfirst((string) ($debt['estado'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
