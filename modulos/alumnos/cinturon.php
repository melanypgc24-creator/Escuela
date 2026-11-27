<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('students.belt_assigned');
$role = current_authenticated_role();
$origin = $_GET['origen'] ?? '';
$returnTo = 'lista.php';
$returnLabel = 'Volver a alumnos';
if ($role === 'maestro' && $origin === 'panel') {
    $returnTo = '../../panel_maestro.php?seccion=alumnos';
    $returnLabel = 'Volver al panel';
} elseif ($role === 'administrador' && $origin === 'cuentas') {
    $returnTo = '../../dashboard_admin.php?seccion=alumnos';
    $returnLabel = 'Volver a cuentas de alumnos';
}
$professorId = $role === 'maestro' ? ($_SESSION['id_profesor'] ?? null) : null;
if ($role === 'maestro' && (!is_int($professorId) || $professorId < 1)) {
    http_response_code(403);
    exit('Tu cuenta no está vinculada a un instructor.');
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Identificador de alumno inválido.');
}

$error = null;
try {
    $pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
    $model = new \App\Models\StudentModel($pdo);
    $student = $model->findForBelt($id, $professorId);
    if ($student === null) {
        http_response_code(404);
        exit('El alumno no existe o no pertenece a tus clases.');
    }
    $belts = $pdo->query('SELECT id_cinturon, color FROM cinturon ORDER BY color')->fetchAll();

    $selected = (string) ($student['id_cinturon'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_valid_csrf();
        $submitted = $_POST['id_cinturon'] ?? null;
        $beltId = $submitted === '' ? null : filter_var($submitted, FILTER_VALIDATE_INT);
        $validIds = array_map(static fn (array $belt): int => (int) $belt['id_cinturon'], $belts);
        if (!is_string($submitted) || ($beltId !== null && ($beltId === false || !in_array($beltId, $validIds, true)))) {
            http_response_code(422);
            $error = 'Selecciona un cinturón válido.';
        } else {
            $selected = $submitted;
            if (!$model->updateBelt($id, $beltId, (int) $_SESSION['id_usuario'], $professorId)) {
                http_response_code(404);
                exit('El alumno no existe o ya no pertenece a tus clases.');
            } else {
                $flashKey = $role === 'administrador' && $origin === 'cuentas' ? 'flash_admin' : 'flash_cinturon';
                $_SESSION[$flashKey] = 'Cinturón actualizado correctamente.';
                header('Location: ' . $returnTo);
                exit;
            }
        }
    }
} catch (\PDOException $exception) {
    error_log('Student belt update failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo consultar o actualizar el cinturón. Inténtalo más tarde.');
} catch (\Throwable $exception) {
    error_log('Student belt update failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudo consultar o actualizar el cinturón.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$csrfToken = csrf_token();
require dirname(__DIR__, 2) . '/includes/panel_shell.php';
tc_shell_open([
    'title' => 'Asignar cinturón',
    'role' => $role,
    'active' => 'alumnos',
    'csrf' => $csrfToken,
    'base' => '../../',
    'breadcrumb' => [['Alumnos', 'lista.php'], ['Asignar cinturón', null]],
]);
?>
<section class="tc-page-head tc-page-head-actions">
    <div>
        <span class="tc-eyebrow">ALUMNOS</span>
        <h1>Asignar cinturón</h1>
        <p>Actualiza el cinturón del alumno según su progreso.</p>
    </div>
    <a class="admin-cancel" href="<?= $escape($returnTo) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> <?= $escape($returnLabel) ?></a>
</section>

<?php if ($error !== null): ?><p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p><?php endif; ?>

<section class="tc-panel">
    <div class="tc-panel-head">
        <div>
            <span class="tc-eyebrow">ALUMNO</span>
            <h2><?= $escape($student['nombre'] . ' ' . $student['apellidos']) ?></h2>
        </div>
        <span class="tc-muted"><?= $escape($student['matricula']) ?></span>
    </div>
    <p class="tc-field-hint">Cinturón actual: <?= $escape($student['color_cinturon'] ?? 'Sin asignar') ?></p>
    <form method="post" class="tc-form-grid">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <div class="tc-field tc-wide">
            <label for="id_cinturon">Cinturón</label>
            <select id="id_cinturon" name="id_cinturon">
                <option value="">Sin asignar</option>
                <?php foreach ($belts as $belt): ?>
                    <option value="<?= $escape($belt['id_cinturon']) ?>"<?= (string) $selected === (string) $belt['id_cinturon'] ? ' selected' : '' ?>><?= $escape($belt['color']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tc-wide">
            <button type="submit" class="tc-btn"><i class="fa-solid fa-check" aria-hidden="true"></i> Guardar cinturón</button>
        </div>
    </form>
</section>
<?php tc_shell_close([], '../../'); ?>
