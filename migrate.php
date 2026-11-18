<?php
declare(strict_types=1);

use App\Config\Database;
use App\Core\MigrationException;
use App\Core\MigrationRepository;
use App\Core\MigrationRunner;

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
 * @return array<string, string>
 */
function parseCliOptions(array $argv): array
{
    $options = [];
    for ($index = 2; $index < count($argv); $index++) {
        $item = $argv[$index];
        if (!is_string($item) || strpos($item, '--') !== 0) {
            throw new MigrationException(sprintf('Parámetro inválido: %s', (string) $item));
        }
        $parts = explode('=', substr($item, 2), 2);
        if (count($parts) !== 2 || trim($parts[0]) === '') {
            throw new MigrationException(sprintf('Parámetro ambiguo o incompleto: %s', $item));
        }
        if (array_key_exists($parts[0], $options)) {
            throw new MigrationException(sprintf('Parámetro duplicado: --%s.', $parts[0]));
        }
        if (!in_array($parts[0], ['target-db', 'env-id', 'confirm-write'], true)) {
            throw new MigrationException(sprintf('Parámetro desconocido: --%s.', $parts[0]));
        }
        $options[$parts[0]] = $parts[1];
    }

    return $options;
}

function parseCsvEnv(string $value): array
{
    $items = array_map('trim', explode(',', $value));
    if ($items === [] || in_array('', $items, true)) {
        return [];
    }

    return $items;
}

function assertWriteAuthorization(MigrationRepository $repository, array $options): void
{
    $required = ['target-db', 'env-id', 'confirm-write'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $options) || trim($options[$field]) === '') {
            throw new MigrationException(sprintf('Falta parámetro obligatorio --%s para operaciones de escritura.', $field));
        }
    }

    if ($options['confirm-write'] !== 'YES') {
        throw new MigrationException('Debes confirmar explícitamente con --confirm-write=YES.');
    }

    $allowedRaw = getenv('MIGRATIONS_ALLOWED_DATABASES');
    if (!is_string($allowedRaw) || trim($allowedRaw) === '') {
        throw new MigrationException(
            'Debes definir MIGRATIONS_ALLOWED_DATABASES para autorizar bases destino de migración.'
        );
    }
    $allowedDbs = parseCsvEnv($allowedRaw);
    if ($allowedDbs === []) {
        throw new MigrationException('MIGRATIONS_ALLOWED_DATABASES está vacío después de parsear.');
    }
    foreach ($allowedDbs as $databaseName) {
        if (preg_match('/^[A-Za-z0-9_$-]+$/D', $databaseName) !== 1) {
            throw new MigrationException('MIGRATIONS_ALLOWED_DATABASES contiene un nombre de base inválido.');
        }
    }

    $blockedRaw = getenv('MIGRATIONS_BLOCKED_DATABASES');
    if ($blockedRaw === false) {
        $blockedDbs = [];
    } elseif (!is_string($blockedRaw) || trim($blockedRaw) === '') {
        throw new MigrationException('MIGRATIONS_BLOCKED_DATABASES está definido pero vacío.');
    } else {
        $blockedDbs = parseCsvEnv($blockedRaw);
        if ($blockedDbs === []) {
            throw new MigrationException('MIGRATIONS_BLOCKED_DATABASES está vacío después de parsear.');
        }
    }
    foreach ($blockedDbs as $databaseName) {
        if (preg_match('/^[A-Za-z0-9_$-]+$/D', $databaseName) !== 1) {
            throw new MigrationException('MIGRATIONS_BLOCKED_DATABASES contiene un nombre de base inválido.');
        }
    }
    $blockedDbs[] = 'escuela';
    $allowedDbs = array_map('strtolower', $allowedDbs);
    $blockedDbs = array_map('strtolower', $blockedDbs);

    $expectedEnvId = getenv('MIGRATIONS_ENV_ID');
    if (!is_string($expectedEnvId) || trim($expectedEnvId) === '') {
        throw new MigrationException('Debes definir MIGRATIONS_ENV_ID para autorizar el entorno.');
    }

    $targetDb = trim($options['target-db']);
    $actualDb = $repository->currentDatabaseName();
    if (preg_match('/^[A-Za-z0-9_$-]+$/D', $actualDb) !== 1) {
        throw new MigrationException('El nombre de la base conectada no tiene un formato permitido.');
    }
    if ($targetDb !== $actualDb) {
        throw new MigrationException(
            sprintf('La base objetivo declarada (%s) no coincide con la conexión activa (%s).', $targetDb, $actualDb)
        );
    }

    if (!in_array(strtolower($actualDb), $allowedDbs, true)) {
        throw new MigrationException(
            sprintf('La base %s no está autorizada por MIGRATIONS_ALLOWED_DATABASES.', $actualDb)
        );
    }
    if (in_array(strtolower($actualDb), $blockedDbs, true)) {
        throw new MigrationException(
            sprintf('La base %s está bloqueada por MIGRATIONS_BLOCKED_DATABASES.', $actualDb)
        );
    }

    if (!hash_equals(trim($expectedEnvId), trim($options['env-id']))) {
        throw new MigrationException('El parámetro --env-id no coincide con MIGRATIONS_ENV_ID.');
    }
}

$command = $argv[1] ?? 'status';
$allowed = ['status', 'init', 'up', 'history'];
if (!in_array($command, $allowed, true)) {
    fwrite(STDERR, "Uso: php migrate.php [status|init|up|history] [--target-db=...] [--env-id=...] [--confirm-write=YES]" . PHP_EOL);
    exit(1);
}

try {
    $options = parseCliOptions($argv);
    $migrationDir = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    if (!is_dir($migrationDir)) {
        throw new MigrationException("No existe el directorio de migraciones: {$migrationDir}");
    }

    $db = Database::connect();
    $repository = new MigrationRepository($db);
    $runner = new MigrationRunner($db, $migrationDir, $repository);

    if ($command === 'status') {
        if (!$repository->tableExists()) {
            fwrite(STDOUT, "Sistema de migraciones no inicializado: la tabla 'migraciones' no existe." . PHP_EOL);
        }
        if ($repository->tableExists() && !$repository->stateTableExists()) {
            fwrite(STDOUT, "Advertencia: falta el journal de recuperación; ejecuta init explícitamente antes de up." . PHP_EOL);
        }

        $rows = $runner->status();
        if ($rows === []) {
            fwrite(STDOUT, "No hay archivos de migración en {$migrationDir}" . PHP_EOL);
            exit(0);
        }
        foreach ($rows as $row) {
            $line = sprintf(
                "[%s] %s (%s)%s",
                $row['status'],
                $row['id'],
                $row['file'],
                $row['executed_at'] === null ? '' : ' ejecutada_en=' . $row['executed_at']
            );
            if ($row['detail'] !== null) {
                $line .= ' detalle=' . $row['detail'];
            }
            fwrite(STDOUT, $line . PHP_EOL);
        }
        exit(0);
    }

    if ($command === 'history') {
        if (!$repository->tableExists()) {
            fwrite(STDOUT, "Sistema de migraciones no inicializado: no hay historial disponible." . PHP_EOL);
            exit(0);
        }

        $rows = $runner->history();
        if ($rows === []) {
            fwrite(STDOUT, "No hay migraciones registradas." . PHP_EOL);
            exit(0);
        }
        foreach ($rows as $row) {
            fwrite(
                STDOUT,
                sprintf(
                    "%s | %s | %s | %s",
                    $row['ejecutada_en'],
                    $row['id_migracion'],
                    $row['archivo'],
                    $row['descripcion']
                ) . PHP_EOL
            );
        }
        exit(0);
    }

    assertWriteAuthorization($repository, $options);

    if ($command === 'init') {
        $databaseName = $repository->currentDatabaseName();
        \App\Core\DatabaseLock::run(
            $db,
            MigrationRunner::lockNameForDatabase($databaseName),
            static function () use ($repository): void {
                $repository->initializeTable();
            },
            15
        );
        fwrite(STDOUT, "Tablas de control y recuperación de migraciones inicializadas/verificadas." . PHP_EOL);
        exit(0);
    }

    $executed = $runner->migrate();
    if ($executed === []) {
        fwrite(STDOUT, "No hay migraciones pendientes." . PHP_EOL);
        exit(0);
    }

    foreach ($executed as $row) {
        fwrite(STDOUT, sprintf("OK %s (%s)", $row['id'], $row['file']) . PHP_EOL);
    }
    exit(0);
} catch (MigrationException $exception) {
    fwrite(STDERR, 'Error de migración: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error inesperado: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

