<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('payments.read');
header('Cache-Control: no-store');
$student = null;
$rawStudent = $_GET['alumno'] ?? '';
$studentId = $rawStudent === '' ? null : filter_var($rawStudent, FILTER_VALIDATE_INT);
$page = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
if ($studentId === false || ($studentId !== null && $studentId < 1) || $page === false || $page < 1 || $page > 1000000) {
    http_response_code(400);
    exit('Parámetros inválidos.');
}
try {
    $pdo = require __DIR__ . '/config/conexion.php';
    if ($studentId !== null) {
        $student = (new \App\Models\StudentModel($pdo))->find($studentId);
        if ($student === null) {
            http_response_code(404);
            exit('Alumno no encontrado.');
        }
        $debts = (new \App\Models\DebtModel($pdo))->allForStudent($studentId);
    }
    $where = $studentId === null ? '' : ' WHERE p.id_alumno = :student OR p.id_alumno_pagador = :payer';
    $params = $studentId === null ? [] : ['student' => $studentId, 'payer' => $studentId];
    $count = $pdo->prepare('SELECT COUNT(*) FROM pagos p' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $query = $pdo->prepare('SELECT p.id_pago, p.folio, p.fecha_pago, p.monto, p.estado, p.concepto,
        CONCAT(a.nombre, " ", a.apellidos) AS alumno FROM pagos p
        LEFT JOIN alumnos a ON a.id_alumno = p.id_alumno' . $where . '
        ORDER BY p.fecha_pago DESC, p.id_pago DESC LIMIT :limit OFFSET :offset');
    foreach ($params as $key => $value) {
        $query->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $query->bindValue(':limit', 25, PDO::PARAM_INT);
    $query->bindValue(':offset', ($page - 1) * 25, PDO::PARAM_INT);
    $query->execute();
    $payments = $query->fetchAll();
} catch (\PDOException $exception) {
    error_log('Payment history failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo consultar el historial de pagos.');
}
$e = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$pageUrl = static fn (int $number): string => '?' . http_build_query(['alumno' => $studentId ?? '', 'pagina' => $number]);
$pages = max(1, (int) ceil($total / 25));
$home = match ($_SESSION['rol']) {
    'director' => 'dashboard_director.php',
    'recepcionista' => 'panel_recepcionista.php',
    default => 'dashboard_admin.php',
};
require __DIR__ . '/includes/panel_shell.php';
tc_shell_open([
    'title' => 'Historial de pagos',
    'role' => $_SESSION['rol'],
    'active' => 'historial',
    'csrf' => csrf_token(),
    'css' => ['css/consultas.css'],
    'breadcrumb' => [['Historial de pagos', null]],
]);
?>
<section class="tc-welcome consultas-welcome">
    <div>
        <span class="tc-eyebrow">PAGOS</span>
        <h1>Historial de pagos</h1>
        <p>Consulta los movimientos y recupera sus comprobantes, incluidos los pagos cancelados.</p>
    </div>
    <div class="consultas-header-actions">
        <?php if ($studentId === null): ?>
            <a class="consultas-button consultas-button-secondary" href="modulos/alumnos/lista.php"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar alumnos</a>
            <a class="admin-cancel" href="<?= $e($home) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al panel</a>
        <?php else: ?>
            <a class="admin-cancel" href="modulos/alumnos/lista.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a alumnos</a>
        <?php endif; ?>
    </div>
</section>

<?php if ($student !== null): ?>
    <section class="tc-panel">
        <div class="tc-panel-head">
            <div><span class="tc-eyebrow">PERFIL DEL ALUMNO</span><h2><?= $e($student['nombre'] . ' ' . $student['apellidos']) ?></h2></div>
            <span class="consultas-count"><?= $e($student['matricula']) ?></span>
        </div>
        <dl class="consultas-profile">
            <div><dt>Teléfono</dt><dd><?= $e($student['telefono']) ?></dd></div>
            <div><dt>Correo de contacto</dt><dd><?= $e($student['correo_contacto'] ?? 'No disponible') ?></dd></div>
            <div><dt>Cinturón</dt><dd><?= $e($student['color_cinturon'] ?? 'Sin asignar') ?></dd></div>
        </dl>
    </section>
    <section class="tc-panel">
        <div class="tc-panel-head"><div><span class="tc-eyebrow">CARGOS</span><h2>Adeudos</h2></div></div>
        <?php if ($debts === []): ?>
            <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>Sin cargos pendientes.</span></div>
        <?php else: ?>
            <ul class="consultas-debts"><?php foreach ($debts as $debt): ?>
                <li><span><strong><?= $e($debt['concepto']) ?></strong><small>Periodo <?= $e($debt['periodo']) ?></small></span><strong>Saldo $<?= $e(number_format((float) $debt['saldo_pendiente'], 2)) ?></strong></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="tc-panel">
    <div class="tc-panel-head consultas-results-head">
        <div><span class="tc-eyebrow">MOVIMIENTOS</span><h2>Pagos registrados</h2></div>
        <span class="consultas-count"><?= number_format($total) ?> <?= $total === 1 ? 'pago' : 'pagos' ?></span>
    </div>
    <?php if ($payments === []): ?>
        <div class="tc-empty"><i class="fa-regular fa-file-lines" aria-hidden="true"></i><span>No hay pagos para mostrar.</span></div>
    <?php else: ?>
        <div class="admin-table-wrap"><table class="admin-table consultas-table">
            <thead><tr><th>Folio</th><th>Fecha</th><th>Alumno</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>Comprobante</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td data-label="Folio"><strong><?= $e($payment['folio'] ?: 'Sin folio') ?></strong></td>
                    <td data-label="Fecha"><?= $e($payment['fecha_pago']) ?></td>
                    <td data-label="Alumno"><?= $e($payment['alumno'] ?? 'No disponible') ?></td>
                    <td data-label="Concepto"><?= $e($payment['concepto']) ?></td>
                    <td data-label="Monto">$<?= $e(number_format((float) $payment['monto'], 2)) ?></td>
                    <td data-label="Estado"><span class="consultas-badge consultas-badge-<?= $payment['estado'] === 'activo' ? 'active' : ($payment['estado'] === 'cancelado' ? 'inactive' : 'neutral') ?>"><?= $e(ucfirst((string) ($payment['estado'] ?? 'Sin estado'))) ?></span></td>
                    <td data-label="Comprobante"><div class="consultas-row-actions"><a href="comprobante.php?id=<?= (int) $payment['id_pago'] ?>">Ver comprobante</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
    <nav class="consultas-pagination" aria-label="Paginación de pagos">
        <?php if ($page > 1): ?><a href="<?= $e($pageUrl($page - 1)) ?>"><i class="fa-solid fa-angle-left" aria-hidden="true"></i> Anterior</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= $pages ?></span>
        <?php if ($page < $pages): ?><a href="<?= $e($pageUrl($page + 1)) ?>">Siguiente <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a><?php endif; ?>
    </nav>
</section>
<?php tc_shell_close(); ?>
