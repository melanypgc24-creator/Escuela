<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('users.manage');

header('Cache-Control: no-store');

$error = null;
$success = null;
$csrfToken = $_SESSION['csrf_token'] ?? '';
$allowedRoles = ['alumno', 'maestro', 'recepcionista', 'director', 'administrador'];

try {
    $pdo = require __DIR__ . '/config/conexion.php';
} catch (\PDOException $exception) {
    error_log('Admin dashboard connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = $_POST['csrf_token'] ?? '';
    $userId = filter_var($_POST['id_usuario'] ?? null, FILTER_VALIDATE_INT);
    $role = $_POST['rol'] ?? '';
    $studentValue = $_POST['id_alumno'] ?? '';
    $professorValue = $_POST['id_profesor'] ?? '';
    $studentId = $studentValue === '' ? null : filter_var($studentValue, FILTER_VALIDATE_INT);
    $professorId = $professorValue === '' ? null : filter_var($professorValue, FILTER_VALIDATE_INT);

    if (!is_string($providedToken) || !is_string($csrfToken) || $csrfToken === ''
        || !hash_equals($csrfToken, $providedToken)) {
        http_response_code(403);
        $error = 'La solicitud venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($userId === false || $userId < 0 || !is_string($role) || !in_array($role, $allowedRoles, true)
        || ($studentId !== null && ($studentId === false || $studentId < 0))
        || ($professorId !== null && ($professorId === false || $professorId < 0))
        || ($role === 'alumno' && $studentId === null)
        || ($role === 'maestro' && $professorId === null)) {
        $error = 'Selecciona un rol y la ficha de alumno o profesor correspondiente.';
    } elseif ((int) $userId === (int) $_SESSION['id_usuario'] && $role !== 'administrador') {
        $error = 'No puedes quitarte a ti mismo el rol de administrador.';
    } else {
        try {
            $pdo->beginTransaction();
            $target = $pdo->prepare(
                'SELECT id_usuario, rol FROM usuarios WHERE id_usuario = :id_usuario FOR UPDATE'
            );
            $target->execute(['id_usuario' => $userId]);
            $targetUser = $target->fetch();

            if ($targetUser === false) {
                $pdo->rollBack();
                $error = 'No se encontró el usuario que intentas actualizar.';
            } elseif ($targetUser['rol'] === 'administrador' && $role !== 'administrador') {
                $admins = $pdo->query(
                    "SELECT id_usuario FROM usuarios WHERE rol = 'administrador' FOR UPDATE"
                )->fetchAll();
                if (count($admins) <= 1) {
                    $pdo->rollBack();
                    $error = 'No puedes quitar el rol al único administrador del sistema.';
                } else {
                    $update = $pdo->prepare(
                        'UPDATE usuarios SET rol = :rol, id_alumno = :id_alumno, id_profesor = :id_profesor
                         WHERE id_usuario = :id_usuario'
                    );
                    $update->execute([
                        'rol' => $role,
                        'id_alumno' => $role === 'alumno' ? $studentId : null,
                        'id_profesor' => $role === 'maestro' ? $professorId : null,
                        'id_usuario' => $userId,
                    ]);
                    (new \App\Models\AuditModel($pdo))->record(
                        (int) $_SESSION['id_usuario'],
                        'cambiar_rol',
                        'usuarios',
                        $userId,
                        [
                            'rol_anterior' => $targetUser['rol'],
                            'rol_nuevo' => $role,
                            'id_alumno' => $role === 'alumno' ? $studentId : null,
                            'id_profesor' => $role === 'maestro' ? $professorId : null,
                        ]
                    );
                    $pdo->commit();
                    $success = 'El rol se actualizó correctamente.';
                }
            } else {
                $update = $pdo->prepare(
                    'UPDATE usuarios SET rol = :rol, id_alumno = :id_alumno, id_profesor = :id_profesor
                     WHERE id_usuario = :id_usuario'
                );
                $update->execute([
                    'rol' => $role,
                    'id_alumno' => $role === 'alumno' ? $studentId : null,
                    'id_profesor' => $role === 'maestro' ? $professorId : null,
                    'id_usuario' => $userId,
                ]);
                (new \App\Models\AuditModel($pdo))->record(
                    (int) $_SESSION['id_usuario'],
                    'cambiar_rol',
                    'usuarios',
                    $userId,
                    [
                        'rol_anterior' => $targetUser['rol'],
                        'rol_nuevo' => $role,
                        'id_alumno' => $role === 'alumno' ? $studentId : null,
                        'id_profesor' => $role === 'maestro' ? $professorId : null,
                    ]
                );
                $pdo->commit();
                $success = 'El rol se actualizó correctamente.';
            }
        } catch (\PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin role update failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = 'No se pudo actualizar el rol. Inténtalo de nuevo.';
        }
    }
}

try {
    $alumnos = $pdo->query(
        'SELECT id_alumno, matricula, nombre, apellidos FROM alumnos ORDER BY apellidos, nombre'
    )->fetchAll();
    $profesores = $pdo->query(
        'SELECT id_profesor, nombre, apellidos FROM profesor ORDER BY apellidos, nombre'
    )->fetchAll();
    $users = $pdo->query(
        'SELECT id_usuario, correo, rol, id_alumno, id_profesor
         FROM usuarios
         ORDER BY id_usuario DESC'
    )->fetchAll();
} catch (\PDOException $exception) {
    error_log('Admin dashboard query failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo cargar la lista de usuarios.');
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$adminEmail = $escape($_SESSION['correo'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administración | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="admin-page">
    <header class="admin-header">
        <div>
            <p class="admin-eyebrow">Team Cats · Gestión</p>
            <h1>Panel de administración</h1>
        </div>
        <div class="admin-account">
            <span><?= $adminEmail ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <button type="submit" class="admin-logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="admin-main">
        <section class="admin-welcome">
            <h2>Usuarios y permisos</h2>
            <p>Las cuentas públicas se registran como alumnos. Puedes asignar otro rol desde esta lista.</p>
            <a class="admin-save admin-payments-link" href="registrar_pago.php">Ir a cobros</a>
        </section>

        <?php if ($error !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p>
        <?php elseif ($success !== null): ?>
            <p class="auth-success admin-message" role="status"><?= $escape($success) ?></p>
        <?php endif; ?>

        <section class="admin-users">
            <div class="admin-users-heading">
                <h2>Usuarios registrados</h2>
                <span><?= count($users) ?> cuentas</span>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>ID</th><th>Correo</th><th>Rol actual</th><th>Actualizar rol y ficha</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td data-label="ID"><?= $escape($user['id_usuario']) ?></td>
                                <td data-label="Correo"><?= $escape($user['correo']) ?></td>
                                <td data-label="Rol actual"><?= $escape(ucfirst($user['rol'])) ?></td>
                                <td data-label="Actualizar rol">
                                    <form method="post" action="dashboard_admin.php" class="admin-user-form">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="id_usuario" value="<?= $escape($user['id_usuario']) ?>">
                                        <label class="sr-only" for="rol-<?= $escape($user['id_usuario']) ?>">Nuevo rol</label>
                                        <select id="rol-<?= $escape($user['id_usuario']) ?>" name="rol" required>
                                            <?php foreach ($allowedRoles as $role): ?>
                                                <option value="<?= $escape($role) ?>" <?= $user['rol'] === $role ? 'selected' : '' ?>>
                                                    <?= $escape(ucfirst($role)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="sr-only" for="alumno-<?= $escape($user['id_usuario']) ?>">Ficha de alumno</label>
                                        <select id="alumno-<?= $escape($user['id_usuario']) ?>" name="id_alumno">
                                            <option value="">Sin ficha de alumno</option>
                                            <?php foreach ($alumnos as $alumno): ?>
                                                <option value="<?= $escape($alumno['id_alumno']) ?>" <?= (string) $user['id_alumno'] === (string) $alumno['id_alumno'] ? 'selected' : '' ?>>
                                                    <?= $escape($alumno['matricula'] . ' · ' . $alumno['nombre'] . ' ' . $alumno['apellidos']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label class="sr-only" for="profesor-<?= $escape($user['id_usuario']) ?>">Ficha de profesor</label>
                                        <select id="profesor-<?= $escape($user['id_usuario']) ?>" name="id_profesor">
                                            <option value="">Sin ficha de profesor</option>
                                            <?php foreach ($profesores as $profesor): ?>
                                                <option value="<?= $escape($profesor['id_profesor']) ?>" <?= (string) $user['id_profesor'] === (string) $profesor['id_profesor'] ? 'selected' : '' ?>>
                                                    <?= $escape($profesor['nombre'] . ' ' . $profesor['apellidos']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="admin-save">Guardar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr><td colspan="4" class="admin-empty">Aún no hay usuarios registrados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
