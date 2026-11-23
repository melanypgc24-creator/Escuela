<?php
require_once dirname(__DIR__) . '/bootstrap.php';

// Calcula la ruta base relativa al document root (ej. /Escuela)
$docRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$baseUrl = ($docRoot !== '' && str_starts_with($appRoot, $docRoot))
    ? substr($appRoot, strlen($docRoot))
    : '/Escuela';
$esPaginaPublica = in_array($paginaActual ?? '', ['inicio', 'sobre-nosotros', 'clases'], true);
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
    <?php if ($esPaginaPublica): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/inicio.css?v=<?= filemtime(dirname(__DIR__) . '/css/inicio.css') ?>">
    <?php endif; ?>
    <?php if (isset($paginaActual) && $paginaActual === 'clases'): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/clases.css?v=<?= filemtime(dirname(__DIR__) . '/css/clases.css') ?>">
    <?php endif; ?>
    <?php if (isset($paginaActual) && $paginaActual === 'sobre-nosotros'): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/sobre-nosotros.css?v=<?= filemtime(dirname(__DIR__) . '/css/sobre-nosotros.css') ?>">
    <?php endif; ?>
    <?php if (isset($paginaActual) && $paginaActual === 'inicio'): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pannellum@2.5.6/build/pannellum.css">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="<?= isset($paginaActual) && $paginaActual === 'inicio' ? 'pagina-inicio' : ($esPaginaPublica ? 'pagina-publica' : '') ?>">
    <?php if (empty($_SESSION['id_usuario'])): ?>
        <header>
            <h1><img src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/imagenes/Logo.png" class="logotipo" alt="Logo de Team Cats"> Team Cats</h1>
            <nav aria-label="Navegación principal">
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/" <?= ($paginaActual ?? '') === 'inicio' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-house" aria-hidden="true"></i> Inicio</a>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/sobre_nosotros.php" <?= ($paginaActual ?? '') === 'sobre-nosotros' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-users" aria-hidden="true"></i> Sobre Nosotros</a>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/clases_publicas.php" <?= ($paginaActual ?? '') === 'clases' ? 'aria-current="page"' : '' ?>><i class="fa-solid fa-clipboard" aria-hidden="true"></i> Clases</a>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/login.php"><i class="fa-solid fa-circle-user" aria-hidden="true"></i> Iniciar sesión</a>
                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/register.php">Crear cuenta</a>
            </nav>
        </header>
    <?php endif; ?>