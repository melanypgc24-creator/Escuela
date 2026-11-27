<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.manage');

$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$error = null;
$success = null;

if (isset($_GET['eliminada']) && $_GET['eliminada'] === '1') {
    $success = 'La clase se eliminó correctamente.';
} elseif (isset($_GET['error'])) {
    $error = match ((string) $_GET['error']) {
        'tiene_asistencias' => 'No se puede eliminar la clase porque ya tiene asistencias registradas.',
        'tiene_inscripciones' => 'No se puede eliminar la clase porque tiene alumnos inscritos o solicitudes de inscripción.',
        'tiene_relaciones'  => 'No se puede eliminar la clase porque tiene registros relacionados.',
        'no_encontrada'     => 'La clase seleccionada no existe o ya fue eliminada.',
        'id_invalido'       => 'El identificador de la clase no es válido.',
        default             => 'No se pudo eliminar la clase.',
    };
}

$clases = [];

try {
    $stmt = $conexion->query(
        'SELECT c.id_clase,
                c.nombre_clase,
                c.categoria,
                c.id_profesor,
                c.dias,
                c.hora_inicio,
                c.hora_fin,
                c.capacidad,
                c.costo,
                p.nombre,
                p.apellidos
         FROM clases c
         LEFT JOIN profesor p ON p.id_profesor = c.id_profesor
         ORDER BY c.id_clase DESC'
    );
    $clases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $exception) {
    error_log('Error al listar clases: ' . $exception->getMessage());
    $error = $error ?? 'No se pudieron cargar las clases registradas.';
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$time = static fn (mixed $value): string => is_string($value) && $value !== '' ? substr($value, 0, 5) : '—';
$cssFile = dirname(__DIR__, 2) . '/css/estilos.css';
$cssVer = is_file($cssFile) ? (int) filemtime($cssFile) : 1;
$csrfToken = $escape(csrf_token());
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clases | Team Cats</title>
    <link rel="stylesheet" href="/Escuela/css/estilos.css?v=<?= $cssVer ?>">
</head>
<body class="role-dashboard-page">
    <header class="role-dashboard-header">
        <div>
            <span class="admin-eyebrow">TEAM CATS · CLASES</span>
            <h1>Gestión de clases</h1>
            <p>Consulta, crea y edita las clases y horarios de la escuela.</p>
        </div>
        <div class="admin-account">
            <span><?= $escape($_SESSION['correo'] ?? '') ?></span>
            <form method="post" action="/Escuela/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="admin-logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="role-dashboard-content">
        <section class="role-dashboard-section">
            <div class="role-dashboard-section-heading">
                <h2>Clases registradas</h2>
                <div class="role-dashboard-actions">
                    <a href="crear_cl.php">Crear clase</a>
                    <a class="admin-cancel" href="<?= ($_SESSION['rol'] ?? '') === 'director' ? '../../dashboard_director.php' : '../../dashboard_admin.php' ?>">Volver al panel</a>
                </div>
            </div>

            <div class="role-dashboard-section-body">
                <?php if ($success !== null): ?>
                    <p class="auth-success admin-message" role="status"><?= $escape($success) ?></p>
                <?php endif; ?>

                <?php if ($error !== null): ?>
                    <p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p>
                <?php endif; ?>

                <?php if ($clases === []): ?>
                    <div class="role-dashboard-empty">No hay clases registradas todavía.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Clase</th>
                                    <th>Categoría</th>
                                    <th>Profesor</th>
                                    <th>Días</th>
                                    <th>Horario</th>
                                    <th>Capacidad</th>
                                    <th>Costo</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clases as $clase): ?>
                                    <?php
                                    $profNombre = trim((string) (($clase['nombre'] ?? '') . ' ' . ($clase['apellidos'] ?? '')));
                                    if ($profNombre === '') {
                                        $profNombre = 'Sin asignar';
                                    }
                                    $idClaseStr = (string) ($clase['id_clase'] ?? '');
                                    $nombreClaseStr = (string) ($clase['nombre_clase'] ?? '');
                                    ?>
                                    <tr>
                                        <td data-label="Clase"><strong><?= $escape($nombreClaseStr) ?></strong></td>
                                        <td data-label="Categoría"><?= $escape($clase['categoria'] === null ? 'Sin asignar' : ucfirst($clase['categoria'])) ?></td>
                                        <td data-label="Profesor"><?= $escape($profNombre) ?></td>
                                        <td data-label="Días"><?= $escape($clase['dias'] ?? '') ?></td>
                                        <td data-label="Horario"><?= $escape($time($clase['hora_inicio'] ?? '') . ' - ' . $time($clase['hora_fin'] ?? '')) ?></td>
                                        <td data-label="Capacidad"><?= $escape((string) ($clase['capacidad'] ?? 0)) ?></td>
                                        <td data-label="Costo"><?= (float) ($clase['costo'] ?? 0) > 0 ? '$' . $escape(number_format((float) $clase['costo'], 2)) : 'Mensualidad' ?></td>
                                        <td data-label="Acciones">
                                            <div class="admin-user-actions">
                                                <a class="admin-save" href="editar_cl.php?id=<?= $escape($idClaseStr) ?>">Editar</a>
                                                <form method="post" action="eliminar_cl.php" onsubmit="return confirm('¿Seguro que deseas eliminar la clase &quot;<?= $escape(addslashes($nombreClaseStr)) ?>&quot;?');">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                    <input type="hidden" name="id_clase" value="<?= $escape($idClaseStr) ?>">
                                                    <button type="submit" class="admin-delete">Eliminar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>