<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = null;
$success = false;

if (!empty($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
    exit;
}

header('Cache-Control: no-store');
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$destinations = [
    'alumno' => 'panel_alumno.php',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($csrfToken) || !is_string($expectedToken) || !hash_equals($expectedToken, $csrfToken)) {
        http_response_code(403);
        $error = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (isset($_POST['rol']) || isset($_POST['role']) || isset($_POST['id_alumno']) || isset($_POST['id_profesor'])) {
        // El registro público solo crea alumnos; el rol nunca se toma de la solicitud.
        http_response_code(403);
        $error = 'El registro público solo está disponible para alumnos.';
    } else {
        $firstName = $_POST['nombre'] ?? '';
        $lastName = $_POST['apellidos'] ?? '';
        $phone = $_POST['telefono'] ?? '';
        $birthDate = $_POST['fecha_nacimiento'] ?? '';
        $email = $_POST['correo'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirmation = $_POST['password_confirmation'] ?? '';

        if (!is_string($firstName) || trim($firstName) === '' || mb_strlen(trim($firstName), 'UTF-8') > 100
            || !is_string($lastName) || trim($lastName) === '' || mb_strlen(trim($lastName), 'UTF-8') > 100) {
            $error = 'Escribe tu nombre y apellidos (máximo 100 caracteres cada uno).';
        } elseif (!is_string($phone) || trim($phone) === '' || mb_strlen(trim($phone), 'UTF-8') > 20) {
            $error = 'Escribe un teléfono válido de máximo 20 caracteres.';
        } elseif (!is_string($birthDate) || !\App\Core\Request::isValidBirthDate($birthDate)) {
            $error = 'Selecciona una fecha de nacimiento válida.';
        } elseif (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || strlen(trim($email)) > 100) {
            $error = 'Escribe un correo válido de máximo 100 caracteres.';
        } elseif (!is_string($password) || mb_strlen($password, 'UTF-8') < 8
            || (preg_match('/\d/', $password) !== 1
                && preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1)) {
            $error = 'La contraseña debe tener al menos 8 caracteres y un número o carácter especial.';
        } elseif (!is_string($passwordConfirmation) || !hash_equals($password, $passwordConfirmation)) {
            $error = 'La contraseña y su confirmación no coinciden.';
        } else {
            $registeredUserId = null;
            try {
                $pdo = require __DIR__ . '/config/conexion.php';
                $email = trim($email);
                $firstName = trim($firstName);
                $lastName = trim($lastName);
                $phone = trim($phone);
                \App\Core\DatabaseLock::run(
                    $pdo,
                    \App\Core\LegacyIdAllocator::lockNameForTable('usuarios'),
                    static function () use ($pdo, $email, $password, $firstName, $lastName, $phone, $birthDate, &$error, &$success, &$registeredUserId): void {
                        $pdo->beginTransaction();
                        try {
                            $hasColumn = static function (\PDO $connection, string $table, string $column): bool {
                                $statement = $connection->prepare(
                                    "SELECT COUNT(*)
                                     FROM information_schema.columns
                                     WHERE table_schema = DATABASE()
                                       AND table_name = :table_name
                                       AND column_name = :column_name"
                                );
                                $statement->execute([
                                    'table_name' => $table,
                                    'column_name' => $column,
                                ]);
                                return ((int) $statement->fetchColumn()) > 0;
                            };
                            $existingUser = $pdo->prepare(
                                'SELECT id_usuario FROM usuarios WHERE correo = :correo LIMIT 1 FOR UPDATE'
                            );
                            $existingUser->execute(['correo' => $email]);

                            if ($existingUser->fetchColumn() !== false) {
                                $pdo->rollBack();
                                $error = 'Ese correo ya tiene una cuenta. Inicia sesión o usa otro correo.';
                                return;
                            }

                            $alumnoHasMatricula = $hasColumn($pdo, 'alumnos', 'matricula');
                            $alumnoColumns = ['nombre', 'apellidos', 'telefono', 'fecha_nacimiento', 'fecha_ingreso', 'estatus', 'id_cinturon'];
                            $alumnoParams = [
                                'nombre' => $firstName,
                                'apellidos' => $lastName,
                                'telefono' => $phone,
                                'fecha_nacimiento' => $birthDate,
                                'fecha_ingreso' => date('Y-m-d'),
                                'estatus' => 1,
                                'id_cinturon' => null,
                            ];
                            if ($alumnoHasMatricula) {
                                $alumnoColumns[] = 'matricula';
                                $alumnoParams['matricula'] = 'TMP-' . bin2hex(random_bytes(8));
                            }
                            if ($hasColumn($pdo, 'alumnos', 'correo_contacto')) {
                                $alumnoColumns[] = 'correo_contacto';
                                $alumnoParams['correo_contacto'] = $email;
                            }
                            if ($hasColumn($pdo, 'alumnos', 'direccion')) {
                                $alumnoColumns[] = 'direccion';
                                $alumnoParams['direccion'] = null;
                            }
                            if ($hasColumn($pdo, 'alumnos', 'categoria')) {
                                $alumnoColumns[] = 'categoria';
                                $alumnoParams['categoria'] = null;
                            }
                            if ($hasColumn($pdo, 'alumnos', 'contacto_emergencia_nombre')) {
                                $alumnoColumns[] = 'contacto_emergencia_nombre';
                                $alumnoParams['contacto_emergencia_nombre'] = null;
                            }
                            if ($hasColumn($pdo, 'alumnos', 'contacto_emergencia_telefono')) {
                                $alumnoColumns[] = 'contacto_emergencia_telefono';
                                $alumnoParams['contacto_emergencia_telefono'] = null;
                            }

                            $alumnoUsesAutoIncrement = \App\Core\TableSchemaInspector::usesAutoIncrement($pdo, 'alumnos', 'id_alumno');
                            if (!$alumnoUsesAutoIncrement) {
                                $nextStudentId = (int) $pdo->query(
                                    'SELECT COALESCE(MAX(id_alumno), 0) + 1 FROM alumnos'
                                )->fetchColumn();
                                array_unshift($alumnoColumns, 'id_alumno');
                                $alumnoParams['id_alumno'] = $nextStudentId;
                            }
                            $alumnoSql = sprintf(
                                'INSERT INTO alumnos (%s) VALUES (%s)',
                                implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $alumnoColumns)),
                                implode(', ', array_map(static fn (string $column): string => ':' . $column, $alumnoColumns))
                            );
                            $createStudent = $pdo->prepare($alumnoSql);
                            $createStudent->execute($alumnoParams);
                            $studentId = $alumnoUsesAutoIncrement ? (int) $pdo->lastInsertId() : $nextStudentId;
                            if ($studentId < 1) {
                                throw new \RuntimeException('La base de datos no devolvió un identificador de alumno válido.');
                            }
                            if ($alumnoHasMatricula) {
                                $updateMatricula = $pdo->prepare(
                                    'UPDATE alumnos SET matricula = :matricula WHERE id_alumno = :id_alumno'
                                );
                                $updateMatricula->execute([
                                    'matricula' => 'TC-' . str_pad((string) $studentId, 6, '0', STR_PAD_LEFT),
                                    'id_alumno' => $studentId,
                                ]);
                            }

                            $usesAutoIncrement = \App\Core\TableSchemaInspector::usesAutoIncrement($pdo, 'usuarios', 'id_usuario');
                            if ($usesAutoIncrement) {
                                $statement = $pdo->prepare(
                                    "INSERT INTO usuarios
                                        (correo, `password`, rol, id_alumno, id_profesor)
                                     VALUES
                                        (:correo, :password, 'alumno', :id_alumno, NULL)"
                                );
                                $statement->execute([
                                    'correo' => $email,
                                    'password' => password_hash($password, PASSWORD_DEFAULT),
                                    'id_alumno' => $studentId,
                                ]);
                                $userId = (int) $pdo->lastInsertId();
                                if ($userId < 1) {
                                    throw new \RuntimeException('La base de datos no devolvió un identificador de usuario válido.');
                                }
                            } else {
                                $nextId = \App\Core\LegacyIdAllocator::nextId($pdo, 'usuarios', 'id_usuario');
                                $statement = $pdo->prepare(
                                    "INSERT INTO usuarios
                                        (id_usuario, correo, `password`, rol, id_alumno, id_profesor)
                                     VALUES
                                        (:id_usuario, :correo, :password, 'alumno', :id_alumno, NULL)"
                                );
                                $statement->execute([
                                    'id_usuario' => $nextId,
                                    'correo' => $email,
                                    'password' => password_hash($password, PASSWORD_DEFAULT),
                                    'id_alumno' => $studentId,
                                ]);
                                $userId = $nextId;
                            }

                            $pdo->commit();
                            $registeredUserId = $userId;
                            session_regenerate_id(true);
                            $_SESSION['id_usuario'] = $userId;
                            $_SESSION['correo'] = $email;
                            $_SESSION['rol'] = 'alumno';
                            $_SESSION['id_alumno'] = $studentId;
                            $_SESSION['id_profesor'] = null;
                            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                            $success = true;
                        } catch (\Throwable $exception) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $exception;
                        }
                    }
                );
                if ($success && is_int($registeredUserId)) {
                    try {
                        (new \App\Models\AuditModel($pdo))->record(
                            $registeredUserId,
                            'registrar_cuenta',
                            'usuarios',
                            $registeredUserId,
                            ['rol' => 'alumno', 'id_alumno' => $_SESSION['id_alumno'] ?? null]
                        );
                    } catch (\PDOException $auditException) {
                        error_log('Registration audit skipped: ' . $auditException->getMessage());
                    }
                    header('Location: ' . $destinations['alumno']);
                    exit;
                }
            } catch (\PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'No se pudo completar el registro. Comprueba que el correo no esté registrado.';
                } else {
                    error_log('Registration database error: ' . $exception->getMessage());
                    http_response_code(503);
                    $error = 'No se pudo completar el registro. Verifica la conexión y la estructura de la base de datos.';
                }
            } catch (\App\Core\MigrationException $exception) {
                error_log('Registration lock error: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'No se pudo completar el registro en este momento. Inténtalo de nuevo.';
            } catch (\Throwable $exception) {
                error_log('Registration failed: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo completar el registro.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#20252d">
    <title>Crear cuenta | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css?v=6">
</head>
<body class="auth-page login-page register-page">
    <main class="register-layout">
        <section class="login-intro" aria-labelledby="register-title">
            <a class="login-brand" href="./">
                <img src="imagenes/Logo.png" alt="" width="58" height="58">
                <span>TEAM CATS<small>JIU-JITSU</small></span>
            </a>
            <p class="login-eyebrow">Únete a Team Cats</p>
            <h1 id="register-title">Crea tu cuenta.<br><span>Empieza a avanzar.</span></h1>
            <p class="login-description">Forma parte de nuestra comunidad y comienza tu camino en el Jiu-Jitsu.</p>
            <a class="login-home-link" href="./">Volver al sitio</a>
        </section>

        <section class="auth-card login-card register-card" aria-labelledby="form-title">
            <div class="login-card-heading">
                <p class="login-eyebrow">Nueva cuenta</p>
                <h2 id="form-title">Crear cuenta</h2>
                <p>Completa tus datos para registrarte.</p>
            </div>
        <?php if ($error !== null): ?>
            <p class="auth-error login-message" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($success): ?>
            <p class="auth-success login-message" role="status">Cuenta creada. Ya puedes <a href="login.php">iniciar sesión</a>.</p>
        <?php endif; ?>

        <?php if (!$success): ?>
            <form method="post" action="register.php" data-auth-validation="register" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="nombre">Nombre</label>
                <input id="nombre" name="nombre" type="text" autocomplete="given-name" required maxlength="100"
                    value="<?= htmlspecialchars((string) ($_POST['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <label for="apellidos">Apellidos</label>
                <input id="apellidos" name="apellidos" type="text" autocomplete="family-name" required maxlength="100"
                    value="<?= htmlspecialchars((string) ($_POST['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <label for="telefono">Teléfono</label>
                <input id="telefono" name="telefono" type="tel" autocomplete="tel" required maxlength="20"
                    value="<?= htmlspecialchars((string) ($_POST['telefono'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <label for="fecha_nacimiento">Fecha de nacimiento</label>
                <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" required max="<?= date('Y-m-d') ?>"
                    value="<?= htmlspecialchars((string) ($_POST['fecha_nacimiento'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <label for="correo">Correo electrónico</label>
                <input id="correo" name="correo" type="text" inputmode="email" autocomplete="email"
                    autocapitalize="none" spellcheck="false" required maxlength="100"
                    aria-describedby="correo-validation"
                    value="<?= htmlspecialchars((string) ($_POST['correo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <span id="correo-validation" class="auth-field-feedback" data-validation-for="correo" aria-live="polite"></span>

                <label for="password">Contraseña</label>
                <div class="password-input-wrap">
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required
                        aria-describedby="password-validation">
                    <button class="password-toggle" type="button" data-password-toggle
                        aria-controls="password" aria-label="Mostrar contraseña" aria-pressed="false"
                        data-show-label="Mostrar contraseña" data-hide-label="Ocultar contraseña"
                        title="Mostrar contraseña">
                        <svg data-eye-closed viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                            <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c5.2 0 8.7 4.2 10 7-.5 1-1.3 2.1-2.4 3.1M6.2 6.2C3.9 7.6 2.5 9.7 2 12c.5 1.1 1.4 2.4 2.8 3.6A10.7 10.7 0 0 0 12 19c1.2 0 2.3-.2 3.3-.6"/>
                        </svg>
                        <svg data-eye-open viewBox="0 0 24 24" aria-hidden="true" hidden>
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <span id="password-validation" class="auth-field-feedback" data-validation-for="password" aria-live="polite"></span>

                <label for="password_confirmation">Confirmar contraseña</label>
                <div class="password-input-wrap">
                    <input id="password_confirmation" name="password_confirmation" type="password"
                        autocomplete="new-password" minlength="8" required aria-describedby="confirmation-validation">
                    <button class="password-toggle" type="button" data-password-toggle
                        aria-controls="password_confirmation" aria-label="Mostrar confirmación de contraseña"
                        data-show-label="Mostrar confirmación de contraseña"
                        data-hide-label="Ocultar confirmación de contraseña"
                        aria-pressed="false" title="Mostrar confirmación de contraseña">
                        <svg data-eye-closed viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                            <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c5.2 0 8.7 4.2 10 7-.5 1-1.3 2.1-2.4 3.1M6.2 6.2C3.9 7.6 2.5 9.7 2 12c.5 1.1 1.4 2.4 2.8 3.6A10.7 10.7 0 0 0 12 19c1.2 0 2.3-.2 3.3-.6"/>
                        </svg>
                        <svg data-eye-open viewBox="0 0 24 24" aria-hidden="true" hidden>
                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <span id="confirmation-validation" class="auth-field-feedback" data-validation-for="password_confirmation" aria-live="polite"></span>

                <button type="submit" class="login-submit">Crear cuenta</button>
            </form>
        <?php endif; ?>
        <p class="auth-register-link">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
        </section>
    </main>
    <script src="js/auth-validation.js?v=3" defer></script>
</body>
</html>
