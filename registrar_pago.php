<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('payments.create');
require __DIR__ . '/includes/caja_pagos.php';
require __DIR__ . '/includes/panel_shell.php';

header('Cache-Control: no-store');

$role = ($_SESSION['rol'] ?? '') === 'recepcionista' ? 'recepcionista' : 'administrador';
$success = $_SESSION['flash_pago'] ?? null;
unset($_SESSION['flash_pago']);
$csrfToken = csrf_token();
$paymentMethods = CAJA_METODOS;

try {
    $pdo = require __DIR__ . '/config/conexion.php';
} catch (\PDOException $exception) {
    error_log('Payment connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos.');
}

caja_actualizar_vencidos($pdo);
$error = caja_procesar_pago($pdo, $paymentMethods, 'registrar_pago.php');

try {
    $students = caja_alumnos($pdo);
} catch (\PDOException $exception) {
    error_log('Active students query failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo cargar la lista de alumnos.');
}

$selectedRaw = $_POST['id_alumno'] ?? $_GET['alumno'] ?? null;
$selected = filter_var($selectedRaw, FILTER_VALIDATE_INT);
$selected = $selected === false || $selected < 0 ? null : $selected;
$charges = $selected !== null ? caja_cargos_alumno($pdo, $selected) : [];

$recentPayments = [];
try {
    $recentPayments = $pdo->query(
        'SELECT p.id_pago, p.folio, p.fecha_pago, p.monto, p.metodo_pago, p.nombre_pagador,
                CONCAT(a.nombre, " ", a.apellidos) AS alumno
         FROM pagos p
         LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno
         WHERE (p.estado = "activo" OR p.estado IS NULL)
         ORDER BY p.id_pago DESC
         LIMIT 15'
    )->fetchAll();
} catch (\PDOException $exception) {
    error_log('Recent payments query failed: ' . $exception->getMessage());
}
$references = caja_referencias_pagos($pdo, array_map(static fn (array $row): int => (int) $row['id_pago'], $recentPayments));

tc_shell_open([
    'title' => 'Cobros y transferencias',
    'role' => $role,
    'active' => $role === 'recepcionista' ? 'cobrar' : 'cobros',
    'csrf' => $csrfToken,
    'css' => ['css/caja.css'],
    'breadcrumb' => [['Cobros y transferencias', null]],
]);
?>
        <section class="tc-page-head">
            <div>
                <span class="tc-eyebrow">CAJA</span>
                <h1>Cobros y transferencias</h1>
                <p>Registra pagos en efectivo, por transferencia o con tarjeta (simulada). Se aplican primero al cargo más antiguo del alumno.</p>
            </div>
        </section>

        <section class="tc-panel" id="caja">
            <div class="tc-panel-head">
                <div>
                    <span class="tc-eyebrow">REGISTRAR PAGO</span>
                    <h2>Caja</h2>
                </div>
            </div>
            <?php if ($error !== null): ?>
                <p class="auth-error admin-message" role="alert"><?= tc_e($error) ?></p>
            <?php elseif (is_string($success)): ?>
                <p class="auth-success admin-message" role="status"><?= tc_e($success) ?></p>
            <?php endif; ?>
            <?php if ($students === []): ?>
                <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No hay alumnos activos disponibles para registrar pagos.</span></div>
            <?php else: ?>
                <?php caja_render_formulario($students, $paymentMethods, $_POST, $csrfToken, 'registrar_pago.php', $selected, $charges); ?>
            <?php endif; ?>
        </section>

        <section class="tc-panel">
            <div class="tc-panel-head">
                <div>
                    <span class="tc-eyebrow">MOVIMIENTOS</span>
                    <h2>Últimos pagos registrados</h2>
                </div>
                <span class="tc-muted">Hasta 15</span>
            </div>
            <?php if ($recentPayments === []): ?>
                <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>Aún no hay pagos registrados.</span></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Folio</th><th>Fecha</th><th>Alumno</th><th>Monto</th><th>Método</th><th>Pagador</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentPayments as $payment): ?>
                                <tr>
                                    <td data-label="Folio"><?= tc_e($payment['folio'] ?? $payment['id_pago']) ?></td>
                                    <td data-label="Fecha"><?= tc_e(date('d/m/Y', (int) strtotime((string) $payment['fecha_pago']))) ?></td>
                                    <td data-label="Alumno"><?= tc_e($payment['alumno'] ?? 'Sin alumno') ?></td>
                                    <td data-label="Monto">$<?= tc_e(caja_money($payment['monto'])) ?></td>
                                    <td data-label="Método">
                                        <?= tc_e(ucfirst((string) $payment['metodo_pago'])) ?>
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
