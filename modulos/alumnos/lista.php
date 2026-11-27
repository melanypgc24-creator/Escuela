<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
$role = current_authenticated_role();
$permissions = \App\Core\Permissions::class;
$assignedOnly = !$permissions::allows($role, 'students.read');
if ($assignedOnly) {
    require_permission('students.read_assigned');
}
$professorId = $assignedOnly ? ($_SESSION['id_profesor'] ?? null) : null;
if ($assignedOnly && !is_int($professorId)) {
    http_response_code(403);
    exit('Tu cuenta no está vinculada a un profesor.');
}

$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
try {
    [$filters, $page, $perPage] = \App\Controllers\StudentController::searchParameters($_GET, $assignedOnly);
    $result = (new \App\Models\StudentModel($pdo))->all($filters, $page, $perPage, $professorId);
    $belts = $pdo->query('SELECT id_cinturon, color FROM cinturon ORDER BY color')->fetchAll();
    $classModel = new \App\Models\ClassModel($pdo);
    $classes = $assignedOnly ? $classModel->allForProfessor($professorId) : $classModel->all();
} catch (\InvalidArgumentException $exception) {
    http_response_code(422);
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
} catch (\PDOException $exception) {
    error_log('Student search failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo consultar la lista de alumnos.');
}
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$selected = static fn (string $name, mixed $value): string => (string) ($_GET[$name] ?? '') === (string) $value ? ' selected' : '';
$canWrite = $permissions::allows($role, 'students.write');
$canCreate = $permissions::allows($role, 'students.create');
$canAssignBelt = $permissions::allows($role, 'students.belt_assigned');
$canPay = $permissions::allows($role, 'payments.create');
$beltMessage = $_SESSION['flash_cinturon'] ?? null;
unset($_SESSION['flash_cinturon']);
$home = match ($role) {
    'director' => '../../dashboard_director.php',
    'recepcionista' => '../../panel_recepcionista.php',
    'maestro' => '../../panel_maestro.php',
    default => '../../dashboard_admin.php',
};
$totalPages = max(1, (int) ceil($result['pagination']['total'] / $perPage));
$pageUrl = static function (int $number): string {
    return '?' . http_build_query(array_merge($_GET, ['pagina' => $number]));
};
require dirname(__DIR__, 2) . '/includes/panel_shell.php';
tc_shell_open([
    'title' => $assignedOnly ? 'Alumnos asignados' : 'Consulta de alumnos',
    'role' => $role,
    'active' => 'alumnos',
    'csrf' => csrf_token(),
    'base' => '../../',
    'css' => ['css/consultas.css'],
    'breadcrumb' => [['Alumnos', null]],
]);
?>
<section class="tc-welcome consultas-welcome">
    <div>
        <span class="tc-eyebrow">ALUMNOS</span>
        <h1><?= $assignedOnly ? 'Alumnos asignados' : 'Consulta de alumnos' ?></h1>
        <p><?= $assignedOnly ? 'Encuentra alumnos de tus clases y consulta su información.' : 'Encuentra alumnos y consulta su información, pagos y adeudos.' ?></p>
    </div>
    <div class="consultas-header-actions">
        <?php if ($canCreate): ?><a class="consultas-button consultas-button-primary" href="crear_al.php"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Agregar alumno</a><?php endif; ?>
        <a class="admin-cancel" href="<?= $escape($home) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al panel</a>
    </div>
</section>

<?php if (is_string($beltMessage)): ?><p class="auth-success admin-message" role="status"><?= $escape($beltMessage) ?></p><?php endif; ?>

<section class="tc-panel">
    <div class="tc-panel-head">
        <div><span class="tc-eyebrow">BÚSQUEDA AVANZADA</span><h2>Buscar alumnos</h2></div>
    </div>
    <form method="get" class="consultas-form">
        <label class="consultas-search">Buscar por nombre, matrícula<?= $assignedOnly ? '' : ', correo' ?>, teléfono o cinturón
            <input type="search" name="q" maxlength="100" value="<?= $escape($_GET['q'] ?? '') ?>" placeholder="Escribe un dato del alumno">
        </label>
        <label>Estado del alumno
            <select name="estatus"><option value="">Todos</option><option value="activo"<?= $selected('estatus', 'activo') ?>>Activo</option><option value="inactivo"<?= $selected('estatus', 'inactivo') ?>>Inactivo</option></select>
        </label>
        <label>Categoría para mensualidades
            <select name="categoria"><option value="">Todas</option><option value="infantil"<?= $selected('categoria', 'infantil') ?>>Infantil</option><option value="adulta"<?= $selected('categoria', 'adulta') ?>>Adulta</option></select>
        </label>
        <label>Cinturón
            <select name="id_cinturon"><option value="">Todos</option><?php foreach ($belts as $belt): ?><option value="<?= $escape($belt['id_cinturon']) ?>"<?= $selected('id_cinturon', $belt['id_cinturon']) ?>><?= $escape($belt['color']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Clase
            <select name="id_clase"><option value="">Todas</option><?php foreach ($classes as $class): ?><option value="<?= $escape($class['id_clase']) ?>"<?= $selected('id_clase', $class['id_clase']) ?>><?= $escape($class['nombre_clase']) ?></option><?php endforeach; ?></select>
        </label>
        <?php if (!$assignedOnly): ?>
            <label>Estatus de pago
                <select name="estado_pago"><option value="">Todos</option><?php foreach (['sin_cargos' => 'Sin cargos', 'al_corriente' => 'Al corriente', 'pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'vencido' => 'Vencido'] as $value => $label): ?><option value="<?= $escape($value) ?>"<?= $selected('estado_pago', $value) ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
            </label>
        <?php endif; ?>
        <div class="consultas-form-actions">
            <button type="submit" class="consultas-button consultas-button-primary"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</button>
            <a class="consultas-button consultas-button-secondary" href="lista.php">Limpiar filtros</a>
        </div>
    </form>
</section>

<section class="tc-panel">
    <div class="tc-panel-head consultas-results-head">
        <div><span class="tc-eyebrow">DIRECTORIO</span><h2>Resultados</h2></div>
        <span class="consultas-count"><?= number_format($result['pagination']['total']) ?> <?= $result['pagination']['total'] === 1 ? 'resultado' : 'resultados' ?></span>
    </div>
    <?php if ($result['data'] === []): ?>
        <div class="tc-empty"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span>No se encontraron alumnos. Prueba con otros filtros.</span></div>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table consultas-table">
                <thead><tr><th>Matrícula</th><th>Nombre</th><th>Teléfono</th><?php if (!$assignedOnly): ?><th>Correo de contacto</th><th>Correo de cuenta</th><?php endif; ?><th>Cinturón</th><th>Estado</th><th><?= $assignedOnly ? 'Entrenamiento' : 'Pago' ?></th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($result['data'] as $student): ?>
                    <?php
                    $status = $assignedOnly
                        ? ($student['habilitado'] === null ? 'Por verificar' : ($student['habilitado'] ? 'Habilitado' : 'No habilitado'))
                        : ucfirst(str_replace('_', ' ', $student['estado_pago']));
                    $statusStyle = $assignedOnly
                        ? ($student['habilitado'] === null ? 'neutral' : ($student['habilitado'] ? 'active' : 'inactive'))
                        : match ($student['estado_pago']) {
                            'al_corriente' => 'active',
                            'vencido' => 'inactive',
                            'pendiente', 'parcial' => 'warning',
                            default => 'neutral',
                        };
                    ?>
                    <tr>
                        <td data-label="Matrícula"><?= $escape($student['matricula']) ?></td>
                        <td data-label="Nombre"><strong><?= $escape($student['nombre'] . ' ' . $student['apellidos']) ?></strong></td>
                        <td data-label="Teléfono"><?= $escape($student['telefono']) ?></td>
                        <?php if (!$assignedOnly): ?><td data-label="Correo de contacto"><?= $escape($student['correo_contacto'] ?? '—') ?></td><td data-label="Correo de cuenta"><?= $escape($student['correo_cuenta'] ?? '—') ?></td><?php endif; ?>
                        <td data-label="Cinturón"><?= $escape($student['color_cinturon'] ?? 'Sin asignar') ?></td>
                        <td data-label="Estado"><span class="consultas-badge consultas-badge-<?= (int) $student['estatus'] === 1 ? 'active' : 'neutral' ?>"><?= (int) $student['estatus'] === 1 ? 'Activo' : 'Inactivo' ?></span></td>
                        <td data-label="<?= $assignedOnly ? 'Entrenamiento' : 'Pago' ?>"><span class="consultas-badge consultas-badge-<?= $escape($statusStyle) ?>"><?= $escape($status) ?></span></td>
                        <td data-label="Acciones"><div class="consultas-row-actions">
                            <?php if ($canWrite): ?><a href="editar_al.php?id=<?= (int) $student['id_alumno'] ?>">Editar</a><?php endif; ?>
                            <?php if ($canAssignBelt): ?><a href="cinturon.php?id=<?= (int) $student['id_alumno'] ?>">Asignar cinturón</a><?php endif; ?>
                            <?php if ($canPay): ?><a href="../../registrar_pago.php?<?= $role === 'recepcionista' ? 'modo=cobro&amp;' : '' ?>alumno=<?= (int) $student['id_alumno'] ?>">Cobrar</a><?php endif; ?>
                            <?php if (!$assignedOnly): ?><a href="../../historial_pagos.php?alumno=<?= (int) $student['id_alumno'] ?>">Perfil, pagos y adeudos</a><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <nav class="consultas-pagination" aria-label="Paginación de alumnos">
        <?php if ($page > 1): ?><a href="<?= $escape($pageUrl($page - 1)) ?>"><i class="fa-solid fa-angle-left" aria-hidden="true"></i> Anterior</a><?php endif; ?>
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a href="<?= $escape($pageUrl($page + 1)) ?>">Siguiente <i class="fa-solid fa-angle-right" aria-hidden="true"></i></a><?php endif; ?>
    </nav>
</section>
<?php tc_shell_close([], '../../'); ?>
