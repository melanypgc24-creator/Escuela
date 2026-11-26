<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('students.write');
require_once dirname(__DIR__, 2) . '/config/database.php';

$error = null;
$role = current_authenticated_role();
$isAdministrator = $role === 'administrador';
$input = $_POST;
$estatus = $isAdministrator ? ($_POST['estatus'] ?? '1') : '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $nombre = $input['nombre'] ?? null;
    $apellidos = $input['apellidos'] ?? null;
    $telefono = $input['telefono'] ?? null;
    $fechaNacimiento = $input['fecha_nacimiento'] ?? null;
    $fechaIngreso = $isAdministrator ? ($input['fecha_ingreso'] ?? date('Y-m-d')) : date('Y-m-d');
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
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Registrar nuevo alumno</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre">Nombre</label>
        <input id="nombre" name="nombre" required maxlength="100" value="<?= $escape($input['nombre'] ?? '') ?>">
        <label for="apellidos">Apellidos</label>
        <input id="apellidos" name="apellidos" required maxlength="100" value="<?= $escape($input['apellidos'] ?? '') ?>">
        <label for="telefono">Teléfono</label>
        <input id="telefono" name="telefono" type="tel" required maxlength="20" value="<?= $escape($input['telefono'] ?? '') ?>">
        <label for="fecha_nacimiento">Fecha de nacimiento</label>
        <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" required max="<?= date('Y-m-d') ?>" value="<?= $escape($input['fecha_nacimiento'] ?? '') ?>">
        <?php if ($isAdministrator): ?>
            <label for="fecha_ingreso">Fecha de ingreso</label>
            <input id="fecha_ingreso" name="fecha_ingreso" type="date" required value="<?= $escape($input['fecha_ingreso'] ?? date('Y-m-d')) ?>">
        <?php else: ?>
            <input type="hidden" name="fecha_ingreso" value="<?= $escape(date('Y-m-d')) ?>">
        <?php endif; ?>
        <label for="id_cinturon">Cinturón</label>
        <select id="id_cinturon" name="id_cinturon">
            <option value="">Sin asignar</option>
            <?php foreach ($cinturones as $cinturon): ?>
                <option value="<?= $escape($cinturon['id_cinturon']) ?>" <?= (string) ($input['id_cinturon'] ?? '') === (string) $cinturon['id_cinturon'] ? 'selected' : '' ?>>
                    <?= $escape($cinturon['color']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($isAdministrator): ?>
            <label for="estatus">Estado</label>
            <select id="estatus" name="estatus">
                <option value="1" <?= (string) $estatus === '1' ? 'selected' : '' ?>>Activo</option>
                <option value="0" <?= (string) $estatus === '0' ? 'selected' : '' ?>>Inactivo</option>
            </select>
        <?php endif; ?>
        <button type="submit">Guardar alumno</button>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
