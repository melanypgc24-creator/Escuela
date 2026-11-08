<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Cache-Control: no-store');

$error = null;
$email = '';
$destinations = [
    'administrador' => 'dashboard_admin.php',
    'maestro' => 'panel_maestro.php',
    'alumno' => 'panel_alumno.php',
];

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if (isset($_SESSION['id_usuario'])) {
    $currentRole = $_SESSION['rol'] ?? '';
    if (isset($destinations[$currentRole])) {
        header('Location: ' . $destinations[$currentRole]);
        exit;
    }

    unset(
        $_SESSION['id_usuario'],
        $_SESSION['correo'],
        $_SESSION['rol'],
        $_SESSION['id_alumno'],
        $_SESSION['id_profesor']
    );
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $providedToken = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($providedToken) || !is_string($expectedToken)
        || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $emailInput = $_POST['correo'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!is_string($emailInput) || !is_string($password)) {
            $error = 'Escribe tu correo y contraseña.';
        } else {
            $email = trim($emailInput);
            $isLocalTestEmail = preg_match('/^[^@\s]+@localhost$/iD', $email) === 1;
            $isValidEmail = strlen($email) <= 100
                && (filter_var($email, FILTER_VALIDATE_EMAIL) !== false || $isLocalTestEmail);

            if (!$isValidEmail) {
                $error = 'Escribe un correo electrónico válido.';
            } elseif ($password === '' || strlen($password) > 4096) {
                $error = 'Escribe una contraseña válida.';
            } else {
                try {
                    $pdo = require __DIR__ . '/config/conexion.php';
                    $statement = $pdo->prepare(
                        'SELECT id_usuario, correo, `password`, rol, id_alumno, id_profesor
                         FROM escuela_usuarios
                         WHERE correo = :correo
                         LIMIT 1'
                    );
                    $statement->execute(['correo' => $email]);
                    $user = $statement->fetch();

                    if ($user === false || !is_string($user['password'])
                        || !password_verify($password, $user['password'])) {
                        $error = 'El correo o la contraseña no son correctos.';
                    } elseif (!isset($destinations[$user['rol']])) {
                        error_log('Login rejected: user has an invalid role.');
                        http_response_code(403);
                        $error = 'Tu cuenta tiene un rol no válido. Contacta al administrador.';
                    } else {
                        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                            $rehash = $pdo->prepare(
                                'UPDATE escuela_usuarios
                                 SET `password` = :password
                                 WHERE id_usuario = :id_usuario'
                            );
                            $rehash->execute([
                                'password' => password_hash($password, PASSWORD_DEFAULT),
                                'id_usuario' => $user['id_usuario'],
                            ]);
                        }

                        session_regenerate_id(true);
                        $_SESSION['id_usuario'] = (int) $user['id_usuario'];
                        $_SESSION['correo'] = $user['correo'];
                        $_SESSION['rol'] = $user['rol'];
                        $_SESSION['id_alumno'] = $user['id_alumno'] === null
                            ? null
                            : (int) $user['id_alumno'];
                        $_SESSION['id_profesor'] = $user['id_profesor'] === null
                            ? null
                            : (int) $user['id_profesor'];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        header('Location: ' . $destinations[$user['rol']]);
                        exit;
                    }
                } catch (\PDOException $exception) {
                    error_log('Login database error: ' . $exception->getMessage());
                    http_response_code(503);
                    $error = 'No se pudo validar el acceso en este momento. Inténtalo más tarde.';
                }
            }
        }
    }
}

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#20252d">
    <title>Iniciar sesión | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="auth-page login-page">
    <main class="login-layout">
        <section class="login-intro" aria-labelledby="login-title">
            <a class="login-brand" href="./">
                <img src="imagenes/Logo.png" alt="" width="58" height="58">
                <span>TEAM CATS<small>JIU-JITSU</small></span>
            </a>
            <p class="login-eyebrow">Qué bueno tenerte de vuelta</p>
            <h1 id="login-title">Entrena con propósito.<br><span>Avanza con confianza.</span></h1>
            <p class="login-description">Inicia sesión para continuar en tu espacio de Team Cats.</p>
            <a class="login-home-link" href="./">Volver al sitio</a>
        </section>

        <section class="auth-card login-card" aria-labelledby="form-title">
            <div class="login-card-heading">
                <p class="login-eyebrow">Tu cuenta</p>
                <h2 id="form-title">Iniciar sesión</h2>
                <p>Ingresa tu correo electrónico y contraseña.</p>
            </div>

            <?php if ($error !== null): ?>
                <p class="auth-error login-message" role="alert"><?= $escape($error) ?></p>
            <?php endif; ?>

            <form method="post" action="login.php" data-auth-validation="login" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $escape((string) $_SESSION['csrf_token']) ?>">

                <label for="correo">Correo institucional</label>
                <input
                    id="correo"
                    name="correo"
                    type="text"
                    inputmode="email"
                    autocomplete="username"
                    autocapitalize="none"
                    spellcheck="false"
                    maxlength="100"
                    required
                    autofocus
                    placeholder="tu-correo@itmatamoros.edu.mx"
                    value="<?= $escape($email) ?>"
                    aria-describedby="correo-validation"
                >
                <span id="correo-validation" class="login-field-help" data-validation-for="correo" aria-live="polite">
                </span>

                <label for="password">Contraseña</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    placeholder="Contraseña"
                    aria-describedby="password-validation"
                >
                <span id="password-validation" class="login-field-help" data-validation-for="password" aria-live="polite"></span>

                <button type="submit" class="login-submit">Entrar</button>
            </form>

            <p class="auth-register-link">¿Aún no tienes cuenta? <a href="register.php">Crear cuenta</a></p>
        </section>
    </main>
    <script src="js/auth-validation.js" defer></script>
</body>
</html>
