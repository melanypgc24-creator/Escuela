<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth_check.php';
require_role('maestro');

$csrfToken = htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
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
<body class="auth-page">
    <main class="auth-card">
        <h1>Panel de maestro</h1>
        <p>Bienvenido, <?= $email ?>.</p>
        <p>Tu cuenta está vinculada como maestro.</p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </main>
</body>
</html>
