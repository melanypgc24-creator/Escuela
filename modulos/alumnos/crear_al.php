<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('students.create');
$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$error = null;
$role = current_authenticated_role();
$input = $_POST;
$estatus = $_POST['estatus'] ?? '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $nombre = $input['nombre'] ?? null;
    $apellidos = $input['apellidos'] ?? null;
    $telefono = $input['telefono'] ?? null;
    $fechaNacimiento = $input['fecha_nacimiento'] ?? null;
    $fechaIngreso = $input['fecha_ingreso'] ?? date('Y-m-d');
    $cinturonValue = $input['id_cinturon'] ?? '';
    $cinturonId = $cinturonValue === '' ? null : filter_var($cinturonValue, FILTER_VALIDATE_INT);
    $estatusValue = filter_var($estatus, FILTER_VALIDATE_INT);

    if (!is_string($nombre) || trim($nombre) === ''
        || !is_string($apellidos) || trim($apellidos) === ''
        || !is_string($telefono) || trim($telefono) === ''
        || !is_string($fechaNacimiento) || !\App\Core\Request::isValidBirthDate($fechaNacimiento)
        || !is_string($fechaIngreso) || !\App\Core\Request::isValidDate($fechaIngreso)
        || ($cinturonId !== null && $cinturonId === false)
        || $estatusValue === false || !in_array($estatusValue, [0, 1], true)) {
        $error = 'Revisa los datos obligatorios del alumno.';
    } else {
        try {
            $id = (new \App\Models\StudentModel($conexion))->create([
                'nombre' => trim($nombre),
                'apellidos' => trim($apellidos),
                'telefono' => trim($telefono),
                'correo_contacto' => null,
                'direccion' => null,
                'fecha_nacimiento' => $fechaNacimiento,
                'fecha_ingreso' => $fechaIngreso,
                'estatus' => $estatusValue,
                'categoria' => null,
                'id_cinturon' => $cinturonId,
            ], (int) $_SESSION['id_usuario']);
            header('Location: lista.php?creado=' . $id);
            exit;
        } catch (\PDOException $exception) {
            error_log('Student create failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo guardar el alumno. Comprueba que las migraciones del esquema estén aplicadas.';
        } catch (\Throwable $exception) {
            error_log('Student create failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'No se pudo guardar el alumno.';
        }
    }
}

$stmt = $conexion->query('SELECT id_cinturon, color FROM cinturon ORDER BY color');
$cinturones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$csrfToken = csrf_token();
require dirname(__DIR__, 2) . '/includes/panel_shell.php';
tc_shell_open([
    'title' => 'Registrar nuevo alumno',
    'role' => $role,
    'active' => 'alumnos',
    'csrf' => $csrfToken,
    'base' => '../../',
    'breadcrumb' => [['Alumnos', 'lista.php'], ['Registrar alumno', null]],
]);
?>
<section class="tc-page-head tc-page-head-actions">
    <div>
        <span class="tc-eyebrow">ALUMNOS</span>
        <h1>Registrar nuevo alumno</h1>
        <p>Completa los datos para crear la ficha del alumno.</p>
    </div>
    <a class="admin-cancel" href="lista.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a alumnos</a>
</section>

<?php if ($error !== null): ?><p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p><?php endif; ?>

<section class="tc-panel">
    <div class="tc-panel-head">
        <div>
            <span class="tc-eyebrow">NUEVO REGISTRO</span>
            <h2>Datos del alumno</h2>
        </div>
        <span class="tc-muted">* Campos obligatorios</span>
    </div>
    <form method="post" action="" class="tc-form-grid">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <div class="tc-field">
            <label for="nombre">Nombre *</label>
            <input id="nombre" name="nombre" autocomplete="given-name" required maxlength="100" value="<?= $escape($input['nombre'] ?? '') ?>">
        </div>
        <div class="tc-field">
            <label for="apellidos">Apellidos *</label>
            <input id="apellidos" name="apellidos" autocomplete="family-name" required maxlength="100" value="<?= $escape($input['apellidos'] ?? '') ?>">
        </div>
        <div class="tc-field">
            <label for="telefono">Teléfono *</label>
            <input id="telefono" name="telefono" type="tel" autocomplete="tel" required maxlength="20" value="<?= $escape($input['telefono'] ?? '') ?>">
        </div>
        <div class="tc-field">
            <label for="fecha_nacimiento">Fecha de nacimiento *</label>
            <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" required max="<?= date('Y-m-d') ?>" data-categoria-clases="categoria-clases" data-fecha-actual="<?= date('Y-m-d') ?>" value="<?= $escape($input['fecha_nacimiento'] ?? '') ?>">
            <small id="categoria-clases" aria-live="polite">Categoría para clases: pendiente de una fecha válida.</small>
        </div>
        <div class="tc-field">
            <label for="fecha_ingreso">Fecha de ingreso *</label>
            <input id="fecha_ingreso" name="fecha_ingreso" type="date" required value="<?= $escape($input['fecha_ingreso'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="tc-field">
            <label for="id_cinturon">Cinturón</label>
            <select id="id_cinturon" name="id_cinturon">
                <option value="">Sin asignar</option>
                <?php foreach ($cinturones as $cinturon): ?>
                    <option value="<?= $escape($cinturon['id_cinturon']) ?>" <?= (string) ($input['id_cinturon'] ?? '') === (string) $cinturon['id_cinturon'] ? 'selected' : '' ?>>
                        <?= $escape($cinturon['color']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tc-field">
            <label for="estatus">Estado</label>
            <select id="estatus" name="estatus">
                <option value="1" <?= (string) $estatus === '1' ? 'selected' : '' ?>>Activo</option>
                <option value="0" <?= (string) $estatus === '0' ? 'selected' : '' ?>>Inactivo</option>
            </select>
        </div>
        <div class="tc-banner tc-wide">
            <div>
                <strong>Ficha de alumno</strong>
                <small>La matrícula se asigna automáticamente. Este registro no crea una cuenta de acceso.</small>
            </div>
        </div>
        <div class="tc-wide">
            <button type="submit" class="tc-btn"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Guardar alumno</button>
        </div>
    </form>
</section>
<script src="../../js/categoria-clases.js" defer></script>
<?php tc_shell_close([], '../../'); ?>
