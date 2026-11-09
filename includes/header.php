<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Escuela de Jujutsu</title>
    <link rel="stylesheet" href="css/estilos.css">
    <?php if (isset($paginaActual) && $paginaActual === 'inicio'): ?>
        <link rel="stylesheet" href="css/inicio.css?v=<?= filemtime(__DIR__ . '/../css/inicio.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="<?= isset($paginaActual) && $paginaActual === 'inicio' ? 'pagina-inicio' : '' ?>">
    <header>
        <h1><img src="imagenes/Logo.png" class="logotipo" alt="Logo de Team Cats"> Team Cats</h1>
        <nav aria-label="Navegación principal">
            <a href="./#inicio"><i class="fa-solid fa-house" aria-hidden="true"></i> Inicio</a>
            <a href="./#sobre-nosotros"><i class="fa-solid fa-users" aria-hidden="true"></i> Sobre Nosotros</a>
            <a href="./#clases"><i class="fa-solid fa-clipboard" aria-hidden="true"></i> Clases</a>
            <a href="login.php"><i class="fa-solid fa-circle-user"></i> Iniciar sesión</a>
            <a href="register.php">Crear cuenta</a>
        </nav>
    </header>
