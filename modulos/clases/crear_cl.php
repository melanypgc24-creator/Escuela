<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.manage');
require_once dirname(__DIR__, 2) . '/config/database.php';

$error = null;
$input = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $name = $input['nombre_clase'] ?? null;
    $professorId = filter_var($input['id_profesor'] ?? null, FILTER_VALIDATE_INT);
    $days = $input['dias'] ?? null;
    $start = $input['hora_inicio'] ?? null;
    $end = $input['hora_fin'] ?? null;
    $capacity = filter_var($input['capacidad'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 100
        || $professorId === false || $professorId < 0
        || !is_string($days) || trim($days) === '' || mb_strlen(trim($days), 'UTF-8') > 50
        || !is_string($start) || !preg_match('/^\d{2}:\d{2}$/D', $start)
        || !is_string($end) || !preg_match('/^\d{2}:\d{2}$/D', $end) || $end <= $start
        || $capacity === false || $capacity < 1) {
        $error = 'Revisa el nombre, profesor, días, horario y capacidad de la clase.';
    } else {
        try {
            $conexion->beginTransaction();
            $insert = $conexion->prepare(
                'INSERT INTO clases (nombre_clase, id_profesor, dias, hora_inicio, hora_fin, capacidad)
                 VALUES (:nombre_clase, :id_profesor, :dias, :hora_inicio, :hora_fin, :capacidad)'
            );
            $insert->execute([
                'nombre_clase' => trim($name),
                'id_profesor' => $professorId,
                'dias' => trim($days),
                'hora_inicio' => $start,
                'hora_fin' => $end,
                'capacidad' => $capacity,
            ]);
            $id = (int) $conexion->lastInsertId();
            (new \App\Models\AuditModel($conexion))->record(
                (int) $_SESSION['id_usuario'],
                'crear',
                'clases',
                $id,
                ['id_profesor' => $professorId, 'capacidad' => $capacity]
            );
            $conexion->commit();
            header('Location: clases.php');
            exit;
        } catch (\Throwable $exception) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log('Class create failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo guardar la clase.';
        }
    }
}

$profesores = $conexion->query('SELECT id_profesor, nombre, apellidos FROM profesor ORDER BY apellidos, nombre')->fetchAll(PDO::FETCH_ASSOC);
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Crear clase</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre_clase">Nombre de la clase</label>
        <input id="nombre_clase" name="nombre_clase" maxlength="100" required value="<?= $escape($input['nombre_clase'] ?? '') ?>">
        <label for="id_profesor">Profesor</label>
        <select id="id_profesor" name="id_profesor" required>
            <option value="">Selecciona un profesor</option>
            <?php foreach ($profesores as $profesor): ?>
                <option value="<?= $escape($profesor['id_profesor']) ?>" <?= (string) ($input['id_profesor'] ?? '') === (string) $profesor['id_profesor'] ? 'selected' : '' ?>>
                    <?= $escape($profesor['nombre'] . ' ' . $profesor['apellidos']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="dias">Días</label>
        <input id="dias" name="dias" maxlength="50" required value="<?= $escape($input['dias'] ?? '') ?>">
        <label for="hora_inicio">Hora de inicio</label>
        <input id="hora_inicio" name="hora_inicio" type="time" required value="<?= $escape($input['hora_inicio'] ?? '') ?>">
        <label for="hora_fin">Hora de fin</label>
        <input id="hora_fin" name="hora_fin" type="time" required value="<?= $escape($input['hora_fin'] ?? '') ?>">
        <label for="capacidad">Capacidad</label>
        <input id="capacidad" name="capacidad" type="number" min="1" required value="<?= $escape($input['capacidad'] ?? 20) ?>">
        <button type="submit">Guardar clase</button>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
