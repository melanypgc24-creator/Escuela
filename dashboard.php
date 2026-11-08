<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (empty($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit;
}

$destinations = [
    'administrador' => 'dashboard_admin.php',
    'maestro' => 'panel_maestro.php',
    'alumno' => 'panel_alumno.php',
];

$destination = $destinations[$_SESSION['rol'] ?? ''] ?? 'login.php';
header('Location: ' . $destination);
exit;
