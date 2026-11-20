<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('classes.read_assigned');

header('Cache-Control: no-store');

$professorId = $_SESSION['id_profesor'] ?? null;
$classes = [];
$students = [];
$attendance = [];
$warning = null;
$sectionWarnings = [];

if (!is_int($professorId)) {
    $warning = 'Tu cuenta no está vinculada a un profesor. Solicita la vinculación al administrador.';
} else {
    try {
        $pdo = require __DIR__ . '/config/conexion.php';
        $classModel = new \App\Models\ClassModel($pdo);
        try {
            $classes = $classModel->allForProfessor($professorId);
            $students = $classModel->studentsForProfessor($professorId);
        } catch (\PDOException $exception) {
            $sectionWarnings[] = 'No se pudieron cargar clases o alumnos asignados.';
        }
        try {
            $attendance = array_slice((new \App\Models\AttendanceModel($pdo))->allForProfessor($professorId), 0, 15);
        } catch (\PDOException $exception) {
            $sectionWarnings[] = 'No se pudo cargar la asistencia reciente.';
        }
    } catch (\PDOException $exception) {
        error_log('Professor panel failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('No se pudo cargar el panel de instructor.');
    } catch (\Throwable $exception) {
        error_log('Professor panel failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo cargar el panel de instructor.');
    }
}

$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($_SESSION['correo'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de maestro | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="role-dashboard-page">
    <header class="role-dashboard-header">
        <div>
            <span class="admin-eyebrow">TEAM CATS · INSTRUCTOR</span>
            <h1>Panel de instructor</h1>
            <p>Clases, alumnos y asistencia de tus grupos.</p>
        </div>
        <div class="admin-account">
            <span><?= $email ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="admin-logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="role-dashboard-content">
        <section class="role-dashboard-welcome">
            <div>
                <span class="admin-eyebrow">RESUMEN · <?= $escape(date('d/m/Y')) ?></span>
                <h2>Mis grupos</h2>
                <p>Consulta tus clases asignadas y la actividad reciente.</p>
            </div>
        </section>

        <?php if (is_string($warning)): ?>
            <p class="auth-error admin-message" role="alert"><?= $escape($warning) ?></p>
        <?php else: ?>
            <?php foreach ($sectionWarnings as $sectionWarning): ?>
                <p class="auth-error admin-message" role="alert"><?= $escape($sectionWarning) ?></p>
            <?php endforeach; ?>

            <section class="role-dashboard-stats" aria-label="Resumen de actividad">
                <article><span>Clases asignadas</span><strong><?= number_format(count($classes)) ?></strong></article>
                <article><span>Alumnos asignados</span><strong><?= number_format(count($students)) ?></strong></article>
                <article><span>Asistencias recientes</span><strong><?= number_format(count($attendance)) ?></strong></article>
            </section>

            <section class="role-dashboard-section">
                <div class="role-dashboard-section-heading"><h2>Mis clases</h2></div>
            <?php if ($classes === []): ?>
                <div class="role-dashboard-empty">No tienes clases asignadas actualmente.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Clase</th><th>Días</th><th>Horario</th><th>Capacidad</th></tr></thead>
                        <tbody>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td data-label="Clase"><?= $escape($class['nombre_clase'] ?? '') ?></td>
                                    <td data-label="Días"><?= $escape($class['dias'] ?? '') ?></td>
                                    <td data-label="Horario"><?= $escape(substr((string) ($class['hora_inicio'] ?? ''), 0, 5) . ' - ' . substr((string) ($class['hora_fin'] ?? ''), 0, 5)) ?></td>
                                    <td data-label="Capacidad"><?= $escape($class['capacidad'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </section>

            <section class="role-dashboard-section">
                <div class="role-dashboard-section-heading"><h2>Alumnos asignados</h2></div>
            <?php if ($students === []): ?>
                <div class="role-dashboard-empty">No hay alumnos inscritos en tus clases.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Matrícula</th><th>Nombre</th><th>Teléfono</th><th>Cinturón</th></tr></thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td data-label="Matrícula"><?= $escape($student['matricula'] ?? 'Pendiente') ?></td>
                                    <td data-label="Nombre"><?= $escape(($student['nombre'] ?? '') . ' ' . ($student['apellidos'] ?? '')) ?></td>
                                    <td data-label="Teléfono"><?= $escape($student['telefono'] ?? '') ?></td>
                                    <td data-label="Cinturón"><?= $escape($student['color_cinturon'] ?? 'Sin asignar') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </section>

            <section class="role-dashboard-section">
                <div class="role-dashboard-section-heading"><h2>Asistencia reciente</h2></div>
            <?php if ($attendance === []): ?>
                <div class="role-dashboard-empty">Aún no hay asistencias registradas.</div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Fecha</th><th>Hora</th><th>Alumno</th><th>Clase</th></tr></thead>
                        <tbody>
                            <?php foreach ($attendance as $row): ?>
                                <tr>
                                    <td data-label="Fecha"><?= $escape($row['fecha'] ?? '') ?></td>
                                    <td data-label="Hora"><?= $escape($row['hora'] ?? '') ?></td>
                                    <td data-label="Alumno"><?= $escape($row['alumno'] ?? '') ?></td>
                                    <td data-label="Clase"><?= $escape($row['nombre_clase'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
