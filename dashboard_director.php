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

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$csrfToken = $escape(csrf_token());
$correoUsuario = (string) ($_SESSION['correo'] ?? 'Dirección');
$rolActual = (string) ($_SESSION['rol'] ?? '');
$puedeCrearClases = in_array($rolActual, ['administrador', 'director'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de dirección | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css?v=<?= (int) filemtime(__DIR__ . '/css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="dashboard_director.php">TEAM CATS</a>
        <div class="tc-topbar-user">
            <span class="tc-topbar-role"><?= $escape($correoUsuario) ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="tc-logout">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <aside class="tc-sidebar" id="tc-sidebar" aria-label="Menú de dirección">
        <a class="tc-sidebar-logo" href="dashboard_director.php">
            <img src="imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <ul class="tc-menu" aria-label="Secciones de dirección">
            <li>
                <a class="tc-link is-active" href="#resumen" aria-current="page">
                    <i class="fa-solid fa-chart-line" aria-hidden="true"></i>Resumen
                </a>
            </li>
            <li>
                <a class="tc-link" href="#reporte-financiero">
                    <i class="fa-solid fa-sack-dollar" aria-hidden="true"></i>Reporte financiero
                </a>
            </li>
            <li>
                <a class="tc-link" href="#proximos-vencimientos">
                    <i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i>Vencimientos
                </a>
            </li>
            <li>
                <a class="tc-link" href="#adeudos-principales">
                    <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Adeudos
                </a>
            </li>
            <li>
                <a class="tc-link" href="modulos/alumnos/lista.php"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i><span>Buscar alumnos</span></a>
            </li>
            <li>
                <a class="tc-link" href="historial_pagos.php"><i class="fa-solid fa-receipt" aria-hidden="true"></i><span>Historial de pagos</span></a>
            </li>
            <li>
                <a class="tc-link" href="modulos/clases/clases.php">
                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Gestionar clases
                </a>
            </li>
        </ul>
    </aside>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content" id="resumen">
            <p class="tc-breadcrumb">
                <a href="dashboard_director.php">Dirección</a>
                <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span>Panel de dirección</span>
            </p>

            <section class="tc-welcome">
                <div>
                    <span class="tc-eyebrow">TEAM CATS · DIRECCIÓN</span>
                    <h1>Panel de dirección</h1>
                    <p>Indicadores generales, creación y administración de clases y reportes financieros.</p>
                </div>
                <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= $escape(date('d/m/Y')) ?></div>
            </section>

            <?php foreach ($warnings as $warning): ?>
                <p class="auth-error admin-message" role="alert"><?= $escape($warning) ?></p>
            <?php endforeach; ?>

            <section class="tc-metrics tc-metrics-4" aria-label="Indicadores generales">
                <article class="tc-metric">
                    <span class="tc-metric-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                    <span class="tc-metric-label">Alumnos inscritos</span>
                    <span class="tc-metric-value"><?= number_format($studentsTotal) ?></span>
                </article>
                <article class="tc-metric">
                    <span class="tc-metric-icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                    <span class="tc-metric-label">Alumnos activos</span>
                    <span class="tc-metric-value"><?= number_format((int) $summary['alumnos_activos']) ?></span>
                </article>
                <article class="tc-metric">
                    <span class="tc-metric-icon"><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i></span>
                    <span class="tc-metric-label">Pagos registrados hoy</span>
                    <span class="tc-metric-value"><?= number_format($paymentsToday) ?></span>
                </article>
                <article class="tc-metric<?= (int) $summary['cargos_pendientes'] > 0 ? ' tc-metric-alert' : '' ?>">
                    <span class="tc-metric-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span>
                    <span class="tc-metric-label">Adeudos pendientes o vencidos</span>
                    <span class="tc-metric-value"><?= number_format((int) $summary['cargos_pendientes']) ?></span>
                </article>
            </section>

            <section class="tc-panel" id="reporte-financiero">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">FINANZAS</span>
                        <h2>Reporte financiero (RF-08)</h2>
                    </div>
                    <span class="tc-muted">Tarifas activas: <?= number_format((int) $summary['tarifas_activas']) ?></span>
                </div>
                <div class="tc-metrics tc-metrics-4" aria-label="Ingresos por periodo">
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-coins" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Ingreso diario</span>
                        <span class="tc-metric-value tc-metric-text">$<?= $escape($income['diario']) ?></span>
                    </article>
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Ingreso semanal</span>
                        <span class="tc-metric-value tc-metric-text">$<?= $escape($income['semanal']) ?></span>
                    </article>
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-calendar" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Ingreso mensual</span>
                        <span class="tc-metric-value tc-metric-text">$<?= $escape($income['mensual']) ?></span>
                    </article>
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Ingreso anual</span>
                        <span class="tc-metric-value tc-metric-text">$<?= $escape($income['anual']) ?></span>
                    </article>
                </div>
                <p class="tc-muted" style="margin-top: 14px;">
                    Pagos históricos sin clasificar este mes: <?= number_format((int) $summary['pagos_historicos_sin_clasificar_mes']) ?>
                </p>
            </section>

            <section class="tc-panel" id="proximos-vencimientos">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">COBRANZA</span>
                        <h2>Próximos vencimientos · 7 días</h2>
                    </div>
                </div>
                <?php if ($nextDue === []): ?>
                    <div class="tc-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span>No hay vencimientos próximos registrados.</span></div>
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

            <section class="tc-panel" id="adeudos-principales">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">CARTERA</span>
                        <h2>Adeudos principales</h2>
                    </div>
                </div>
                <?php if ($topDebts === []): ?>
                    <div class="tc-empty tc-empty-ok"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No hay adeudos pendientes.</span></div>
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

            <section class="tc-panel" id="clases">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">ACADEMIA</span>
                        <h2>Clases</h2>
                    </div>
                </div>
                <p class="tc-muted">Crear y gestionar clases.</p>
                <div class="tc-actions" style="margin-top: 14px;">
                    <a href="modulos/clases/clases.php">
                        <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>Ver clases
                    </a>
                    <?php if ($puedeCrearClases): ?>
                        <a href="modulos/clases/crear_cl.php?origen=panel">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>Crear clase
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <footer class="tc-footer">Team Cats · Escuela de Jiu-Jitsu</footer>
    </div>
    <script src="js/panel-shell.js?v=<?= (int) filemtime(__DIR__ . '/js/panel-shell.js') ?>"></script>
</body>
</html>