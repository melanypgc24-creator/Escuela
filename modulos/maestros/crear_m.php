<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('professors.manage');
$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

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
            $insert = $conexion->prepare(
                'INSERT INTO profesor (nombre, apellidos, telefono)
                 VALUES (:nombre, :apellidos, :telefono)'
            );
            $insert->execute([
                'nombre' => trim($name),
                'apellidos' => trim($surname),
                'telefono' => trim($phone),
            ]);
            $id = (int) $conexion->lastInsertId();
            (new \App\Models\AuditModel($conexion))->record(
                (int) $_SESSION['id_usuario'],
                'crear',
                'profesor',
                $id
            );
            $conexion->commit();
            header('Location: maestros.php');
            exit;
        } catch (\Throwable $exception) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log('Professor create failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo guardar el profesor.';
        }
    }
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Registrar profesor</h1>
    <?php if ($error !== null): ?><p class="auth-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= $escape(csrf_token()) ?>">
        <label for="nombre">Nombre</label>
        <input id="nombre" name="nombre" maxlength="50" required value="<?= $escape($input['nombre'] ?? '') ?>">
        <label for="apellidos">Apellidos</label>
        <input id="apellidos" name="apellidos" maxlength="100" required value="<?= $escape($input['apellidos'] ?? '') ?>">
        <label for="telefono">Teléfono</label>
        <input id="telefono" name="telefono" type="tel" maxlength="20" required value="<?= $escape($input['telefono'] ?? '') ?>">
        <div class="admin-user-actions">
            <button type="submit" class="admin-save">Guardar profesor</button>
            <a class="admin-cancel" href="maestros.php">Cancelar y volver a profesores</a>
        </div>
    </form>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
