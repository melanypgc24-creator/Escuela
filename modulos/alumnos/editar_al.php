<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('students.write');
$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 0) {
    http_response_code(400);
    exit('Identificador de alumno inválido.');
}

$model = new \App\Models\StudentModel($conexion);
$alumno = $model->find($id);
if ($alumno === null) {
    http_response_code(404);
    exit('El alumno no existe.');
}

$error = null;
$role = current_authenticated_role();
$isAdministrator = $role === 'administrador';
$input = $_POST;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $nombre = $input['nombre'] ?? null;
    $apellidos = $input['apellidos'] ?? null;
    $telefono = $input['telefono'] ?? null;
    $correo = $input['correo_contacto'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $fechaNacimiento = $input['fecha_nacimiento'] ?? null;
    $fechaIngreso = $isAdministrator ? ($input['fecha_ingreso'] ?? null) : $alumno['fecha_ingreso'];
    $cinturonValue = $input['id_cinturon'] ?? '';
    $cinturonId = $cinturonValue === '' ? null : filter_var($cinturonValue, FILTER_VALIDATE_INT);
    $estatus = $isAdministrator
        ? filter_var($input['estatus'] ?? null, FILTER_VALIDATE_INT)
        : (int) $alumno['estatus'];
    $categoria = $isAdministrator ? ($input['categoria'] ?? null) : $alumno['categoria'];
    $categoria = $categoria === '' ? null : $categoria;

    if (!is_string($nombre) || trim($nombre) === ''
        || !is_string($apellidos) || trim($apellidos) === ''
        || !is_string($telefono) || trim($telefono) === ''
        || ($correo !== '' && (!is_string($correo) || !filter_var($correo, FILTER_VALIDATE_EMAIL)))
        || !is_string($direccion)
        || !is_string($fechaNacimiento) || !\App\Core\Request::isValidBirthDate($fechaNacimiento)
        || !is_string($fechaIngreso) || !\App\Core\Request::isValidDate($fechaIngreso)
        || ($cinturonId !== null && $cinturonId === false)
        || !in_array($estatus, [0, 1], true)
        || ($categoria !== null && !in_array($categoria, ['infantil', 'adulta'], true))) {
        $error = 'Revisa los datos del alumno.';
    } else {
        try {
            $model->update($id, [
                'nombre' => trim($nombre),
                'apellidos' => trim($apellidos),
                'telefono' => trim($telefono),
                'correo_contacto' => is_string($correo) && trim($correo) !== '' ? trim($correo) : null,
                'direccion' => trim($direccion) !== '' ? trim($direccion) : null,
                'fecha_nacimiento' => $fechaNacimiento,
                'fecha_ingreso' => $fechaIngreso,
                'estatus' => $estatus,
                'categoria' => $categoria,
                'id_cinturon' => $cinturonId,
            ], (int) $_SESSION['id_usuario']);
            header('Location: lista.php?actualizado=' . $id);
            exit;
        } catch (\PDOException $exception) {
            error_log('Student update failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo actualizar el alumno. Comprueba que las migraciones del esquema estén aplicadas.';
        } catch (\Throwable $exception) {
            error_log('Student update failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'No se pudo actualizar el alumno.';
        }
    }
}

$stmt = $conexion->query('SELECT id_cinturon, color FROM cinturon ORDER BY color');
$cinturones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$value = static fn (string $key): mixed => $input[$key] ?? $alumno[$key] ?? null;
$escape = static fn (mixed $item): string => htmlspecialchars((string) ($item ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Editar alumno</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre">Nombre</label>
        <input id="nombre" name="nombre" required maxlength="100" value="<?= $escape($value('nombre')) ?>">
        <label for="apellidos">Apellidos</label>
        <input id="apellidos" name="apellidos" required maxlength="100" value="<?= $escape($value('apellidos')) ?>">
        <label for="telefono">Teléfono</label>
        <input id="telefono" name="telefono" type="tel" required maxlength="20" value="<?= $escape($value('telefono')) ?>">
        <label for="correo_contacto">Correo de contacto</label>
        <input id="correo_contacto" name="correo_contacto" type="email" maxlength="150" value="<?= $escape($value('correo_contacto')) ?>">
        <label for="direccion">Dirección</label>
        <input id="direccion" name="direccion" maxlength="255" value="<?= $escape($value('direccion')) ?>">
        <label for="fecha_nacimiento">Fecha de nacimiento</label>
        <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" required max="<?= date('Y-m-d') ?>" value="<?= $escape($value('fecha_nacimiento')) ?>">
        <?php if ($isAdministrator): ?>
            <label for="fecha_ingreso">Fecha de ingreso</label>
            <input id="fecha_ingreso" name="fecha_ingreso" type="date" required value="<?= $escape($value('fecha_ingreso')) ?>">
        <?php endif; ?>
        <label for="id_cinturon">Cinturón</label>
        <select id="id_cinturon" name="id_cinturon">
            <option value="">Sin asignar</option>
            <?php foreach ($cinturones as $cinturon): ?>
                <option value="<?= $escape($cinturon['id_cinturon']) ?>" <?= (string) $value('id_cinturon') === (string) $cinturon['id_cinturon'] ? 'selected' : '' ?>>
                    <?= $escape($cinturon['color']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($isAdministrator): ?>
            <label for="categoria">Categoría</label>
            <select id="categoria" name="categoria">
                <option value="">Sin clasificar</option>
                <option value="infantil" <?= $value('categoria') === 'infantil' ? 'selected' : '' ?>>Infantil</option>
                <option value="adulta" <?= $value('categoria') === 'adulta' ? 'selected' : '' ?>>Adulta</option>
            </select>
            <label for="estatus">Estado</label>
            <select id="estatus" name="estatus">
                <option value="1" <?= (string) $value('estatus') === '1' ? 'selected' : '' ?>>Activo</option>
                <option value="0" <?= (string) $value('estatus') === '0' ? 'selected' : '' ?>>Inactivo</option>
            </select>
        <?php endif; ?>
        <button type="submit">Guardar cambios</button>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
