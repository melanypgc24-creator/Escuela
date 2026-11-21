<?php
// Calcula la ruta base relativa al document root (ej. /Escuela)
$docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$baseUrl = ($docRoot !== '' && str_starts_with($appRoot, $docRoot))
    ? substr($appRoot, strlen($docRoot))
    : '/Escuela';
if ($baseUrl === '') {
    $baseUrl = '';
}
$estilosCss = dirname(__DIR__) . '/css/estilos.css';
$estilosVersion = is_file($estilosCss) ? (int) filemtime($estilosCss) : 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Escuela de Jujutsu</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/estilos.css?v=<?= $estilosVersion ?>">
    <?php if (isset($paginaActual) && $paginaActual === 'inicio'): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/inicio.css?v=<?= filemtime(dirname(__DIR__) . '/css/inicio.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="<?= isset($paginaActual) && $paginaActual === 'inicio' ? 'pagina-inicio' : '' ?>">
    <header>
        <h1><img src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/imagenes/Logo.png" class="logotipo" alt="Logo de Team Cats"> Team Cats</h1>
        <nav aria-label="Navegación principal">
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/#inicio"><i class="fa-solid fa-house" aria-hidden="true"></i> Inicio</a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/#sobre-nosotros"><i class="fa-solid fa-users" aria-hidden="true"></i> Sobre Nosotros</a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/#clases"><i class="fa-solid fa-clipboard" aria-hidden="true"></i> Clases</a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/login.php"><i class="fa-solid fa-circle-user"></i> Iniciar sesión</a>
            <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/register.php">Crear cuenta</a>
        </nav>
    </header>