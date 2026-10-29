<?php
$adminName = htmlspecialchars((string) ($_SESSION['admin_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel administrativo | Team Cats</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body class="auth-page">
    <main class="auth-card dashboard-card">
        <h1>Panel administrativo</h1>
        <p>Bienvenido, <?= $adminName ?>.</p>
        <p>Has iniciado sesión correctamente.</p>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button type="submit">Cerrar sesión</button>
        </form>
    </main>
</body>
</html>
