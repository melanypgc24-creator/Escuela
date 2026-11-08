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
        } elseif (!is_string($password) || mb_strlen($password, 'UTF-8') < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif (!is_string($passwordConfirmation) || !hash_equals($password, $passwordConfirmation)) {
            $error = 'La contraseña y su confirmación no coinciden.';
        } else {
            try {
                $pdo = require __DIR__ . '/conexion.php';
                $email = trim($email);
                $existingUser = $pdo->prepare(
                    'SELECT 1 FROM escuela_usuarios WHERE correo = :correo LIMIT 1'
                );
                $existingUser->execute(['correo' => $email]);

                if ($existingUser->fetchColumn() !== false) {
                    $error = 'Ese correo ya tiene una cuenta. Inicia sesión o usa otro correo.';
                } else {
                    $statement = $pdo->prepare(
                        "INSERT INTO escuela_usuarios
                            (correo, `password`, rol, id_alumno, id_profesor)
                         VALUES
                            (:correo, :password, 'alumno', NULL, NULL)"
                    );
                    $statement->execute([
                        'correo' => $email,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    session_regenerate_id(true);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $success = true;
                }
            } catch (\PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'No se pudo completar el registro. Comprueba que el correo no esté registrado.';
                } else {
                    error_log('Registration database error: ' . $exception->getMessage());
                    http_response_code(503);
                    $error = 'No se pudo completar el registro. Verifica la conexión y la estructura de la base de datos.';
                }
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
    <title>Crear cuenta | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-home-link" href="./">Volver al inicio</a>
        <h1>Crear cuenta</h1>
        <p>Crea tu cuenta de alumno en Team Cats.</p>

        <?php if ($error !== null): ?>
            <p class="auth-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($success): ?>
            <p class="auth-success" role="status">Cuenta creada. Ya puedes <a href="login.php">iniciar sesión</a>.</p>
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
                <input id="password" name="password" type="password" autocomplete="new-password" required
                    aria-describedby="password-validation">
                <span id="password-validation" class="auth-field-feedback" data-validation-for="password" aria-live="polite"></span>

                <label for="password_confirmation">Confirmar contraseña</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                    autocomplete="new-password" required aria-describedby="confirmation-validation">
                <span id="confirmation-validation" class="auth-field-feedback" data-validation-for="password_confirmation" aria-live="polite"></span>

                <button type="submit">Crear cuenta</button>
            </form>
        <?php endif; ?>
        <p class="auth-register-link">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
    </main>
    <script src="js/auth-validation.js" defer></script>
</body>
</html>
