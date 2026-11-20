<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('students.read_own');

header('Cache-Control: no-store');

$sections = [
    'inicio' => ['label' => 'Inicio', 'icon' => 'fa-house'],
    'clases' => ['label' => 'Mis clases', 'icon' => 'fa-calendar-days'],
    'inscripcion' => ['label' => 'Inscribirme a clases', 'icon' => 'fa-pen-to-square'],
    'asistencias' => ['label' => 'Asistencias', 'icon' => 'fa-clipboard-check'],
    'pagos' => ['label' => 'Pagos', 'icon' => 'fa-money-bill-wave'],
    'adeudos' => ['label' => 'Adeudos', 'icon' => 'fa-file-invoice-dollar'],
    'notificaciones' => ['label' => 'Notificaciones', 'icon' => 'fa-bell'],
    'perfil' => ['label' => 'Mi perfil', 'icon' => 'fa-id-card'],
];
$menu = [
    ['key' => 'inicio'],
    ['group' => 'clases', 'label' => 'Clases', 'icon' => 'fa-dumbbell', 'items' => ['clases', 'inscripcion']],
    ['group' => 'historial', 'label' => 'Mi historial', 'icon' => 'fa-clock-rotate-left', 'items' => ['asistencias', 'pagos', 'adeudos']],
    ['key' => 'notificaciones'],
    ['key' => 'perfil'],
];
$requested = $_GET['seccion'] ?? 'inicio';
$section = is_string($requested) && isset($sections[$requested]) ? $requested : 'inicio';

$email = (string) ($_SESSION['correo'] ?? '');
$studentId = $_SESSION['id_alumno'] ?? null;
$profile = null;
$classes = [];
$attendance = [];
$payments = [];
$debts = [];
$notifications = [];
$failedSections = [];
$linked = is_int($studentId);

if ($linked) {
    try {
        $pdo = require __DIR__ . '/config/conexion.php';
        $portal = new \App\Models\StudentPortalModel($pdo);
        $profile = $portal->profile($studentId);
        // Las notificaciones se cargan siempre para mostrar el indicador de pendientes en el menú.
        $notifications = $portal->notifications((int) $_SESSION['id_usuario']);

        if ($section === 'inicio') {
            $classes = $portal->classes($studentId);
            $debts = $portal->debts($studentId);
            $payments = $portal->payments($studentId, 3);
            $attendance = $portal->attendance($studentId, 5);
        } elseif ($section === 'clases') {
            $classes = $portal->classes($studentId);
        } elseif ($section === 'asistencias') {
            $attendance = $portal->attendance($studentId);
        } elseif ($section === 'pagos') {
            $payments = $portal->payments($studentId);
        } elseif ($section === 'adeudos') {
            $debts = $portal->debts($studentId);
        }
        $failedSections = $portal->failedSections();
    } catch (\Throwable $exception) {
        error_log('Panel de alumno: no se pudo abrir la conexión o el perfil: ' . $exception->getMessage()
            . ' in ' . basename($exception->getFile()) . ':' . $exception->getLine());
        http_response_code(503);
        exit('No se pudo cargar el panel de alumno. Inténtalo de nuevo en unos minutos.');
    }
}

$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$fullName = is_array($profile) ? trim(($profile['nombre'] ?? '') . ' ' . ($profile['apellidos'] ?? '')) : '';
$displayName = $fullName !== '' ? $fullName : 'Alumno';
$firstName = is_array($profile) && trim((string) ($profile['nombre'] ?? '')) !== ''
    ? mb_convert_case(trim((string) $profile['nombre']), MB_CASE_TITLE, 'UTF-8') : 'Alumno';
$initials = mb_strtoupper(
    mb_substr(is_array($profile) ? (string) ($profile['nombre'] ?? '') : '', 0, 1)
    . mb_substr(is_array($profile) ? (string) ($profile['apellidos'] ?? '') : '', 0, 1)
) ?: 'A';
$belt = is_array($profile) && ($profile['color_cinturon'] ?? '') !== '' ? (string) $profile['color_cinturon'] : 'Sin asignar';
$money = static fn (mixed $value): string => '$' . number_format((float) $value, 2);
$date = static fn (mixed $value): string => is_string($value) && $value !== '' && strtotime($value) !== false
    ? date('d/m/Y', strtotime($value)) : '—';
$dateTime = static fn (mixed $value): string => is_string($value) && $value !== '' && strtotime($value) !== false
    ? date('d/m/Y H:i', strtotime($value)) : '—';
$time = static fn (mixed $value): string => is_string($value) && $value !== '' ? substr($value, 0, 5) : '—';
$unread = count(array_filter($notifications, static fn (array $n): bool => (int) ($n['leida'] ?? 0) === 0));
$sectionFailed = static fn (string $name): bool => in_array($name, $failedSections, true);
$notice = static function (string $name) use ($sectionFailed): string {
    return $sectionFailed($name)
        ? '<p class="role-dashboard-notice">Por ahora no pudimos cargar esta información. Inténtalo de nuevo más tarde.</p>'
        : '';
};
$notificationInfo = static function (array $notification): array {
    return match ((string) ($notification['tipo'] ?? '')) {
        'vencimiento_3_dias' => ['Tu pago vence en 3 días', 'fa-clock', 'warn'],
        'vencimiento_hoy' => ['Tu pago vence hoy', 'fa-triangle-exclamation', 'alert'],
        'cargo_cubierto' => ['Pago registrado: cargo cubierto', 'fa-circle-check', 'ok'],
        default => [ucfirst(str_replace('_', ' ', (string) ($notification['tipo'] ?? 'Aviso'))), 'fa-bell', 'warn'],
    };
};
$renderNotification = static function (array $notification) use ($notificationInfo, $escape, $money, $date, $dateTime): string {
    [$title, $icon, $tone] = $notificationInfo($notification);
    $isRead = (int) ($notification['leida'] ?? 0) === 1;
    $details = [];
    if (($notification['periodo'] ?? '') !== '') {
        $details[] = 'Periodo ' . $escape($notification['periodo']);
    }
    if (isset($notification['importe'])) {
        $details[] = $escape($money($notification['importe']));
    }
    if (($notification['fecha_vencimiento'] ?? '') !== '') {
        $details[] = 'Vence ' . $escape($date($notification['fecha_vencimiento']));
    }
    return '<article class="tc-notice' . ($isRead ? '' : ' is-unread') . '">'
        . '<span class="tc-notice-icon tc-tone-' . $tone . '"><i class="fa-solid ' . $icon . '" aria-hidden="true"></i></span>'
        . '<div class="tc-notice-body"><strong>' . $escape($title) . '</strong>'
        . '<span>' . implode(' · ', $details) . '</span>'
        . '<small>' . $escape($dateTime($notification['fecha_creacion'] ?? '')) . '</small></div>'
        . '<span class="tc-notice-state">' . ($isRead ? 'Leída' : 'Nueva') . '</span></article>';
};
$activeGroup = null;
foreach ($menu as $entry) {
    if (isset($entry['group']) && in_array($section, $entry['items'], true)) {
        $activeGroup = $entry['group'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($sections[$section]['label']) ?> | Panel de alumno | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css?v=<?= (int) filemtime(__DIR__ . '/css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="panel_alumno.php">TEAM CATS</a>
        <div class="tc-topbar-user">
            <a class="tc-bell" href="panel_alumno.php?seccion=notificaciones" aria-label="Notificaciones<?= $unread > 0 ? ': ' . $unread . ' sin leer' : '' ?>">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                <?php if ($unread > 0): ?><span class="tc-bell-count"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
            </a>
            <span class="tc-topbar-role"><?= $escape($firstName) ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="tc-logout">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <div class="tc-sidebar" id="tc-sidebar">
        <a class="tc-sidebar-logo" href="panel_alumno.php">
            <img src="imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <div class="tc-student-chip">
            <span class="tc-avatar"><?= $escape($initials) ?></span>
            <span class="tc-student-chip-text">
                <strong><?= $escape($displayName) ?></strong>
                <small>Cinturón: <?= $escape($belt) ?></small>
            </span>
        </div>
        <ul class="tc-menu" aria-label="Secciones del alumno">
            <?php foreach ($menu as $entry): ?>
                <?php if (isset($entry['key'])): ?>
                    <?php $info = $sections[$entry['key']]; $isCurrent = $entry['key'] === $section; ?>
                    <li>
                        <a class="tc-link<?= $isCurrent ? ' is-active' : '' ?>" href="panel_alumno.php?seccion=<?= $escape($entry['key']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= $info['icon'] ?>" aria-hidden="true"></i><?= $escape($info['label']) ?>
                            <?php if ($entry['key'] === 'notificaciones' && $unread > 0): ?>
                                <span class="tc-count tc-count-alert"><?= $unread > 9 ? '9+' : $unread ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php else: ?>
                    <?php $open = $activeGroup === $entry['group']; ?>
                    <li>
                        <button type="button" class="tc-link tc-group-toggle<?= $open ? ' is-open' : '' ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="tc-group-<?= $escape($entry['group']) ?>">
                            <i class="fa-solid <?= $entry['icon'] ?>" aria-hidden="true"></i><?= $escape($entry['label']) ?>
                            <i class="fa-solid fa-chevron-down tc-chevron" aria-hidden="true"></i>
                        </button>
                        <ul class="tc-submenu" id="tc-group-<?= $escape($entry['group']) ?>"<?= $open ? '' : ' hidden' ?>>
                            <?php foreach ($entry['items'] as $itemKey): ?>
                                <?php $info = $sections[$itemKey]; $isCurrent = $itemKey === $section; ?>
                                <li>
                                    <a class="tc-link tc-sublink<?= $isCurrent ? ' is-active' : '' ?>" href="panel_alumno.php?seccion=<?= $escape($itemKey) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                                        <i class="fa-solid <?= $info['icon'] ?>" aria-hidden="true"></i><?= $escape($info['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content">
            <p class="tc-breadcrumb">
                <a href="panel_alumno.php">Mi panel</a>
                <?php if ($section !== 'inicio'): ?>
                    <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span><?= $escape($sections[$section]['label']) ?></span>
                <?php endif; ?>
            </p>

            <?php if (!$linked): ?>
                <section class="tc-panel">
                    <div class="tc-empty"><i class="fa-solid fa-link-slash" aria-hidden="true"></i><span>Tu cuenta todavía no está vinculada a una ficha de alumno. Solicita la vinculación al administrador.</span></div>
                </section>
            <?php elseif (!is_array($profile)): ?>
                <section class="tc-panel">
                    <div class="tc-empty"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>No encontramos tu ficha de alumno. Solicita apoyo al administrador.</span></div>
                </section>
            <?php elseif ($section === 'inicio'): ?>
                <section class="tc-welcome">
                    <div>
                        <span class="tc-eyebrow">TEAM CATS · ALUMNO</span>
                        <h1>Bienvenido, <?= $escape($firstName) ?></h1>
                        <p>Este es el resumen de tu actividad en la escuela.</p>
                    </div>
                    <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= $escape(date('d/m/Y')) ?></div>
                </section>

                <section class="tc-metrics tc-metrics-4" aria-label="Resumen del alumno">
                    <a class="tc-metric" href="panel_alumno.php?seccion=clases">
                        <span class="tc-metric-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Clases inscritas</span>
                        <span class="tc-metric-value"><?= number_format(count($classes)) ?></span>
                        <span class="tc-metric-note">Ver mis clases <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                    <a class="tc-metric<?= $debts !== [] ? ' tc-metric-alert' : '' ?>" href="panel_alumno.php?seccion=adeudos">
                        <span class="tc-metric-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Adeudos pendientes</span>
                        <span class="tc-metric-value"><?= number_format(count($debts)) ?></span>
                        <span class="tc-metric-note">Ver adeudos <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                    <a class="tc-metric" href="panel_alumno.php?seccion=notificaciones">
                        <span class="tc-metric-icon"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Notificaciones nuevas</span>
                        <span class="tc-metric-value"><?= number_format($unread) ?></span>
                        <span class="tc-metric-note">Ver avisos <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                    <a class="tc-metric" href="panel_alumno.php?seccion=perfil">
                        <span class="tc-metric-icon"><i class="fa-solid fa-medal" aria-hidden="true"></i></span>
                        <span class="tc-metric-label">Cinturón</span>
                        <span class="tc-metric-value tc-metric-text"><?= $escape($belt) ?></span>
                        <span class="tc-metric-note">Ver mi perfil <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </section>

                <?php foreach (['clases', 'asistencias', 'pagos', 'adeudos', 'notificaciones'] as $name): ?>
                    <?= $notice($name) ?>
                <?php endforeach; ?>

                <section class="tc-grid">
                    <div class="tc-panel">
                        <div class="tc-panel-head">
                            <div><span class="tc-eyebrow">CLASES</span><h2>Mis clases</h2></div>
                            <a class="tc-link-more" href="panel_alumno.php?seccion=inscripcion">Inscribirme</a>
                        </div>
                        <?php if ($classes === []): ?>
                            <div class="tc-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span>Aún no tienes clases inscritas.</span></div>
                        <?php else: ?>
                            <div class="tc-class-list">
                                <?php foreach (array_slice($classes, 0, 4) as $class): ?>
                                    <article class="tc-class">
                                        <span class="tc-class-icon"><i class="fa-solid fa-person-running" aria-hidden="true"></i></span>
                                        <div>
                                            <strong><?= $escape($class['nombre_clase'] ?? '') ?></strong>
                                            <span><?= $escape($class['dias'] ?? '') ?> · <?= $escape($time($class['hora_inicio'] ?? '') . ' - ' . $time($class['hora_fin'] ?? '')) ?></span>
                                            <small>Prof. <?= $escape($class['profesor'] ?? '') ?></small>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tc-panel">
                        <div class="tc-panel-head">
                            <div><span class="tc-eyebrow">AVISOS</span><h2>Notificaciones</h2></div>
                            <a class="tc-link-more" href="panel_alumno.php?seccion=notificaciones">Ver todas</a>
                        </div>
                        <?php if ($notifications === []): ?>
                            <div class="tc-empty"><i class="fa-regular fa-bell" aria-hidden="true"></i><span>No tienes notificaciones por ahora.</span></div>
                        <?php else: ?>
                            <div class="tc-notice-list">
                                <?php foreach (array_slice($notifications, 0, 3) as $notification): ?>
                                    <?= $renderNotification($notification) ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="tc-grid tc-grid-even">
                    <div class="tc-panel">
                        <div class="tc-panel-head">
                            <div><span class="tc-eyebrow">HISTORIAL</span><h2>Últimas asistencias</h2></div>
                            <a class="tc-link-more" href="panel_alumno.php?seccion=asistencias">Ver todas</a>
                        </div>
                        <?php if ($attendance === []): ?>
                            <div class="tc-empty"><i class="fa-regular fa-clipboard" aria-hidden="true"></i><span>Aún no tienes asistencias registradas.</span></div>
                        <?php else: ?>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Fecha</th><th>Clase</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($attendance as $row): ?>
                                            <tr>
                                                <td data-label="Fecha"><?= $escape($date($row['fecha'] ?? '')) ?></td>
                                                <td data-label="Clase"><?= $escape($row['nombre_clase'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tc-panel">
                        <div class="tc-panel-head">
                            <div><span class="tc-eyebrow">PAGOS</span><h2>Últimos pagos</h2></div>
                            <a class="tc-link-more" href="panel_alumno.php?seccion=pagos">Ver todos</a>
                        </div>
                        <?php if ($payments === []): ?>
                            <div class="tc-empty"><i class="fa-regular fa-credit-card" aria-hidden="true"></i><span>Aún no tienes pagos registrados.</span></div>
                        <?php else: ?>
                            <div class="admin-table-wrap">
                                <table class="admin-table">
                                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td data-label="Fecha"><?= $escape($date($payment['fecha_pago'] ?? '')) ?></td>
                                                <td data-label="Concepto"><?= $escape($payment['concepto'] ?? 'Mensualidad') ?></td>
                                                <td data-label="Monto"><?= $escape($money($payment['monto'] ?? 0)) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($section === 'perfil'): ?>
                <section class="tc-profile">
                    <span class="tc-avatar tc-avatar-lg"><?= $escape($initials) ?></span>
                    <div>
                        <h1><?= $escape($displayName) ?></h1>
                        <p><?= $escape($email) ?></p>
                        <span class="tc-belt"><i class="fa-solid fa-medal" aria-hidden="true"></i> Cinturón <?= $escape($belt) ?></span>
                    </div>
                </section>
                <section class="tc-panel">
                    <div class="tc-panel-head"><div><span class="tc-eyebrow">DATOS PERSONALES</span><h2>Mi información</h2></div></div>
                    <dl class="role-dashboard-profile">
                        <div><dt>Nombre</dt><dd><?= $escape($displayName) ?></dd></div>
                        <div><dt>Matrícula</dt><dd><?= $escape($profile['matricula'] ?? 'Aún no asignada') ?></dd></div>
                        <div><dt>Teléfono</dt><dd><?= $escape(($profile['telefono'] ?? '') !== '' ? $profile['telefono'] : 'Sin registro') ?></dd></div>
                        <div><dt>Correo</dt><dd><?= $escape(($profile['correo_contacto'] ?? '') !== '' ? $profile['correo_contacto'] : $email) ?></dd></div>
                        <div><dt>Fecha de nacimiento</dt><dd><?= $escape(($profile['fecha_nacimiento'] ?? '') !== '' ? $date($profile['fecha_nacimiento']) : 'Sin registro') ?></dd></div>
                        <div><dt>Cinturón</dt><dd><?= $escape($belt) ?></dd></div>
                        <?php if (($profile['categoria'] ?? '') !== ''): ?>
                            <div><dt>Categoría</dt><dd><?= $escape(ucfirst((string) $profile['categoria'])) ?></dd></div>
                        <?php endif; ?>
                        <?php if (($profile['fecha_ingreso'] ?? '') !== ''): ?>
                            <div><dt>Fecha de ingreso</dt><dd><?= $escape($date($profile['fecha_ingreso'])) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>
            <?php elseif ($section === 'clases'): ?>
                <?= $notice('clases') ?>
                <section class="tc-panel">
                    <div class="tc-panel-head">
                        <div><span class="tc-eyebrow">CLASES</span><h2>Mis clases</h2></div>
                        <a class="tc-link-more" href="panel_alumno.php?seccion=inscripcion">Inscribirme a una clase</a>
                    </div>
                    <?php if ($classes === []): ?>
                        <div class="tc-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span>Aún no tienes clases inscritas.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Clase</th><th>Profesor</th><th>Días</th><th>Horario</th></tr></thead>
                                <tbody>
                                    <?php foreach ($classes as $class): ?>
                                        <tr>
                                            <td data-label="Clase"><strong><?= $escape($class['nombre_clase'] ?? '') ?></strong></td>
                                            <td data-label="Profesor"><?= $escape($class['profesor'] ?? '') ?></td>
                                            <td data-label="Días"><?= $escape($class['dias'] ?? '') ?></td>
                                            <td data-label="Horario"><?= $escape($time($class['hora_inicio'] ?? '') . ' - ' . $time($class['hora_fin'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif ($section === 'inscripcion'): ?>
                <section class="tc-panel">
                    <div class="tc-panel-head"><div><span class="tc-eyebrow">CLASES</span><h2>Inscribirme a clases</h2></div></div>
                    <div class="tc-soon">
                        <span class="tc-soon-icon"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
                        <h3>Muy pronto podrás inscribirte desde aquí</h3>
                        <p>Estamos preparando la inscripción en línea. Mientras tanto, consulta tus clases actuales o pide apoyo en recepción.</p>
                        <a class="admin-save" href="panel_alumno.php?seccion=clases">Ver mis clases</a>
                    </div>
                </section>
            <?php elseif ($section === 'asistencias'): ?>
                <?= $notice('asistencias') ?>
                <section class="tc-panel">
                    <div class="tc-panel-head"><div><span class="tc-eyebrow">HISTORIAL</span><h2>Historial de asistencias</h2></div></div>
                    <?php if ($attendance === []): ?>
                        <div class="tc-empty"><i class="fa-regular fa-clipboard" aria-hidden="true"></i><span>Aún no tienes asistencias registradas.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Fecha</th><th>Hora</th><th>Clase</th></tr></thead>
                                <tbody>
                                    <?php foreach ($attendance as $row): ?>
                                        <tr>
                                            <td data-label="Fecha"><?= $escape($date($row['fecha'] ?? '')) ?></td>
                                            <td data-label="Hora"><?= $escape($time($row['hora'] ?? '')) ?></td>
                                            <td data-label="Clase"><?= $escape($row['nombre_clase'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif ($section === 'pagos'): ?>
                <?= $notice('pagos') ?>
                <section class="tc-panel">
                    <div class="tc-panel-head"><div><span class="tc-eyebrow">HISTORIAL</span><h2>Historial de pagos</h2></div></div>
                    <?php if ($payments === []): ?>
                        <div class="tc-empty"><i class="fa-regular fa-credit-card" aria-hidden="true"></i><span>Aún no tienes pagos registrados.</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Método</th><th>Folio</th><th>Estatus</th></tr></thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td data-label="Fecha"><?= $escape($date($payment['fecha_pago'] ?? '')) ?></td>
                                            <td data-label="Concepto"><?= $escape($payment['concepto'] ?? 'Mensualidad') ?></td>
                                            <td data-label="Monto"><strong><?= $escape($money($payment['monto'] ?? 0)) ?></strong></td>
                                            <td data-label="Método"><?= $escape($payment['metodo_pago'] ?? '') ?></td>
                                            <td data-label="Folio"><?= $escape(($payment['folio'] ?? '') !== '' ? $payment['folio'] : '—') ?></td>
                                            <td data-label="Estatus"><span class="admin-badge <?= ($payment['estado'] ?? 'activo') === 'activo' ? 'admin-badge-active' : 'admin-badge-inactive' ?>"><?= $escape(ucfirst((string) ($payment['estado'] ?? 'activo'))) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif ($section === 'adeudos'): ?>
                <?= $notice('adeudos') ?>
                <section class="tc-panel">
                    <div class="tc-panel-head"><div><span class="tc-eyebrow">HISTORIAL</span><h2>Adeudos</h2></div></div>
                    <?php if ($debts === []): ?>
                        <div class="tc-empty tc-empty-ok"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>No tienes adeudos pendientes. ¡Estás al corriente!</span></div>
                    <?php else: ?>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>Periodo</th><th>Vence</th><th>Importe</th><th>Saldo pendiente</th><th>Estado</th></tr></thead>
                                <tbody>
                                    <?php foreach ($debts as $debt): ?>
                                        <tr>
                                            <td data-label="Periodo"><?= $escape($debt['periodo'] ?? '') ?></td>
                                            <td data-label="Vence"><?= $escape($date($debt['fecha_vencimiento'] ?? '')) ?></td>
                                            <td data-label="Importe"><?= $escape($money($debt['importe'] ?? 0)) ?></td>
                                            <td data-label="Saldo pendiente"><strong><?= $escape($money($debt['saldo_pendiente'] ?? 0)) ?></strong></td>
                                            <td data-label="Estado"><span class="admin-badge admin-badge-inactive"><?= $escape(ucfirst((string) ($debt['estado'] ?? ''))) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif ($section === 'notificaciones'): ?>
                <?= $notice('notificaciones') ?>
                <section class="tc-panel">
                    <div class="tc-panel-head">
                        <div><span class="tc-eyebrow">AVISOS</span><h2>Notificaciones</h2></div>
                        <?php if ($unread > 0): ?><span class="tc-muted"><?= number_format($unread) ?> sin leer</span><?php endif; ?>
                    </div>
                    <?php if ($notifications === []): ?>
                        <div class="tc-empty"><i class="fa-regular fa-bell" aria-hidden="true"></i><span>No tienes notificaciones por ahora.</span></div>
                    <?php else: ?>
                        <div class="tc-notice-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?= $renderNotification($notification) ?>
                            <?php endforeach; ?>
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
