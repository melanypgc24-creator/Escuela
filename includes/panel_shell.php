<?php
declare(strict_types=1);

/**
 * Estructura común de los paneles de Team Cats (barra superior, menú lateral y pie).
 *
 * La usan las pantallas de administración (generar_cargos.php, registrar_pago.php) y el
 * panel de secretaría / recepción, para que todas se vean igual que dashboard_admin.php.
 *
 * Uso:
 *   tc_shell_open(['title' => 'Cargos', 'role' => 'administrador', 'active' => 'cargos',
 *                  'csrf' => csrf_token(), 'breadcrumb' => [['Cargos mensuales', null]]]);
 *   ... contenido de la página ...
 *   tc_shell_close();
 */

/** Escapa texto para imprimirlo en HTML. */
function tc_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Elementos del menú lateral por rol.
 *
 * @return list<array{key:string, label:string, icon:string, href?:string, children?:list<array{key:string, label:string, icon:string, href:string}>}>
 */
function tc_shell_menu(string $role): array
{
    if ($role === 'director') {
        return [
            ['key' => 'resumen', 'label' => 'Resumen', 'icon' => 'fa-chart-line', 'href' => 'dashboard_director.php'],
            ['key' => 'reporte', 'label' => 'Reporte financiero', 'icon' => 'fa-sack-dollar', 'href' => 'dashboard_director.php#reporte-financiero'],
            ['key' => 'vencimientos', 'label' => 'Vencimientos', 'icon' => 'fa-calendar-xmark', 'href' => 'dashboard_director.php#proximos-vencimientos'],
            ['key' => 'adeudos', 'label' => 'Adeudos', 'icon' => 'fa-file-invoice-dollar', 'href' => 'dashboard_director.php#adeudos-principales'],
            ['key' => 'alumnos', 'label' => 'Buscar alumnos', 'icon' => 'fa-user-graduate', 'href' => 'modulos/alumnos/lista.php'],
            ['key' => 'historial', 'label' => 'Historial de pagos', 'icon' => 'fa-receipt', 'href' => 'historial_pagos.php'],
            ['key' => 'clases', 'label' => 'Gestionar clases', 'icon' => 'fa-calendar-days', 'href' => 'modulos/clases/clases.php'],
        ];
    }

    if ($role === 'maestro') {
        return [
            ['key' => 'resumen', 'label' => 'Resumen', 'icon' => 'fa-gauge-high', 'href' => 'panel_maestro.php'],
            ['key' => 'asistencia', 'label' => 'Pasar asistencia', 'icon' => 'fa-clipboard-check', 'href' => 'panel_maestro.php?seccion=asistencia'],
            ['key' => 'historial-asistencia', 'label' => 'Historial de asistencia', 'icon' => 'fa-clock-rotate-left', 'href' => 'modulos/asistencias/historial_asistencias.php'],
            ['key' => 'solicitudes', 'label' => 'Solicitudes de inscripción', 'icon' => 'fa-user-plus', 'href' => 'panel_maestro.php?seccion=solicitudes'],
            ['key' => 'clases', 'label' => 'Mis clases', 'icon' => 'fa-calendar-days', 'href' => 'panel_maestro.php?seccion=clases'],
            ['key' => 'alumnos', 'label' => 'Alumnos asignados', 'icon' => 'fa-users', 'href' => 'panel_maestro.php?seccion=alumnos'],
        ];
    }

    if ($role === 'recepcionista') {
        return [
            ['key' => 'resumen', 'label' => 'Resumen', 'icon' => 'fa-gauge-high', 'href' => 'panel_recepcionista.php'],
            ['key' => 'cobrar', 'label' => 'Cobrar', 'icon' => 'fa-cash-register', 'href' => 'registrar_pago.php?modo=cobro'],
            ['key' => 'transferencia', 'label' => 'Registrar transferencia', 'icon' => 'fa-building-columns', 'href' => 'registrar_pago.php?modo=transferencia'],
            ['key' => 'adeudos', 'label' => 'Alumnos con adeudo', 'icon' => 'fa-triangle-exclamation', 'href' => 'panel_recepcionista.php#adeudos'],
            ['key' => 'pagos', 'label' => 'Pagos de hoy', 'icon' => 'fa-receipt', 'href' => 'panel_recepcionista.php#pagos-hoy'],
            ['key' => 'historial', 'label' => 'Historial de pagos', 'icon' => 'fa-clock-rotate-left', 'href' => 'historial_pagos.php'],
            ['key' => 'alumnos', 'label' => 'Consultar alumnos', 'icon' => 'fa-user-graduate', 'href' => 'modulos/alumnos/lista.php'],
        ];
    }

    return [
        ['key' => 'resumen', 'label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'href' => 'dashboard_admin.php'],
        [
            'key' => 'cuentas', 'label' => 'Cuentas', 'icon' => 'fa-users',
            'children' => [
                ['key' => 'alumnos', 'label' => 'Alumnos', 'icon' => 'fa-user-graduate', 'href' => 'dashboard_admin.php?seccion=alumnos'],
                ['key' => 'instructores', 'label' => 'Instructores', 'icon' => 'fa-chalkboard-user', 'href' => 'dashboard_admin.php?seccion=instructores'],
                ['key' => 'secretaria', 'label' => 'Secretaría', 'icon' => 'fa-headset', 'href' => 'dashboard_admin.php?seccion=secretaria'],
                ['key' => 'directores', 'label' => 'Directores', 'icon' => 'fa-building-columns', 'href' => 'dashboard_admin.php?seccion=directores'],
                ['key' => 'administradores', 'label' => 'Administradores', 'icon' => 'fa-user-shield', 'href' => 'dashboard_admin.php?seccion=administradores'],
            ],
        ],
        ['key' => 'cobros', 'label' => 'Cobros y transferencias', 'icon' => 'fa-file-invoice-dollar', 'href' => 'registrar_pago.php'],
        ['key' => 'historial', 'label' => 'Historial de pagos', 'icon' => 'fa-clock-rotate-left', 'href' => 'historial_pagos.php'],
        ['key' => 'cargos', 'label' => 'Cargos mensuales', 'icon' => 'fa-calendar-plus', 'href' => 'generar_cargos.php'],
    ];
}

/**
 * Imprime desde <!DOCTYPE> hasta la apertura de <main class="tc-content"> y la miga de pan.
 *
 * @param array{title:string, role:string, active?:string, csrf:string, breadcrumb?:list<array{0:string,1:?string}>, css?:list<string>, base?:string} $options
 */
function tc_shell_open(array $options): void
{
    $role = (string) ($options['role'] ?? 'administrador');
    $active = (string) ($options['active'] ?? '');
    $base = (string) ($options['base'] ?? '');
    [$home, $roleLabel, $rootLabel] = match ($role) {
        'recepcionista' => ['panel_recepcionista.php', 'Secretaría', 'Panel de secretaría'],
        'director' => ['dashboard_director.php', 'Dirección', 'Panel de dirección'],
        'maestro' => ['panel_maestro.php', 'Instructor', 'Panel de instructor'],
        default => ['dashboard_admin.php', 'Admin', 'Panel de administración'],
    };
    $baseDir = dirname(__DIR__);
    $stylesheets = array_merge(['css/estilos.css'], $options['css'] ?? []);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= tc_e($options['title']) ?> | Team Cats</title>
    <?php foreach ($stylesheets as $stylesheet): ?>
        <link rel="stylesheet" href="<?= tc_e($base . $stylesheet) ?>?v=<?= (int) @filemtime($baseDir . '/' . $stylesheet) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="<?= tc_e($base . $home) ?>">TEAM CATS</a>
        <div class="tc-topbar-user">
            <span class="tc-topbar-role"><?= tc_e($roleLabel) ?></span>
            <form method="post" action="<?= tc_e($base) ?>logout.php">
                <input type="hidden" name="csrf_token" value="<?= tc_e($options['csrf']) ?>">
                <button type="submit" class="tc-logout" aria-label="Cerrar sesión">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <div class="tc-sidebar" id="tc-sidebar">
        <a class="tc-sidebar-logo" href="<?= tc_e($base . $home) ?>">
            <img src="<?= tc_e($base) ?>imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <ul class="tc-menu" aria-label="Secciones del panel">
            <?php foreach (tc_shell_menu($role) as $item): ?>
                <li>
                    <?php if (isset($item['children'])): ?>
                        <?php
                        $groupId = 'tc-group-' . $item['key'];
                        $groupOpen = false;
                        foreach ($item['children'] as $child) {
                            if ($child['key'] === $active) {
                                $groupOpen = true;
                            }
                        }
                        ?>
                        <button type="button" class="tc-link tc-group-toggle<?= $groupOpen ? ' is-open' : '' ?>" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>" aria-controls="<?= tc_e($groupId) ?>">
                            <i class="fa-solid <?= tc_e($item['icon']) ?>" aria-hidden="true"></i><?= tc_e($item['label']) ?>
                            <i class="fa-solid fa-chevron-down tc-chevron" aria-hidden="true"></i>
                        </button>
                        <ul class="tc-submenu" id="<?= tc_e($groupId) ?>"<?= $groupOpen ? '' : ' hidden' ?>>
                            <?php foreach ($item['children'] as $child): ?>
                                <li>
                                    <a class="tc-link tc-sublink<?= $child['key'] === $active ? ' is-active' : '' ?>" href="<?= tc_e($base . $child['href']) ?>"<?= $child['key'] === $active ? ' aria-current="page"' : '' ?>>
                                        <i class="fa-solid <?= tc_e($child['icon']) ?>" aria-hidden="true"></i><?= tc_e($child['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <a class="tc-link<?= $item['key'] === $active ? ' is-active' : '' ?>" href="<?= tc_e($base . $item['href']) ?>"<?= $item['key'] === $active ? ' aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= tc_e($item['icon']) ?>" aria-hidden="true"></i><?= tc_e($item['label']) ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content">
        <p class="tc-breadcrumb">
            <a href="<?= tc_e($base . $home) ?>"><?= tc_e($rootLabel) ?></a>
            <?php foreach ($options['breadcrumb'] ?? [] as $crumb): ?>
                <i class="fa-solid fa-angle-right" aria-hidden="true"></i>
                <?php if ($crumb[1] !== null): ?>
                    <a href="<?= tc_e($crumb[1]) ?>"><?= tc_e($crumb[0]) ?></a>
                <?php else: ?>
                    <span><?= tc_e($crumb[0]) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </p>
    <?php
}

/**
 * Cierra <main>, imprime el pie y carga los scripts del panel.
 *
 * @param list<string> $scripts Scripts adicionales (rutas relativas a la raíz del proyecto).
 */
function tc_shell_close(array $scripts = [], string $base = ''): void
{
    $baseDir = dirname(__DIR__);
    $scripts = array_merge(['js/panel-shell.js'], $scripts);
    ?>
        </main>
        <footer class="tc-footer">Team Cats · Escuela de Jiu-Jitsu</footer>
    </div>
    <?php foreach ($scripts as $script): ?>
        <script src="<?= tc_e($base . $script) ?>?v=<?= (int) @filemtime($baseDir . '/' . $script) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
    <?php
}
