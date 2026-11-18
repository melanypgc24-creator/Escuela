<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$error = null;
$success = false;
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($csrfToken) || !is_string($expectedToken) || !hash_equals($expectedToken, $csrfToken)) {
        http_response_code(403);
        $error = 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $email = $_POST['correo'] ?? '';
        $password = $_POST['password'] ?? '';
        $passwordConfirmation = $_POST['password_confirmation'] ?? '';

        if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || strlen(trim($email)) > 100) {
            $error = 'Escribe un correo válido de máximo 100 caracteres.';
        } elseif (!is_string($password) || mb_strlen($password, 'UTF-8') < 8
            || (preg_match('/\d/', $password) !== 1
                && preg_match('/[^\p{L}\p{N}\s]/u', $password) !== 1)) {
            $error = 'La contraseña debe tener al menos 8 caracteres y un número o carácter especial.';
        } elseif (!is_string($passwordConfirmation) || !hash_equals($password, $passwordConfirmation)) {
            $error = 'La contraseña y su confirmación no coinciden.';
        } else {
            try {
                $pdo = require __DIR__ . '/conexion.php';
                $email = trim($email);
                \App\Core\DatabaseLock::run(
                    $pdo,
                    \App\Core\LegacyIdAllocator::lockNameForTable('usuarios'),
                    static function () use ($pdo, $email, $password, &$error, &$success): void {
                        $pdo->beginTransaction();
                        try {
                            $existingUser = $pdo->prepare(
                                'SELECT id_usuario FROM usuarios WHERE correo = :correo LIMIT 1 FOR UPDATE'
                            );
                            $existingUser->execute(['correo' => $email]);

                            if ($existingUser->fetchColumn() !== false) {
                                $pdo->rollBack();
                                $error = 'Ese correo ya tiene una cuenta. Inicia sesión o usa otro correo.';
                                return;
                            }

                            $usesAutoIncrement = \App\Core\TableSchemaInspector::usesAutoIncrement($pdo, 'usuarios', 'id_usuario');
                            if ($usesAutoIncrement) {
                                $statement = $pdo->prepare(
                                    "INSERT INTO usuarios
                                        (correo, `password`, rol, id_alumno, id_profesor)
                                     VALUES
                                        (:correo, :password, 'alumno', NULL, NULL)"
                                );
                                $statement->execute([
                                    'correo' => $email,
                                    'password' => password_hash($password, PASSWORD_DEFAULT),
                                ]);
                            } else {
                                $nextId = \App\Core\LegacyIdAllocator::nextId($pdo, 'usuarios', 'id_usuario');
                                $statement = $pdo->prepare(
                                    "INSERT INTO usuarios
                                        (id_usuario, correo, `password`, rol, id_alumno, id_profesor)
                                     VALUES
                                        (:id_usuario, :correo, :password, 'alumno', NULL, NULL)"
                                );
                                $statement->execute([
                                    'id_usuario' => $nextId,
                                    'correo' => $email,
                                    'password' => password_hash($password, PASSWORD_DEFAULT),
                                ]);
                            }

                            $pdo->commit();
                            session_regenerate_id(true);
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
