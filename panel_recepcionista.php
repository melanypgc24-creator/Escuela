<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('payments.create');

header('Cache-Control: no-store');

$todayPayments = [];
$totalToday = '0.00';
$debtCount = 0;
$studentsCount = 0;
$error = null;

try {
    $pdo = require __DIR__ . '/config/conexion.php';
    $todayPayments = $pdo->query(
        'SELECT p.id_pago, p.fecha_pago, p.monto, p.metodo_pago, p.nombre_pagador,
                CONCAT(a.nombre, " ", a.apellidos) AS alumno
         FROM pagos p
         LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
         WHERE p.fecha_pago = CURRENT_DATE
           AND (p.estado = "activo" OR p.estado IS NULL)
         ORDER BY p.id_pago DESC
         LIMIT 20'
    )->fetchAll();
    $totalToday = (string) $pdo->query(
        'SELECT COALESCE(SUM(monto), 0)
         FROM pagos
         WHERE fecha_pago = CURRENT_DATE
           AND (estado = "activo" OR estado IS NULL)'
    )->fetchColumn();
    $studentsCount = (int) $pdo->query('SELECT COUNT(*) FROM alumnos WHERE estatus = 1')->fetchColumn();
    $debtCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM cargos_mensuales WHERE estado IN ("pendiente", "vencido")'
    )->fetchColumn();
} catch (\PDOException $exception) {
    error_log('Reception panel failed: ' . $exception->getMessage());
    $error = 'No se pudieron cargar todos los indicadores operativos.';
}

$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de recepción | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="role-dashboard-page">
    <header class="role-dashboard-header">
        <div>
            <span class="admin-eyebrow">TEAM CATS · RECEPCIÓN</span>
            <h1>Panel de secretaría / recepción</h1>
            <p>Operación de caja, alumnos y seguimiento de adeudos.</p>
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
                <span class="admin-eyebrow">RESUMEN OPERATIVO · <?= $escape(date('d/m/Y')) ?></span>
                <h2>Actividad de hoy</h2>
                <p>Accesos rápidos y movimientos recientes de recepción.</p>
            </div>
            <nav class="role-dashboard-actions" aria-label="Acciones de recepción">
                <a href="registrar_pago.php">Registrar pago</a>
                <a href="modulos/alumnos/lista.php">Consultar alumnos</a>
                <a href="modulos/alumnos/crear_al.php">Registrar alumno</a>
            </nav>
        </section>

        <?php if ($error !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p>
        <?php endif; ?>

        <section class="role-dashboard-stats" aria-label="Indicadores del día">
            <article><span>Pagos de hoy</span><strong><?= number_format(count($todayPayments)) ?></strong></article>
            <article><span>Ingresos de hoy</span><strong>$<?= $escape($totalToday) ?></strong></article>
            <article><span>Alumnos activos</span><strong><?= number_format($studentsCount) ?></strong></article>
            <article><span>Cargos pendientes o vencidos</span><strong><?= number_format($debtCount) ?></strong></article>
        </section>

        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading">
                <h2>Últimos pagos de hoy</h2>
                <span class="admin-hint">Hasta 20 movimientos</span>
            </div>
        <?php if ($todayPayments === []): ?>
            <div class="role-dashboard-empty">No hay pagos registrados hoy.</div>
        <?php else: ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>ID</th><th>Alumno</th><th>Monto</th><th>Método</th><th>Pagador</th></tr></thead>
                    <tbody>
                        <?php foreach ($todayPayments as $payment): ?>
                            <tr>
                                <td data-label="ID"><?= $escape($payment['id_pago'] ?? '') ?></td>
                                <td data-label="Alumno"><?= $escape($payment['alumno'] ?? 'Sin alumno') ?></td>
                                <td data-label="Monto">$<?= $escape($payment['monto'] ?? '0.00') ?></td>
                                <td data-label="Método"><?= $escape($payment['metodo_pago'] ?? '') ?></td>
                                <td data-label="Pagador"><?= $escape($payment['nombre_pagador'] ?? '') ?></td>
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
