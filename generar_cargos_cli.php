<?php
declare(strict_types=1);

use App\Config\Database;
use App\Models\MonthlyChargeModel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Este script solo puede ejecutarse por CLI.' . PHP_EOL);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $parts = explode('\\', $relativeClass);
    $directory = strtolower(array_shift($parts));
    $file = __DIR__ . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Uso:
 *   php generar_cargos_cli.php --actor=1 [--periodo=2026-11]
 *
 * --actor=<id_usuario>  Obligatorio. ID del usuario (debe existir en
 *                        `usuarios`) que queda registrado en auditoria_eventos
 *                        e id_usuario_generador como responsable de la
 *                        generación. Usa el ID de una cuenta de administrador
 *                        dedicada a tareas automáticas, no tu cuenta personal.
 * --periodo=AAAA-MM     Opcional. Por defecto, el mes en curso.
 * --vencimiento=AAAA-MM-DD  Opcional. Fecha de vencimiento de los cargos. Si se omite,
 *                        se usa el día predeterminado que el administrador guardó en
 *                        la pantalla "Cargos mensuales" (configuracion_mensualidades).
 *
 * Pensado para correr una vez al mes, por ejemplo el día 1:
 *   Linux (cron):   0 6 1 * * php /ruta/a/Escuela/generar_cargos_cli.php --actor=1
 *   Windows/XAMPP:  Programador de tareas, mensual, con la acción
 *                   C:\xampp\php\php.exe C:\xampp\htdocs\Escuela\generar_cargos_cli.php --actor=1
 * Repetirlo es seguro: no duplica cargos.
 */

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        fwrite(STDERR, "Parámetro inválido: {$argument}" . PHP_EOL);
        exit(1);
    }
    [$name, $value] = explode('=', substr($argument, 2), 2);
    $options[$name] = $value;
}

if (!isset($options['actor']) || filter_var($options['actor'], FILTER_VALIDATE_INT) === false) {
    fwrite(STDERR, 'Falta --actor=<id_usuario> (entero).' . PHP_EOL);
    exit(1);
}

$actorUserId = (int) $options['actor'];
$periodo = $options['periodo'] ?? date('Y-m');
$vencimiento = $options['vencimiento'] ?? null;

try {
    $pdo = Database::connect();

    $actorCheck = $pdo->prepare('SELECT 1 FROM usuarios WHERE id_usuario = :id AND activo = 1 LIMIT 1');
    $actorCheck->execute(['id' => $actorUserId]);
    if ($actorCheck->fetchColumn() === false) {
        fwrite(STDERR, "El usuario --actor={$actorUserId} no existe o está desactivado." . PHP_EOL);
        exit(1);
    }

    $model = new MonthlyChargeModel($pdo);
    $resultado = $model->generateForPeriod($periodo, $actorUserId, $vencimiento);

    echo sprintf(
        "Periodo %s: %d creado(s), %d ya existía(n), %d omitido(s).%s",
        $resultado['periodo'],
        $resultado['creados'],
        $resultado['ya_existian'],
        count($resultado['omitidos']),
        PHP_EOL
    );
    foreach ($resultado['omitidos'] as $omitido) {
        echo sprintf("  - #%d %s: %s%s", $omitido['id_alumno'], $omitido['nombre'], $omitido['motivo'], PHP_EOL);
    }
    exit(0);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Error al generar cargos: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
