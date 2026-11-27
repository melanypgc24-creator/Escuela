<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.read_assigned');

header('Cache-Control: no-store');

$professorId = $_SESSION['id_profesor'] ?? null;
$classes = [];
$historyRows = [];
$selectedClassId = null;
$selectedDate = '';
$warning = null;
$sectionWarnings = [];

$isValidDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
    && strtotime($value) !== false;

if (!is_int($professorId)) {
    $warning = 'Tu cuenta no está vinculada a un profesor. Solicita la vinculación al administrador.';
} else {
    try {
        $pdo = require dirname(__DIR__, 2) . '/config/conexion.php';

        $rawClass = $_GET['clase_id'] ?? null;
        if ($rawClass !== null && $rawClass !== '' && $rawClass !== 'todas') {
            $parsedClass = filter_var($rawClass, FILTER_VALIDATE_INT);
            if ($parsedClass !== false && $parsedClass > 0) {
                $selectedClassId = $parsedClass;
            }
        }

        $rawDate = trim((string) ($_GET['fecha'] ?? ''));
        if ($rawDate !== '' && $isValidDate($rawDate)) {
            $selectedDate = $rawDate;
        }

        $classModel = new \App\Models\ClassModel($pdo);
        try {
            $classes = $classModel->allForProfessor($professorId);
        } catch (\PDOException $exception) {
            $sectionWarnings[] = 'No se pudieron cargar las clases asignadas.';
        }

        // Verifica que si se filtró por clase, pertenezca al profesor
        if ($selectedClassId !== null) {
            $belongs = false;
            foreach ($classes as $cls) {
                if ((int) ($cls['id_clase'] ?? 0) === $selectedClassId) {
                    $belongs = true;
                    break;
                }
            }
            if (!$belongs) {
                $selectedClassId = null;
            }
        }

        try {
            $sql = 'SELECT a.id_asistencia,
                           a.fecha,
                           a.hora,
                           a.id_clase,
                           c.nombre_clase,
                           c.dias,
                           al.matricula,
                           TRIM(CONCAT(COALESCE(al.nombre, ""), " ", COALESCE(al.apellidos, ""))) AS alumno
                    FROM asistencias a
                    JOIN clases c ON c.id_clase = a.id_clase
                    JOIN alumnos al ON al.id_alumno = a.id_alumno
                    WHERE c.id_profesor = :id_profesor';
            $params = ['id_profesor' => $professorId];

            if ($selectedClassId !== null) {
                $sql .= ' AND a.id_clase = :id_clase';
                $params['id_clase'] = $selectedClassId;
            }

            if ($selectedDate !== '') {
                $sql .= ' AND a.fecha = :fecha';
                $params['fecha'] = $selectedDate;
            }

            $sql .= ' ORDER BY a.fecha DESC, a.hora DESC, c.nombre_clase ASC, alumno ASC LIMIT 200';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            error_log('Error al cargar historial de asistencias: ' . $exception->getMessage());
            $sectionWarnings[] = 'No se pudo consultar el historial de pases de lista.';
        }
    } catch (\PDOException $exception) {
        error_log('Attendance history failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('No se pudo cargar el historial de asistencias.');
    } catch (\Throwable $exception) {
        error_log('Attendance history failed: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo cargar el historial de asistencias.');
    }
}

$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($_SESSION['correo'] ?? ''), ENT_QUOTES, 'UTF-8');
$time = static fn (mixed $value): string => is_string($value) && $value !== '' ? substr($value, 0, 5) : '—';
$dateFmt = static fn (mixed $value): string => is_string($value) && $value !== '' && strtotime($value) !== false
    ? date('d/m/Y', strtotime($value)) : '—';

$cssFile = dirname(__DIR__, 2) . '/css/estilos.css';
$cssVer = is_file($cssFile) ? (int) filemtime($cssFile) : 1;
$jsFile = dirname(__DIR__, 2) . '/js/panel-shell.js';
$jsVer = is_file($jsFile) ? (int) filemtime($jsFile) : 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de asistencias | Team Cats</title>
    <link rel="stylesheet" href="/Escuela/css/estilos.css?v=<?= $cssVer ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="/Escuela/panel_maestro.php">TEAM CATS</a>
        <div class="tc-topbar-user">
            <span class="tc-topbar-role"><?= $email ?></span>
            <form method="post" action="/Escuela/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="tc-logout">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <aside class="tc-sidebar" id="tc-sidebar" aria-label="Menú de instructor">
        <a class="tc-sidebar-logo" href="/Escuela/panel_maestro.php">
            <img src="/Escuela/imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <ul class="tc-menu" aria-label="Secciones de instructor">
            <li>
                <a class="tc-link" href="/Escuela/panel_maestro.php">
                    <i class="fa-solid fa-house" aria-hidden="true"></i>Mis grupos
                </a>
            </li>
            <li>
                <a class="tc-link" href="/Escuela/panel_maestro.php?seccion=asistencia">
                    <i class="fa-solid fa-user-check" aria-hidden="true"></i>Pasar asistencia
                </a>
            </li>
            <li>
                <a class="tc-link is-active" href="/Escuela/modulos/asistencias/historial_asistencias.php" aria-current="page">
                    <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>Historial de listas
                </a>
            </li>
        </ul>
    </aside>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content">
            <p class="tc-breadcrumb">
                <a href="/Escuela/panel_maestro.php">Instructor</a>
                <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span>Historial de asistencias</span>
            </p>

            <section class="tc-welcome">
                <div>
                    <span class="tc-eyebrow">TEAM CATS · ASISTENCIAS</span>
                    <h1>Historial de pases de lista</h1>
                    <p>Consulta los registros de asistencia guardados en días anteriores por clase y fecha.</p>
                </div>
                <div class="admin-user-actions">
                    <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= $escape(date('d/m/Y')) ?></div>
                    <a class="admin-cancel" href="/Escuela/panel_maestro.php?seccion=asistencia"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a pasar lista</a>
                </div>
            </section>

            <?php if (is_string($warning)): ?>
                <p class="auth-error admin-message" role="alert"><?= $escape($warning) ?></p>
            <?php else: ?>
                <?php foreach ($sectionWarnings as $sectionWarning): ?>
                    <p class="auth-error admin-message" role="alert"><?= $escape($sectionWarning) ?></p>
                <?php endforeach; ?>

                <section class="tc-panel">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">FILTROS</span>
                            <h2>Buscar pases de lista</h2>
                        </div>
                    </div>

                    <form method="get" action="/Escuela/modulos/asistencias/historial_asistencias.php" class="class-create-form" style="margin-bottom: 18px;">
                        <label for="clase_id">Clase</label>
                        <select id="clase_id" name="clase_id">
                            <option value="todas" <?= $selectedClassId === null ? 'selected' : '' ?>>Todas mis clases</option>
                            <?php foreach ($classes as $cls): ?>
                                <?php $cid = (int) ($cls['id_clase'] ?? 0); ?>
                                <option value="<?= $escape((string) $cid) ?>" <?= $cid === $selectedClassId ? 'selected' : '' ?>>
                                    <?= $escape((string) ($cls['nombre_clase'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="fecha">Fecha específica (opcional)</label>
                        <div class="admin-user-actions">
                            <input id="fecha" name="fecha" type="date" value="<?= $escape($selectedDate) ?>">
                            <button type="submit" class="admin-save">Filtrar</button>
                            <a class="admin-logout" href="/Escuela/modulos/asistencias/historial_asistencias.php">Limpiar</a>
                        </div>
                    </form>

                    <?php if ($historyRows === []): ?>
                        <div class="tc-empty"><span>No hay registros de asistencia para el filtro seleccionado.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Hora registro</th>
                                        <th>Clase</th>
                                        <th>Matrícula</th>
                                        <th>Alumno</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historyRows as $row): ?>
                                        <tr>
                                            <td data-label="Fecha"><?= $escape($dateFmt($row['fecha'] ?? '')) ?></td>
                                            <td data-label="Hora registro"><?= $escape($time($row['hora'] ?? '')) ?></td>
                                            <td data-label="Clase"><?= $escape($row['nombre_clase'] ?? '') ?></td>
                                            <td data-label="Matrícula"><?= $escape($row['matricula'] ?? 'Pendiente') ?></td>
                                            <td data-label="Alumno"><strong><?= $escape($row['alumno'] ?? '') ?></strong></td>
                                            <td data-label="Estado"><span class="admin-badge admin-badge-active">Asistió</span></td>
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
    <script src="/Escuela/js/panel-shell.js?v=<?= $jsVer ?>"></script>
</body>
</html>