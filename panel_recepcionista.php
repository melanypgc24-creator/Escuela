<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('payments.create');
require __DIR__ . '/includes/caja_pagos.php';
require __DIR__ . '/includes/panel_shell.php';

header('Cache-Control: no-store');

$todayPayments = [];
$references = [];
$totalToday = '0.00';
$debtCount = 0;
$studentsCount = 0;
$students = [];
$debtors = [];
$charges = [];
$error = null;
$cajaError = null;
$success = $_SESSION['flash_pago'] ?? null;
unset($_SESSION['flash_pago']);
$paymentMethods = CAJA_METODOS;
$selected = null;

try {
    $pdo = require __DIR__ . '/config/conexion.php';
    caja_actualizar_vencidos($pdo);

    // El POST de la caja se procesa antes de cargar indicadores para que el panel ya muestre el pago nuevo.
    $cajaError = caja_procesar_pago($pdo, $paymentMethods, 'panel_recepcionista.php#caja');

    $selectedRaw = $_POST['id_alumno'] ?? $_GET['alumno'] ?? null;
    $selected = filter_var($selectedRaw, FILTER_VALIDATE_INT);
    $selected = $selected === false || $selected < 0 ? null : $selected;
    $students = caja_alumnos($pdo);
    $debtors = array_values(array_filter($students, static fn (array $row): bool => (float) $row['saldo'] > 0));
    usort($debtors, static fn (array $a, array $b): int => (float) $b['saldo'] <=> (float) $a['saldo']);
    if ($selected !== null) {
        $charges = caja_cargos_alumno($pdo, $selected);
    }

    $todayPayments = $pdo->query(
        'SELECT p.id_pago, p.folio, p.fecha_pago, p.monto, p.metodo_pago, p.nombre_pagador,
                CONCAT(a.nombre, " ", a.apellidos) AS alumno
         FROM pagos p
         LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
         WHERE p.fecha_pago = CURRENT_DATE
           AND (p.estado = "activo" OR p.estado IS NULL)
         ORDER BY p.id_pago DESC
         LIMIT 20'
    )->fetchAll();
    $references = caja_referencias_pagos($pdo, array_map(static fn (array $row): int => (int) $row['id_pago'], $todayPayments));
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

$csrfToken = csrf_token();

tc_shell_open([
    'title' => 'Panel de secretaría',
    'role' => 'recepcionista',
    'active' => 'resumen',
    'csrf' => $csrfToken,
    'css' => ['css/caja.css'],
]);
?>
        <section class="tc-welcome">
            <div>
                <span class="tc-eyebrow">TEAM CATS · SECRETARÍA</span>
                <h1>Panel de secretaría / recepción</h1>
                <p>Operación de caja, alumnos y seguimiento de adeudos.</p>
            </div>
            <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= tc_e(date('d/m/Y')) ?></div>
        </section>

        <?php if ($error !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= tc_e($error) ?></p>
        <?php endif; ?>

        <section class="tc-metrics tc-metrics-4" aria-label="Indicadores del día">
            <article class="tc-metric">
                <span class="tc-metric-icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                <span class="tc-metric-label">Pagos de hoy</span>
                <span class="tc-metric-value"><?= number_format(count($todayPayments)) ?></span>
            </article>
            <article class="tc-metric">
                <span class="tc-metric-icon"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i></span>
                <span class="tc-metric-label">Ingresos de hoy</span>
                <span class="tc-metric-value">$<?= tc_e(caja_money($totalToday)) ?></span>
            </article>
            <article class="tc-metric">
                <span class="tc-metric-icon"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span>
                <span class="tc-metric-label">Alumnos activos</span>
                <span class="tc-metric-value"><?= number_format($studentsCount) ?></span>
            </article>
            <article class="tc-metric<?= $debtCount > 0 ? ' tc-metric-alert' : '' ?>">
                <span class="tc-metric-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                <span class="tc-metric-label">Cargos pendientes o vencidos</span>
                <span class="tc-metric-value"><?= number_format($debtCount) ?></span>
            </article>
        </section>

        <section class="tc-grid">
            <div class="tc-panel" id="caja">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">CAJA</span>
                        <h2>Registrar pago</h2>
                    </div>
                    <span class="tc-muted">Efectivo, transferencia o tarjeta (simulada)</span>
                </div>
                <?php if ($cajaError !== null): ?>
                    <p class="auth-error admin-message" role="alert"><?= tc_e($cajaError) ?></p>
                <?php elseif (is_string($success)): ?>
                    <p class="auth-success admin-message" role="status"><?= tc_e($success) ?></p>
                <?php endif; ?>
                <?php if ($students === []): ?>
                    <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No hay alumnos activos para cobrar.</span></div>
                <?php else: ?>
                    <?php caja_render_formulario($students, $paymentMethods, $_POST, $csrfToken, 'panel_recepcionista.php#caja', $selected, $charges); ?>
                <?php endif; ?>
            </div>

            <div class="tc-panel">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">ACCESOS RÁPIDOS</span>
                        <h2>Acciones frecuentes</h2>
                    </div>
                </div>
                <div class="tc-actions">
                    <a href="#caja"><i class="fa-solid fa-cash-register" aria-hidden="true"></i><span>Cobrar</span></a>
                    <a href="panel_recepcionista.php?metodo=Transferencia#caja"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><span>Registrar transferencia</span></a>
                    <a href="modulos/alumnos/lista.php"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i><span>Consultar alumnos</span></a>
                    <a href="modulos/alumnos/crear_al.php"><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span>Registrar alumno</span></a>
                </div>
            </div>
        </section>

        <section class="tc-panel" id="adeudos">
            <div class="tc-panel-head">
                <div>
                    <span class="tc-eyebrow">SEGUIMIENTO</span>
                    <h2>Alumnos con adeudo</h2>
                </div>
                <span class="tc-muted">Mayor saldo primero</span>
            </div>
            <?php if ($debtors === []): ?>
                <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No hay alumnos con adeudo pendiente.</span></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Alumno</th><th>Saldo</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($debtors, 0, 15) as $debtor): ?>
                                <tr>
                                    <td data-label="Alumno"><?= tc_e($debtor['apellidos'] . ', ' . $debtor['nombre']) ?></td>
                                    <td data-label="Saldo">$<?= tc_e(caja_money($debtor['saldo'])) ?></td>
                                    <td><a class="caja-link" href="panel_recepcionista.php?alumno=<?= (int) $debtor['id_alumno'] ?>#caja">Cobrar</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="tc-panel" id="pagos-hoy">
            <div class="tc-panel-head">
                <div>
                    <span class="tc-eyebrow">MOVIMIENTOS</span>
                    <h2>Últimos pagos de hoy</h2>
                </div>
                <span class="tc-muted">Hasta 20 movimientos</span>
            </div>
            <?php if ($todayPayments === []): ?>
                <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No hay pagos registrados hoy.</span></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Folio</th><th>Alumno</th><th>Monto</th><th>Método</th><th>Pagador</th></tr></thead>
                        <tbody>
                            <?php foreach ($todayPayments as $payment): ?>
                                <tr>
                                    <td data-label="Folio"><?= tc_e($payment['folio'] ?? $payment['id_pago'] ?? '') ?></td>
                                    <td data-label="Alumno"><?= tc_e($payment['alumno'] ?? 'Sin alumno') ?></td>
                                    <td data-label="Monto">$<?= tc_e(caja_money($payment['monto'] ?? 0)) ?></td>
                                    <td data-label="Método">
                                        <?= tc_e(ucfirst((string) ($payment['metodo_pago'] ?? ''))) ?>
                                        <?php if (isset($references[(int) $payment['id_pago']])): ?>
                                            <span class="tc-ref">Ref. <?= tc_e($references[(int) $payment['id_pago']]) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Pagador"><?= tc_e($payment['nombre_pagador'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
<?php
tc_shell_close();
