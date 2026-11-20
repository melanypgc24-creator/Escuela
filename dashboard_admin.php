<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_permission('users.manage');

header('Cache-Control: no-store');

// Cada sección del panel agrupa un tipo de cuenta, como en el panel de Sanitec.
$sections = [
    'resumen' => ['label' => 'Resumen', 'role' => null],
    'alumnos' => ['label' => 'Alumnos', 'role' => 'alumno', 'singular' => 'alumno'],
    'instructores' => ['label' => 'Instructores', 'role' => 'maestro', 'singular' => 'instructor'],
    'secretaria' => ['label' => 'Secretaría', 'role' => 'recepcionista', 'singular' => 'cuenta de secretaría'],
    'directores' => ['label' => 'Directores', 'role' => 'director', 'singular' => 'director'],
    'administradores' => ['label' => 'Administradores', 'role' => 'administrador', 'singular' => 'administrador'],
];
$roleLabels = [
    'alumno' => 'Alumno',
    'maestro' => 'Instructor',
    'recepcionista' => 'Secretaría / Recepción',
    'director' => 'Director',
    'administrador' => 'Administrador',
];
$sectionOf = static function (?string $value) use ($sections): string {
    return is_string($value) && isset($sections[$value]) ? $value : 'resumen';
};
$sectionForRole = static function (string $role) use ($sections): string {
    foreach ($sections as $key => $info) {
        if ($info['role'] === $role) {
            return $key;
        }
    }
    return 'resumen';
};

$error = null;
$success = $_SESSION['flash_admin'] ?? null;
unset($_SESSION['flash_admin']);
$csrfToken = csrf_token();

try {
    $pdo = require __DIR__ . '/config/conexion.php';
    $accounts = new \App\Models\AccountModel($pdo);
    $availableRoles = $accounts->availableRoles();
} catch (\PDOException $exception) {
    error_log('Admin dashboard connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo conectar con la base de datos.');
}

$section = $sectionOf($_GET['seccion'] ?? null);
$actorId = (int) $_SESSION['id_usuario'];
$formData = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $userId = filter_var($_POST['id_usuario'] ?? null, FILTER_VALIDATE_INT);
    $section = $sectionOf($_POST['seccion'] ?? null);

    if (!is_string($providedToken) || !hash_equals($csrfToken, $providedToken)) {
        http_response_code(403);
        $error = 'La solicitud venció. Recarga la página e inténtalo de nuevo.';
    } elseif (!is_string($action) || !in_array($action, ['create_account', 'update_account', 'update_role', 'set_active', 'delete_account', 'reset_password'], true)) {
        $error = 'La acción solicitada no es válida.';
    } elseif ($action !== 'create_account' && ($userId === false || $userId < 0)) {
        $error = 'Selecciona un usuario válido.';
    } else {
        try {
            $target = $section;
            switch ($action) {
                case 'create_account':
                    $formData = $_POST;
                    $newId = $accounts->create($_POST, $actorId);
                    $_SESSION['flash_admin'] = 'La cuenta se creó correctamente (ID ' . $newId . ').';
                    $target = $sectionForRole((string) ($_POST['rol'] ?? ''));
                    break;
                case 'update_account':
                    $accounts->update((int) $userId, $_POST, $actorId);
                    if ((int) $userId === $actorId) {
                        $_SESSION['correo'] = trim((string) $_POST['correo']);
                    }
                    $_SESSION['flash_admin'] = 'La cuenta se actualizó correctamente.';
                    break;
                case 'update_role':
                    $newRole = (string) ($_POST['rol'] ?? '');
                    $accounts->changeRole((int) $userId, $newRole, $actorId);
                    $_SESSION['flash_admin'] = 'El rol se actualizó. El ID, los vínculos y el historial de la cuenta se conservaron.';
                    $target = $sectionForRole($newRole);
                    break;
                case 'reset_password':
                    $accounts->resetPassword(
                        (int) $userId,
                        (string) ($_POST['nueva_password'] ?? ''),
                        (string) ($_POST['confirmar_password'] ?? ''),
                        $actorId
                    );
                    $_SESSION['flash_admin'] = 'La contraseña se restableció. Compártela con el usuario por un medio seguro.';
                    break;
                case 'delete_account':
                    $accounts->delete((int) $userId, $actorId);
                    $_SESSION['flash_admin'] = 'La cuenta se eliminó. La ficha de alumno o instructor y su historial se conservan.';
                    break;
                case 'set_active':
                    $active = ($_POST['activo'] ?? '') === '1';
                    $accounts->setActive((int) $userId, $active, $actorId);
                    $_SESSION['flash_admin'] = $active ? 'La cuenta se activó.' : 'La cuenta se desactivó. Sus datos e historial se conservan.';
                    break;
            }
            header('Location: dashboard_admin.php?seccion=' . $target);
            exit;
        } catch (\DomainException $exception) {
            $error = $exception->getMessage();
        } catch (\PDOException $exception) {
            error_log('Admin account action "' . $action . '" failed: ' . $exception->getMessage());
            http_response_code(503);
            $error = ($exception->errorInfo[1] ?? null) === 1265
                ? 'La base de datos no admite este rol. Aplica las migraciones del esquema.'
                : 'No se pudo completar la operación. Inténtalo de nuevo.';
        }
    }
}

try {
    $users = $accounts->all();
} catch (\PDOException $exception) {
    error_log('Admin dashboard query failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('No se pudo cargar la lista de usuarios.');
}

$counts = array_fill_keys(\App\Models\AccountModel::ALL_ROLES, 0);
foreach ($users as $user) {
    $counts[$user['rol']] = ($counts[$user['rol']] ?? 0) + 1;
}
$sectionRole = $sections[$section]['role'];
$rows = $sectionRole === null ? [] : array_values(array_filter($users, static fn (array $u): bool => $u['rol'] === $sectionRole));

$editing = null;
$editId = filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT);
if ($sectionRole !== null && $editId !== false && $editId !== null) {
    foreach ($rows as $candidate) {
        if ($candidate['id_usuario'] === $editId) {
            $editing = $candidate;
            break;
        }
    }
}

$supportsActive = $accounts->supportsActiveFlag();
$supportsStaffProfile = $accounts->supportsStaffProfile();
$roleAvailable = $sectionRole !== null && in_array($sectionRole, $availableRoles, true);
$canCreate = $sectionRole !== null && $sectionRole !== 'alumno' && $roleAvailable;
$isStudents = $sectionRole === 'alumno';
$isInstructors = $sectionRole === 'maestro';

$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
$fullName = static fn (array $u): string => trim(($u['nombre'] ?? '') . ' ' . ($u['apellidos'] ?? '')) ?: '—';
$statusBadge = static fn (bool $active): string => $active
    ? '<span class="admin-badge admin-badge-active">Activa</span>'
    : '<span class="admin-badge admin-badge-inactive">Desactivada</span>';
$adminEmail = $escape($_SESSION['correo'] ?? '');
$pageTitle = $sections[$section]['label'];

$icons = [
    'resumen' => 'fa-gauge-high',
    'alumnos' => 'fa-user-graduate',
    'instructores' => 'fa-chalkboard-user',
    'secretaria' => 'fa-headset',
    'directores' => 'fa-building-columns',
    'administradores' => 'fa-user-shield',
];
$actor = null;
foreach ($users as $candidate) {
    if ($candidate['id_usuario'] === $actorId) {
        $actor = $candidate;
        break;
    }
}
$adminName = $actor !== null && $fullName($actor) !== '—'
    ? $fullName($actor)
    : ucfirst((string) strstr((string) ($_SESSION['correo'] ?? 'admin'), '@', true) ?: 'Administrador');
$activeAccounts = count(array_filter($users, static fn (array $u): bool => $u['activo']));
$inactiveAccounts = count($users) - $activeAccounts;
$recentAccounts = $users;
usort($recentAccounts, static fn (array $a, array $b): int => $b['id_usuario'] <=> $a['id_usuario']);
$recentAccounts = array_slice($recentAccounts, 0, 6);

$finance = null;
if ($sectionRole === null) {
    try {
        $finance = (new \App\Models\DashboardModel($pdo))->summary();
    } catch (\Throwable $exception) {
        error_log('Admin dashboard finance summary unavailable: ' . $exception->getMessage());
    }
}
$groupOpen = $sectionRole !== null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?> | Administración | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css?v=<?= (int) filemtime(__DIR__ . '/css/estilos.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body class="admin-page tc-body tc-collapsed">
    <div class="tc-topbar">
        <button class="tc-menu-btn" id="tc-menu-btn" type="button" aria-label="Mostrar u ocultar el menú" aria-controls="tc-sidebar">
            <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <a class="tc-topbar-brand" href="dashboard_admin.php">TEAM CATS</a>
        <div class="tc-topbar-user">
            <span class="tc-topbar-role">Admin</span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <button type="submit" class="tc-logout">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span>Cerrar sesión</span>
                </button>
            </form>
        </div>
    </div>

    <div class="tc-sidebar" id="tc-sidebar">
        <a class="tc-sidebar-logo" href="dashboard_admin.php">
            <img src="imagenes/Logo.png" alt="Logo de Team Cats">
            <span>Escuela de Jiu-Jitsu</span>
        </a>
        <ul class="tc-menu" aria-label="Secciones de administración">
            <li>
                <a class="tc-link<?= $section === 'resumen' ? ' is-active' : '' ?>" href="dashboard_admin.php"<?= $section === 'resumen' ? ' aria-current="page"' : '' ?>>
                    <i class="fa-solid <?= $icons['resumen'] ?>" aria-hidden="true"></i>Dashboard
                </a>
            </li>
            <li>
                <button type="button" class="tc-link tc-group-toggle<?= $groupOpen ? ' is-open' : '' ?>" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>" aria-controls="tc-group-cuentas">
                    <i class="fa-solid fa-users" aria-hidden="true"></i>Cuentas
                    <i class="fa-solid fa-chevron-down tc-chevron" aria-hidden="true"></i>
                </button>
                <ul class="tc-submenu" id="tc-group-cuentas"<?= $groupOpen ? '' : ' hidden' ?>>
                    <?php foreach ($sections as $key => $info): ?>
                        <?php if ($info['role'] === null) { continue; } ?>
                        <li>
                            <a class="tc-link tc-sublink<?= $key === $section ? ' is-active' : '' ?>" href="dashboard_admin.php?seccion=<?= $escape($key) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
                                <i class="fa-solid <?= $icons[$key] ?>" aria-hidden="true"></i>
                                <?= $escape($info['label']) ?>
                                <span class="tc-count"><?= number_format($counts[$info['role']]) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <li>
                <a class="tc-link" href="registrar_pago.php">
                    <i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Cobros
                </a>
            </li>
        </ul>
    </div>

    <div class="tc-overlay" id="tc-overlay"></div>

    <div class="tc-main">
        <main class="tc-content">
        <p class="tc-breadcrumb">
            <a href="dashboard_admin.php">Panel de administración</a>
            <?php if ($sectionRole !== null): ?>
                <i class="fa-solid fa-angle-right" aria-hidden="true"></i><span><?= $escape($pageTitle) ?></span>
            <?php endif; ?>
        </p>

        <?php if ($error !== null): ?>
            <p class="auth-error admin-message" role="alert"><?= $escape($error) ?></p>
        <?php elseif ($success !== null): ?>
            <p class="auth-success admin-message" role="status"><?= $escape($success) ?></p>
        <?php endif; ?>

        <?php if ($sectionRole === null): ?>
            <section class="tc-welcome">
                <div>
                    <span class="tc-eyebrow">TEAM CATS · ADMINISTRACIÓN</span>
                    <h1>Bienvenido, <?= $escape($adminName) ?></h1>
                    <p>Consulta el estado general de las cuentas y da seguimiento a las actividades de la escuela.</p>
                </div>
                <div class="tc-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i><?= $escape(date('d/m/Y')) ?></div>
            </section>

            <section class="tc-metrics" aria-label="Resumen de cuentas">
                <?php foreach ($sections as $key => $info): ?>
                    <?php if ($info['role'] === null) { continue; } ?>
                    <a class="tc-metric" href="dashboard_admin.php?seccion=<?= $escape($key) ?>">
                        <span class="tc-metric-icon"><i class="fa-solid <?= $icons[$key] ?>" aria-hidden="true"></i></span>
                        <span class="tc-metric-label"><?= $escape($info['label']) ?></span>
                        <span class="tc-metric-value"><?= number_format($counts[$info['role']]) ?></span>
                        <span class="tc-metric-note">Ver cuentas <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                    </a>
                <?php endforeach; ?>
            </section>

            <section class="tc-grid">
                <div class="tc-panel">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">SEGUIMIENTO</span>
                            <h2>Estado de las cuentas</h2>
                        </div>
                    </div>
                    <div class="tc-counters">
                        <div class="tc-counter"><span class="tc-dot tc-dot-ok"></span><strong><?= number_format($activeAccounts) ?></strong><span>Activas</span></div>
                        <div class="tc-counter"><span class="tc-dot tc-dot-off"></span><strong><?= number_format($inactiveAccounts) ?></strong><span>Desactivadas</span></div>
                        <div class="tc-counter"><span class="tc-dot tc-dot-all"></span><strong><?= number_format(count($users)) ?></strong><span>Total</span></div>
                    </div>
                    <?php if ($finance !== null): ?>
                        <a class="tc-callout" href="registrar_pago.php">
                            <span class="tc-callout-icon"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i></span>
                            <span class="tc-callout-text">
                                <strong>Pagos registrados este mes</strong>
                                <small><?= number_format($finance['alumnos_activos']) ?> alumnos activos · <?= number_format($finance['cargos_pendientes']) ?> cargos pendientes</small>
                            </span>
                            <span class="tc-callout-value">$<?= number_format((float) $finance['pagos_activos_mes'], 2) ?></span>
                        </a>
                    <?php else: ?>
                        <a class="tc-callout" href="registrar_pago.php">
                            <span class="tc-callout-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span>
                            <span class="tc-callout-text">
                                <strong>Cobros y pagos</strong>
                                <small>Registra pagos de alumnos activos</small>
                            </span>
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="tc-panel">
                    <div class="tc-panel-head">
                        <div>
                            <span class="tc-eyebrow">ACCESOS RÁPIDOS</span>
                            <h2>Acciones frecuentes</h2>
                        </div>
                    </div>
                    <div class="tc-actions">
                        <a href="dashboard_admin.php?seccion=instructores"><i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i><span>Crear instructor</span></a>
                        <a href="dashboard_admin.php?seccion=secretaria"><i class="fa-solid fa-headset" aria-hidden="true"></i><span>Crear secretaría</span></a>
                        <a href="dashboard_admin.php?seccion=alumnos"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i><span>Consultar alumnos</span></a>
                        <a href="registrar_pago.php"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i><span>Registrar pago</span></a>
                    </div>
                </div>
            </section>

            <section class="tc-panel">
                <div class="tc-panel-head">
                    <div>
                        <span class="tc-eyebrow">CUENTAS</span>
                        <h2>Cuentas recientes</h2>
                    </div>
                    <span class="tc-muted">Últimas 6</span>
                </div>
                <?php if ($recentAccounts === []): ?>
                    <div class="tc-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span>Aún no hay cuentas registradas.</span></div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAccounts as $account): ?>
                                    <tr>
                                        <td data-label="ID"><?= $escape($account['id_usuario']) ?></td>
                                        <td data-label="Nombre"><strong><?= $escape($fullName($account)) ?></strong></td>
                                        <td data-label="Correo"><?= $escape($account['correo']) ?></td>
                                        <td data-label="Rol"><?= $escape($roleLabels[$account['rol']] ?? $account['rol']) ?></td>
                                        <td data-label="Estado"><?= $statusBadge($account['activo']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="tc-page-head">
                <div>
                    <span class="tc-eyebrow">ADMINISTRACIÓN</span>
                    <h1><?= $escape($pageTitle) ?></h1>
                    <p><?= number_format(count($rows)) ?> cuenta(s) registradas</p>
                </div>
            </section>

            <?php if ($editing !== null): ?>
                <section class="admin-users">
                    <div class="admin-users-heading">
                        <div>
                            <h2>Editar cuenta #<?= $escape($editing['id_usuario']) ?></h2>
                            <p class="admin-hint">El ID y el historial no cambian.</p>
                        </div>
                        <a href="dashboard_admin.php?seccion=<?= $escape($section) ?>">Cerrar</a>
                    </div>
                    <form method="post" action="dashboard_admin.php" class="admin-account-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_account">
                        <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                        <input type="hidden" name="id_usuario" value="<?= $escape($editing['id_usuario']) ?>">
                        <label>Correo<input type="email" name="correo" maxlength="100" required value="<?= $escape($editing['correo']) ?>"></label>
                        <?php if ($isStudents || $isInstructors || $supportsStaffProfile): ?>
                            <label>Nombre<input type="text" name="nombre" maxlength="100" value="<?= $escape($editing['nombre']) ?>"></label>
                            <label>Apellidos<input type="text" name="apellidos" maxlength="100" value="<?= $escape($editing['apellidos']) ?>"></label>
                            <label>Teléfono<input type="text" name="telefono" maxlength="20" value="<?= $escape($editing['telefono']) ?>"></label>
                            <label>Fecha de nacimiento<input type="date" name="fecha_nacimiento" value="<?= $escape($editing['fecha_nacimiento']) ?>"></label>
                        <?php else: ?>
                            <p class="admin-hint">Los datos personales de esta cuenta estarán disponibles al aplicar la migración 20261120_006.</p>
                        <?php endif; ?>
                        <button type="submit" class="admin-save">Guardar cambios</button>
                    </form>
                    <form method="post" action="dashboard_admin.php" class="admin-account-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                        <input type="hidden" name="id_usuario" value="<?= $escape($editing['id_usuario']) ?>">
                        <label>Cambiar rol (la cuenta pasará a su sección)
                            <select name="rol" required>
                                <?php foreach (array_unique(array_merge($availableRoles, [$editing['rol']])) as $role): ?>
                                    <option value="<?= $escape($role) ?>" <?= $editing['rol'] === $role ? 'selected' : '' ?>><?= $escape($roleLabels[$role] ?? $role) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="admin-save">Cambiar rol</button>
                    </form>
                    <form method="post" action="dashboard_admin.php?seccion=<?= $escape($section) ?>&amp;editar=<?= $escape($editing['id_usuario']) ?>" class="admin-account-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                        <input type="hidden" name="id_usuario" value="<?= $escape($editing['id_usuario']) ?>">
                        <label>Nueva contraseña
                            <input type="password" name="nueva_password" minlength="8" maxlength="72" required autocomplete="new-password">
                        </label>
                        <label>Confirmar contraseña
                            <input type="password" name="confirmar_password" minlength="8" maxlength="72" required autocomplete="new-password">
                        </label>
                        <p class="admin-hint">Úsalo cuando el usuario olvide su contraseña o no pueda entrar. Mínimo 8 caracteres.</p>
                        <button type="submit" class="admin-save"><i class="fa-solid fa-key" aria-hidden="true"></i> Restablecer contraseña</button>
                    </form>
                </section>
            <?php endif; ?>

            <section class="admin-users">
                <?php if ($isStudents): ?>
                    <p class="admin-hint admin-section-note">Los alumnos se registran desde el registro público; aquí puedes consultar y editar sus datos.</p>
                <?php elseif (!$roleAvailable): ?>
                    <p class="admin-hint admin-section-note">La base de datos aún no admite este rol. Se habilitará al aplicar las migraciones del esquema (requiere autorización).</p>
                <?php else: ?>
                    <details <?= $formData !== [] ? 'open' : '' ?>>
                        <summary class="admin-users-heading"><h2>Crear <?= $escape($sections[$section]['singular']) ?></h2></summary>
                        <form method="post" action="dashboard_admin.php" class="admin-account-form" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_account">
                            <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                            <input type="hidden" name="rol" value="<?= $escape($sectionRole) ?>">
                            <label>Correo<input type="email" name="correo" maxlength="100" required value="<?= $escape($formData['correo'] ?? '') ?>"></label>
                            <label>Contraseña inicial<input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"></label>
                            <label>Nombre<input type="text" name="nombre" maxlength="100" <?= $isInstructors ? 'required' : '' ?> value="<?= $escape($formData['nombre'] ?? '') ?>"></label>
                            <label>Apellidos<input type="text" name="apellidos" maxlength="100" <?= $isInstructors ? 'required' : '' ?> value="<?= $escape($formData['apellidos'] ?? '') ?>"></label>
                            <?php if (!$isInstructors): ?>
                                <label>Teléfono<input type="text" name="telefono" maxlength="20" value="<?= $escape($formData['telefono'] ?? '') ?>"></label>
                                <label>Fecha de nacimiento<input type="date" name="fecha_nacimiento" value="<?= $escape($formData['fecha_nacimiento'] ?? '') ?>"></label>
                            <?php endif; ?>
                            <p class="admin-hint"><?= $isInstructors
                                ? 'Se crea también su perfil de profesor. Nombre y apellidos son obligatorios.'
                                : 'No requiere ficha de alumno ni cinturón.' ?></p>
                            <button type="submit" class="admin-save">Crear cuenta</button>
                        </form>
                    </details>
                <?php endif; ?>
            </section>

            <section class="admin-users">
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <?php if ($isStudents): ?>
                                <tr><th>ID</th><th>Matrícula</th><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Nacimiento</th><th>Cinturón</th><th>Estado</th><th>Acciones</th></tr>
                            <?php elseif ($isInstructors): ?>
                                <tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Estado</th><th>Acciones</th></tr>
                            <?php else: ?>
                                <tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Estado</th><th>Acciones</th></tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $user): ?>
                                <tr>
                                    <td data-label="ID"><?= $escape($user['id_usuario']) ?></td>
                                    <?php if ($isStudents): ?>
                                        <td data-label="Matrícula"><?= $escape($user['matricula'] ?? '—') ?></td>
                                    <?php endif; ?>
                                    <td data-label="Nombre"><?= $escape($fullName($user)) ?></td>
                                    <?php if ($isStudents): ?>
                                        <td data-label="Teléfono"><?= $escape($user['telefono'] ?: '—') ?></td>
                                    <?php endif; ?>
                                    <td data-label="Correo"><?= $escape($user['correo']) ?></td>
                                    <?php if ($isStudents): ?>
                                        <td data-label="Nacimiento"><?= $escape($user['fecha_nacimiento'] ?: '—') ?></td>
                                        <td data-label="Cinturón"><?= $escape($user['cinturon'] ?: 'Sin asignar') ?></td>
                                    <?php endif; ?>
                                    <td data-label="Estado"><?= $statusBadge($user['activo']) ?></td>
                                    <td data-label="Acciones">
                                        <div class="admin-user-actions">
                                            <a class="admin-save" href="dashboard_admin.php?seccion=<?= $escape($section) ?>&amp;editar=<?= $escape($user['id_usuario']) ?>">Ver / editar</a>
                                            <form method="post" action="dashboard_admin.php">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="set_active">
                                                <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                                                <input type="hidden" name="id_usuario" value="<?= $escape($user['id_usuario']) ?>">
                                                <input type="hidden" name="activo" value="<?= $user['activo'] ? '0' : '1' ?>">
                                                <button type="submit" class="<?= $user['activo'] ? 'admin-delete' : 'admin-save' ?>"
                                                    <?= (!$supportsActive || ($user['activo'] && $user['id_usuario'] === $actorId)) ? 'disabled' : '' ?>
                                                    <?= $supportsActive ? '' : 'title="Requiere la migración 20261120_006"' ?>>
                                                    <?= $user['activo'] ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            </form>
                                            <form method="post" action="dashboard_admin.php" data-confirm="¿Eliminar la cuenta de <?= $escape($user['correo']) ?>? Esta acción no se puede deshacer. La ficha y el historial se conservan.">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_account">
                                                <input type="hidden" name="seccion" value="<?= $escape($section) ?>">
                                                <input type="hidden" name="id_usuario" value="<?= $escape($user['id_usuario']) ?>">
                                                <button type="submit" class="admin-delete"
                                                    <?= $user['id_usuario'] === $actorId ? 'disabled title="No puedes eliminar tu propia cuenta"' : '' ?>>
                                                    <i class="fa-regular fa-trash-can" aria-hidden="true"></i> Eliminar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($rows === []): ?>
                                <tr><td colspan="<?= $isStudents ? 9 : 5 ?>" class="admin-empty">Aún no hay cuentas en esta sección.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        </main>
        <footer class="tc-footer">Team Cats · Escuela de Jiu-Jitsu</footer>
    </div>
    <script src="js/panel-shell.js?v=<?= (int) filemtime(__DIR__ . '/js/panel-shell.js') ?>"></script>
</body>
</html>
