<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('professors.manage');
$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 0) {
    http_response_code(400);
    exit('Identificador de profesor inválido.');
}
$find = $conexion->prepare('SELECT id_profesor, nombre, apellidos, telefono FROM profesor WHERE id_profesor = :id');
$find->execute(['id' => $id]);
$professor = $find->fetch(PDO::FETCH_ASSOC);
if ($professor === false) {
    http_response_code(404);
    exit('El profesor no existe.');
}

$error = null;
$input = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_valid_csrf();
    $name = $input['nombre'] ?? null;
    $surname = $input['apellidos'] ?? null;
    $phone = $input['telefono'] ?? null;
    if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 50
        || !is_string($surname) || trim($surname) === '' || mb_strlen(trim($surname), 'UTF-8') > 100
        || !is_string($phone) || trim($phone) === '' || mb_strlen(trim($phone), 'UTF-8') > 20) {
        $error = 'Revisa nombre, apellidos y teléfono.';
    } else {
        try {
            $conexion->beginTransaction();
            $update = $conexion->prepare(
                'UPDATE profesor SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono
                 WHERE id_profesor = :id'
            );
            $update->execute([
                'nombre' => trim($name),
                'apellidos' => trim($surname),
                'telefono' => trim($phone),
                'id' => $id,
            ]);
            if ($update->rowCount() > 0) {
                (new \App\Models\AuditModel($conexion))->record(
                    (int) $_SESSION['id_usuario'],
                    'actualizar',
                    'profesor',
                    $id,
                    ['campos' => ['nombre', 'apellidos', 'telefono']]
                );
            }
            $conexion->commit();
            header('Location: maestros.php');
            exit;
        } catch (\Throwable $exception) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log('Professor update failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo actualizar el profesor.';
        }
    }
}

$value = static fn (string $key): mixed => $input[$key] ?? $professor[$key] ?? null;
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Editar profesor</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre">Nombre</label>
        <input id="nombre" name="nombre" maxlength="50" required value="<?= $escape($value('nombre')) ?>">
        <label for="apellidos">Apellidos</label>
        <input id="apellidos" name="apellidos" maxlength="100" required value="<?= $escape($value('apellidos')) ?>">
        <label for="telefono">Teléfono</label>
        <input id="telefono" name="telefono" type="tel" maxlength="20" required value="<?= $escape($value('telefono')) ?>">
        <div class="admin-user-actions">
            <button type="submit" class="admin-save">Guardar cambios</button>
            <a class="admin-cancel" href="maestros.php">Cancelar y volver a profesores</a>
        </div>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
