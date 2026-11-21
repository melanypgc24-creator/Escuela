<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('classes.read_assigned');

header('Cache-Control: no-store');

$professorId = $_SESSION['id_profesor'] ?? null;
$classes = [];
$students = [];
$attendance = [];
$pendingRequests = [];
$warning = null;
$requestSuccess = null;
$requestError = null;
$sectionWarnings = [];

if (!is_int($professorId)) {
    $warning = 'Tu cuenta no está vinculada a un profesor. Solicita la vinculación al administrador.';
} else {
    try {
        $pdo = require __DIR__ . '/config/conexion.php';

        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && in_array((string) ($_POST['accion'] ?? ''), ['aceptar_solicitud', 'rechazar_solicitud'], true)
        ) {
            require_valid_csrf();

            $rawReqId = $_POST['id_inscripcion'] ?? null;
            $requestId = ($rawReqId === '' || $rawReqId === null)
                ? false
                : filter_var($rawReqId, FILTER_VALIDATE_INT);
            $decision = (string) ($_POST['accion'] ?? '') === 'aceptar_solicitud' ? 'aceptada' : 'rechazada';

            if ($requestId === false || $requestId <= 0) {
                $requestError = 'La solicitud seleccionada no es válida.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Verifica que la solicitud exista, esté pendiente y pertenezca a una clase del profesor actual
                    $reqStmt = $pdo->prepare(
                        'SELECT ic.id_inscripcion,
                                ic.id_clase,
                                ic.estado,
                                c.capacidad
                         FROM inscripciones_clases ic
                         JOIN clases c ON c.id_clase = ic.id_clase
                         WHERE ic.id_inscripcion = :id_inscripcion
                           AND c.id_profesor = :id_profesor
                         LIMIT 1'
                    );
                    $reqStmt->execute([
                        'id_inscripcion' => $requestId,
                        'id_profesor'    => $professorId,
                    ]);
                    $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);

                    if (!is_array($reqRow)) {
                        $pdo->rollBack();
                        $requestError = 'No se encontró la solicitud o no pertenece a tus clases.';
                    } elseif (($reqRow['estado'] ?? '') !== 'pendiente') {
                        $pdo->rollBack();
                        $requestError = 'Esta solicitud ya fue revisada anteriormente.';
                    } else {
                        if ($decision === 'aceptada') {
                            $capStmt = $pdo->prepare(
                                'SELECT COUNT(*) FROM inscripciones_clases
                                 WHERE id_clase = :id_clase AND estado = "aceptada"'
                            );
                            $capStmt->execute(['id_clase' => (int) $reqRow['id_clase']]);
                            $ocupados = (int) $capStmt->fetchColumn();

                            if ($ocupados >= (int) ($reqRow['capacidad'] ?? 0)) {
                                $pdo->rollBack();
                                $requestError = 'No se puede aceptar: la clase ya alcanzó su capacidad máxima.';
                            }
                        }

                        if ($requestError === null) {
                            $updStmt = $pdo->prepare(
                                'UPDATE inscripciones_clases
                                 SET estado = :estado,
                                     fecha_resolucion = CURRENT_TIMESTAMP,
                                     revisado_por_profesor = :id_profesor
                                 WHERE id_inscripcion = :id_inscripcion'
                            );
                            $updStmt->execute([
                                'estado'         => $decision,
                                'id_profesor'    => $professorId,
                                'id_inscripcion' => $requestId,
                            ]);

                            $pdo->commit();
                            $requestSuccess = $decision === 'aceptada'
                                ? 'Solicitud aceptada. El alumno ya forma parte de la clase.'
                                : 'Solicitud rechazada.';
                        }
                    }
                } catch (\Throwable $txException) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Error al resolver solicitud de inscripción: ' . $txException->getMessage());
                    $requestError = 'No se pudo actualizar la solicitud.';
                }
            }
        }

        $classModel = new \App\Models\ClassModel($pdo);
        try {
            $classes = $classModel->allForProfessor($professorId);
            $students = $classModel->studentsForProfessor($professorId);
        } catch (\PDOException $exception) {
            $sectionWarnings[] = 'No se pudieron cargar clases o alumnos asignados.';
        }

        try {
            $reqListStmt = $pdo->prepare(
                'SELECT ic.id_inscripcion,
                        ic.fecha_solicitud,
                        c.nombre_clase,
                        c.dias,
                        c.hora_inicio,
                        c.hora_fin,
                        c.capacidad,
                        TRIM(CONCAT(COALESCE(a.nombre, ""), " ", COALESCE(a.apellidos, ""))) AS alumno,
                        a.telefono,
                        (SELECT COUNT(*)
                         FROM inscripciones_clases ic2
                         WHERE ic2.id_clase = c.id_clase AND ic2.estado = "aceptada") AS inscritos_aceptados
                 FROM inscripciones_clases ic
                 JOIN clases c ON c.id_clase = ic.id_clase
                 JOIN alumnos a ON a.id_alumno = ic.id_alumno
                 WHERE c.id_profesor = :id_profesor
                   AND ic.estado = "pendiente"
                 ORDER BY ic.fecha_solicitud ASC, ic.id_inscripcion ASC'
            );
            $reqListStmt->execute(['id_profesor' => $professorId]);
            $pendingRequests = $reqListStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            error_log('Error al cargar solicitudes pendientes: ' . $exception->getMessage());
            $sectionWarnings[] = 'No se pudieron cargar las solicitudes de inscripción.';
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
$time = static fn (mixed $value): string => is_string($value) && $value !== '' ? substr($value, 0, 5) : '—';
$dateTime = static fn (mixed $value): string => is_string($value) && $value !== '' && strtotime($value) !== false
    ? date('d/m/Y H:i', strtotime($value)) : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel de maestro | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css?v=<?= (int) filemtime(__DIR__ . '/css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="panel_maestro.php">TEAM CATS</a>
        <div class="tc-topbar-user">
            <span class="tc-topbar-role"><?= $email ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="tc-logout">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <aside class="tc-sidebar" id="tc-sidebar" aria-label="Menú de instructor">
        <a class="tc-sidebar-logo" href="panel_maestro.php">
            <img src="imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <ul class="tc-menu" aria-label="Secciones de instructor">
            <li>
                <a class="tc-link is-active" href="#resumen" aria-current="page">
                    <i class="fa-solid fa-house" aria-hidden="true"></i>Mis grupos
                </a>
            </li>
            <li>
                <a class="tc-link" href="#solicitudes-inscripcion">
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>Solicitudes
                    <?php if ($pendingRequests !== []): ?>
                        <span class="tc-count tc-count-alert"><?= count($pendingRequests) > 9 ? '9+' : count($pendingRequests) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a class="tc-link" href="#mis-clases">
                    <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Mis clases
                </a>
            </li>
            <li>
                <a class="tc-link" href="#alumnos-asignados">
                    <i class="fa-solid fa-users" aria-hidden="true"></i>Alumnos asignados
                </a>
            </li>
            <li>
                <a class="tc-link" href="#asistencia-reciente">
                    <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>Asistencia reciente
                </a>
            </li>
        </ul>
    </aside>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content" id="resumen">
            <p class="tc-breadcrumb">
                <a href="panel_maestro.php">Instructor</a>
                <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span>Mis grupos</span>
            </p>

            <section class="tc-welcome">
                <div>
                    <span class="tc-eyebrow">TEAM CATS · INSTRUCTOR</span>
                    <h1>Panel de instructor</h1>
                    <p>Clases, solicitudes de inscripción, alumnos y asistencia de tus grupos.</p>
                </div>
                <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= $escape(date('d/m/Y')) ?></div>
            </section>

            <?php if (is_string($warning)): ?>
                <p class="auth-error admin-message" role="alert"><?= $escape($warning) ?></p>
            <?php else: ?>
                <?php if ($requestSuccess !== null): ?>
                    <p class="auth-success admin-message" role="status"><?= $escape($requestSuccess) ?></p>
                <?php endif; ?>

                <?php if ($requestError !== null): ?>
                    <p class="auth-error admin-message" role="alert"><?= $escape($requestError) ?></p>
                <?php endif; ?>

                <?php foreach ($sectionWarnings as $sectionWarning): ?>
                    <p class="auth-error admin-message" role="alert"><?= $escape($sectionWarning) ?></p>
                <?php endforeach; ?>

                <section class="tc-metrics tc-metrics-4" aria-label="Resumen de actividad">
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Clases asignadas</span>
                        <span class="tc-metric-value"><?= number_format(count($classes)) ?></span>
                    </article>
                    <article class="tc-metric<?= $pendingRequests !== [] ? ' tc-metric-alert' : '' ?>">
                        <span class="tc-metric-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Solicitudes pendientes</span>
                        <span class="tc-metric-value"><?= number_format(count($pendingRequests)) ?></span>
                    </article>
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Alumnos asignados</span>
                        <span class="tc-metric-value"><?= number_format(count($students)) ?></span>
                    </article>
                    <article class="tc-metric">
                        <span class="tc-metric-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Asistencias recientes</span>
                        <span class="tc-metric-value"><?= number_format(count($attendance)) ?></span>
                    </article>
                </section>

                <section class="tc-panel" id="solicitudes-inscripcion">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">INSCRIPCIONES</span>
                            <h2>Solicitudes de inscripción pendientes</h2>
                        </div>
                    </div>
                    <?php if ($pendingRequests === []): ?>
                        <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No tienes solicitudes de inscripción pendientes por revisar.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Alumno</th>
                                        <th>Clase</th>
                                        <th>Horario</th>
                                        <th>Cupo</th>
                                        <th>Solicitado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingRequests as $req): ?>
                                        <?php
                                        $alumnoNombre = trim((string) ($req['alumno'] ?? '')) ?: 'Alumno';
                                        $capacidad = (int) ($req['capacidad'] ?? 0);
                                        $ocupados = (int) ($req['inscritos_aceptados'] ?? 0);
                                        $sinCupo = $ocupados >= $capacidad;
                                        $reqId = (string) ($req['id_inscripcion'] ?? '');
                                        ?>
                                        <tr>
                                            <td data-label="Alumno">
                                                <strong><?= $escape($alumnoNombre) ?></strong>
                                                <?php if (($req['telefono'] ?? '') !== ''): ?>
                                                    <div class="tc-muted"><?= $escape((string) $req['telefono']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Clase"><?= $escape($req['nombre_clase'] ?? '') ?></td>
                                            <td data-label="Horario"><?= $escape(($req['dias'] ?? '') . ' · ' . $time($req['hora_inicio'] ?? '') . ' - ' . $time($req['hora_fin'] ?? '')) ?></td>
                                            <td data-label="Cupo"><?= $escape($ocupados . ' / ' . $capacidad) ?></td>
                                            <td data-label="Solicitado"><?= $escape($dateTime($req['fecha_solicitud'] ?? '')) ?></td>
                                            <td data-label="Acciones">
                                                <div class="admin-user-actions">
                                                    <form method="post" action="panel_maestro.php#solicitudes-inscripcion">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                        <input type="hidden" name="accion" value="aceptar_solicitud">
                                                        <input type="hidden" name="id_inscripcion" value="<?= $escape($reqId) ?>">
                                                        <button type="submit" class="admin-save" <?= $sinCupo ? 'disabled' : '' ?>>Aceptar</button>
                                                    </form>
                                                    <form method="post" action="panel_maestro.php#solicitudes-inscripcion">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                        <input type="hidden" name="accion" value="rechazar_solicitud">
                                                        <input type="hidden" name="id_inscripcion" value="<?= $escape($reqId) ?>">
                                                        <button type="submit" class="admin-delete">Rechazar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="tc-panel" id="mis-clases">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">CLASES</span>
                            <h2>Mis clases</h2>
                        </div>
                    </div>
                    <?php if ($classes === []): ?>
                        <div class="tc-empty"><span>No tienes clases asignadas actualmente.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Clase</th><th>Días</th><th>Horario</th><th>Capacidad</th></tr></thead>
                                <tbody>
                                    <?php foreach ($classes as $class): ?>
                                        <tr>
                                            <td data-label="Clase"><?= $escape($class['nombre_clase'] ?? '') ?></td>
                                            <td data-label="Días"><?= $escape($class['dias'] ?? '') ?></td>
                                            <td data-label="Horario"><?= $escape($time($class['hora_inicio'] ?? '') . ' - ' . $time($class['hora_fin'] ?? '')) ?></td>
                                            <td data-label="Capacidad"><?= $escape($class['capacidad'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="tc-panel" id="alumnos-asignados">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">ALUMNOS</span>
                            <h2>Alumnos asignados</h2>
                        </div>
                    </div>
                    <?php if ($students === []): ?>
                        <div class="tc-empty"><span>No hay alumnos inscritos en tus clases.</span></div>
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

                <section class="tc-panel" id="asistencia-reciente">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">HISTORIAL</span>
                            <h2>Asistencia reciente</h2>
                        </div>
                    </div>
                    <?php if ($attendance === []): ?>
                        <div class="tc-empty"><span>Aún no hay asistencias registradas.</span></div>
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
        <footer class="tc-footer">Team Cats · Escuela de Jiu-Jitsu</footer>
    </div>
    <script src="js/panel-shell.js?v=<?= (int) filemtime(__DIR__ . '/js/panel-shell.js') ?>"></script>
</body>
</html>