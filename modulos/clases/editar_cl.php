<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.manage');
require_once dirname(__DIR__, 2) . '/config/database.php';

$classId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($classId === false || $classId === null || $classId < 0) {
    http_response_code(400);
    exit('Identificador de clase inválido.');
}
$find = $conexion->prepare('SELECT * FROM clases WHERE id_clase = :id_clase');
$find->execute(['id_clase' => $classId]);
$clase = $find->fetch(PDO::FETCH_ASSOC);
if ($clase === false) {
    http_response_code(404);
    exit('La clase no existe.');
}

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
            $lockClass = $conexion->prepare('SELECT id_clase FROM clases WHERE id_clase = :id_clase FOR UPDATE');
            $lockClass->execute(['id_clase' => $classId]);
            if ($lockClass->fetchColumn() === false) {
                throw new \DomainException('La clase ya no existe.');
            }
            $enrolled = $conexion->prepare(
                'SELECT COUNT(*) FROM clases_alumnos WHERE id_clase = :id_clase AND estado = "activa"'
            );
            $enrolled->execute(['id_clase' => $classId]);
            if ($capacity < (int) $enrolled->fetchColumn()) {
                throw new \DomainException('La capacidad no puede ser menor que las inscripciones activas.');
            }
            $update = $conexion->prepare(
                'UPDATE clases
                 SET nombre_clase = :nombre, id_profesor = :id_profesor, dias = :dias,
                     hora_inicio = :inicio, hora_fin = :fin, capacidad = :capacidad
                 WHERE id_clase = :id_clase'
            );
            $update->execute([
                'nombre' => trim($name),
                'id_profesor' => $professorId,
                'dias' => trim($days),
                'inicio' => $start,
                'fin' => $end,
                'capacidad' => $capacity,
                'id_clase' => $classId,
            ]);
            if ($update->rowCount() > 0) {
                (new \App\Models\AuditModel($conexion))->record(
                    (int) $_SESSION['id_usuario'],
                    'actualizar',
                    'clases',
                    $classId,
                    ['id_profesor' => $professorId, 'capacidad' => $capacity]
                );
            }
            $conexion->commit();
            header('Location: clases.php');
            exit;
        } catch (\DomainException $exception) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            $error = $exception->getMessage();
        } catch (\Throwable $exception) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log('Class update failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo actualizar la clase.';
        }
    }
}

$profesores = $conexion->query('SELECT id_profesor, nombre, apellidos FROM profesor ORDER BY apellidos, nombre')->fetchAll(PDO::FETCH_ASSOC);
$value = static fn (string $key): mixed => $input[$key] ?? $clase[$key] ?? null;
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Editar clase</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre_clase">Nombre de la clase</label>
        <input id="nombre_clase" name="nombre_clase" maxlength="100" required value="<?= $escape($value('nombre_clase')) ?>">
        <label for="id_profesor">Profesor</label>
        <select id="id_profesor" name="id_profesor" required>
            <?php foreach ($profesores as $profesor): ?>
                <option value="<?= $escape($profesor['id_profesor']) ?>" <?= (string) $value('id_profesor') === (string) $profesor['id_profesor'] ? 'selected' : '' ?>>
                    <?= $escape($profesor['nombre'] . ' ' . $profesor['apellidos']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="dias">Días</label>
        <input id="dias" name="dias" maxlength="50" required value="<?= $escape($value('dias')) ?>">
        <label for="hora_inicio">Hora de inicio</label>
        <input id="hora_inicio" name="hora_inicio" type="time" required value="<?= $escape($value('hora_inicio')) ?>">
        <label for="hora_fin">Hora de fin</label>
        <input id="hora_fin" name="hora_fin" type="time" required value="<?= $escape($value('hora_fin')) ?>">
        <label for="capacidad">Capacidad</label>
        <input id="capacidad" name="capacidad" type="number" min="1" required value="<?= $escape($value('capacidad')) ?>">
        <button type="submit">Guardar cambios</button>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
