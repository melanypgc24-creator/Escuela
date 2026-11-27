<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class MigrationRunner
{
    // Espera máxima por el bloqueo de migraciones: si hay otra en curso se falla rápido en vez de esperar mucho.
    public const LOCK_TIMEOUT_SECONDS = 5;

    private string $migrationDirectoryRealpath;

    public function __construct(
        private PDO $db,
        string $migrationDirectory,
        private MigrationRepository $repository
    ) {
        $realpath = realpath($migrationDirectory);
        if (!is_string($realpath) || !is_dir($realpath)) {
            throw new MigrationException(
                sprintf('No existe el directorio de migraciones: %s', $migrationDirectory)
            );
        }

        $this->migrationDirectoryRealpath = rtrim($realpath, '\\/');
    }

    public static function lockNameForDatabase(string $databaseName): string
    {
        return 'migration_' . substr(hash('sha256', strtolower($databaseName)), 0, 40);
    }

    /**
     * @return list<array{id:string,file:string,path:string,description:string,checksum:string,executed:bool,executed_at:string|null,status:string,transactional:bool,statements:int,detail:string|null}>
     */
    public function status(): array
    {
        $executed = $this->repository->allIndexedById();
        $states = $this->repository->allStatesIndexedById();
        $executedByFile = [];
        foreach ($executed as $row) {
            $executedByFile[$row['archivo']] = $row;
        }

        $status = [];
        $seenFiles = [];
        $seenStateIds = [];
        foreach ($this->migrationPaths() as $path) {
            $file = basename($path);
            $sums = self::checksumsFor($path);
            $checksum = $sums['canonical'];

            $executedRow = $executedByFile[$file] ?? null;
            $displayId = $executedRow['id_migracion'] ?? pathinfo($file, PATHINFO_FILENAME);
            $stateRow = $states[$displayId] ?? null;
            $seenFiles[$file] = true;
            if ($stateRow !== null) {
                $seenStateIds[$displayId] = true;
            }

            $fileStatus = 'pending';
            $executedAt = null;
            $detail = $stateRow['detalle'] ?? null;
            if ($executedRow !== null) {
                $executedAt = $executedRow['ejecutada_en'];
                if (!self::checksumMatches($executedRow['checksum'], $sums['accepted'])) {
                    $fileStatus = 'checksum_mismatch';
                } elseif ($stateRow !== null
                    && (!self::checksumMatches($stateRow['checksum'], $sums['accepted']) || $stateRow['estado'] !== 'completed')) {
                    $fileStatus = 'recovery_required';
                } else {
                    $fileStatus = 'executed';
                }
            } elseif ($stateRow !== null) {
                if (!self::checksumMatches($stateRow['checksum'], $sums['accepted']) || $stateRow['estado'] === 'completed') {
                    $fileStatus = 'recovery_required';
                } elseif ($stateRow['estado'] === 'in_progress') {
                    $fileStatus = 'interrupted';
                } else {
                    $fileStatus = 'failed';
                }
            }

            $status[] = [
                'id' => $displayId,
                'file' => $file,
                'path' => $path,
                'description' => $executedRow['descripcion'] ?? '',
                'checksum' => $checksum,
                'executed' => $executedRow !== null,
                'executed_at' => $executedAt,
                'status' => $fileStatus,
                'transactional' => false,
                'statements' => 0,
                'detail' => $detail,
            ];
        }

        foreach ($executed as $row) {
            if (isset($seenFiles[$row['archivo']])) {
                continue;
            }
            $status[] = [
                'id' => $row['id_migracion'],
                'file' => '(archivo de migración ausente: ' . $row['archivo'] . ')',
                'path' => '',
                'description' => $row['descripcion'],
                'checksum' => $row['checksum'],
                'executed' => true,
                'executed_at' => $row['ejecutada_en'],
                'status' => 'missing_file',
                'transactional' => false,
                'statements' => 0,
                'detail' => null,
            ];
            $seenStateIds[$row['id_migracion']] = true;
        }

        foreach ($states as $id => $stateRow) {
            if (isset($seenStateIds[$id])) {
                continue;
            }
            $status[] = [
                'id' => $id,
                'file' => '(journal sin migración validada)',
                'path' => '',
                'description' => '',
                'checksum' => $stateRow['checksum'],
                'executed' => false,
                'executed_at' => null,
                'status' => $stateRow['estado'] === 'in_progress' ? 'interrupted' : 'recovery_required',
                'transactional' => false,
                'statements' => 0,
                'detail' => $stateRow['detalle'],
            ];
        }

        return $status;
    }

    /**
     * @return list<array{id:string,file:string,description:string,checksum:string}>
     */
    public function migrate(?string $onlyId = null): array
    {
        $migrations = $this->discoverAndValidate();
        $dbName = $this->repository->currentDatabaseName();

        return DatabaseLock::run(
            $this->db,
            self::lockNameForDatabase($dbName),
            function () use ($migrations, $dbName, $onlyId): array {
                if ($onlyId !== null) {
                    $selected = array_values(array_filter($migrations, static fn (array $migration): bool => $migration['id'] === $onlyId));
                    if (count($selected) !== 1 || !$selected[0]['standalone']) {
                        throw new MigrationException('La migración indicada no existe o no admite ejecución independiente.');
                    }
                    $migrations = $selected;
                }
                foreach ($migrations as $migration) {
                    $this->assertChecksumUnchanged($migration);
                }
                if (!$this->repository->tableExists()) {
                    throw new MigrationException(
                        'El sistema de migraciones no está inicializado. Ejecuta primero "php migrate.php init".'
                    );
                }
                if (!$this->repository->stateTableExists()) {
                    throw new MigrationException(
                        'Falta la tabla de recuperación de migraciones. Ejecuta el comando explícito "init" antes de migrar.'
                    );
                }

                $status = $this->statusFor($migrations);
                if ($onlyId !== null) {
                    $status = array_values(array_filter($status, static fn (array $row): bool => $row['id'] === $onlyId));
                }
                $knownIds = array_fill_keys(array_column($migrations, 'id'), true);
                foreach ($onlyId === null ? $this->repository->allIndexedById() : [] as $id => $record) {
                    if (!isset($knownIds[$id])) {
                        throw new MigrationException(
                            sprintf(
                                'La migración registrada %s (%s) ya no está en el directorio. Reincorpora/revisa el archivo antes de continuar.',
                                $id,
                                $record['archivo']
                            )
                        );
                    }
                }
                foreach ($onlyId === null ? $this->repository->allStatesIndexedById() : [] as $id => $state) {
                    if (!isset($knownIds[$id])
                        && in_array($state['estado'], ['in_progress', 'failed'], true)) {
                        throw new MigrationException(
                            sprintf(
                                'El journal contiene la migración %s en estado %s, pero su archivo ya no existe. Se requiere recuperación manual.',
                                $id,
                                $state['estado']
                            )
                        );
                    }
                }
                foreach ($status as $row) {
                    if ($row['status'] === 'checksum_mismatch') {
                        throw new MigrationException(
                            sprintf(
                                'La migración %s (%s) cambió después de ejecutarse. Revisa el archivo antes de continuar.',
                                $row['id'],
                                $row['file']
                            )
                        );
                    }
                    if ($row['status'] === 'identity_mismatch') {
                        throw new MigrationException(
                            sprintf('El ID %s está asociado a un archivo distinto del migrado; no se ejecutó nada.', $row['id'])
                        );
                    }
                    if (in_array($row['status'], ['interrupted', 'failed', 'recovery_required'], true)) {
                        throw new MigrationException(
                            sprintf(
                                'La migración %s (%s) requiere recuperación manual; estado=%s. No se reintentó para evitar duplicar cambios. Inspecciona el esquema/datos. Si el archivo la declara retry_safe, puedes liberarla con: php migrate.php recover --migration=%s --target-db=<base> --env-id=<id> --confirm-write=YES',
                                $row['id'],
                                $row['file'],
                                $row['status'],
                                $row['id']
                            )
                        );
                    }
                }

                $executedNow = [];
                $statusById = [];
                foreach ($status as $row) {
                    $statusById[$row['id']] = $row['status'];
                }
                foreach ($migrations as $migration) {
                    if (($statusById[$migration['id']] ?? 'pending') !== 'pending') {
                        continue;
                    }

                    $this->assertChecksumUnchanged($migration);
                    $this->assertPreflight($migration);

                    if ($migration['transactional']) {
                        $this->executeTransactionalMigration($migration);
                    } else {
                        $this->executeRecoverableMigration($migration, $dbName);
                    }

                    $executedNow[] = [
                        'id' => $migration['id'],
                        'file' => $migration['file'],
                        'description' => $migration['description'],
                        'checksum' => $migration['checksum'],
                    ];
                }

                return $executedNow;
            },
            self::LOCK_TIMEOUT_SECONDS
        );
    }

    /**
     * Libera el journal de una migración fallida o interrumpida para poder repetirla desde el inicio.
     * Solo se permite si el archivo declara retry_safe (todas sus sentencias son idempotentes) y la migración
     * no está registrada como ejecutada. No ejecuta SQL de la migración: después hay que correr "up".
     *
     * @return array{id:string,estado_anterior:string,detalle:string|null}
     */
    public function recover(string $migrationId): array
    {
        $migrations = $this->discoverAndValidate();
        $dbName = $this->repository->currentDatabaseName();

        return DatabaseLock::run(
            $this->db,
            self::lockNameForDatabase($dbName),
            function () use ($migrations, $migrationId): array {
                if (!$this->repository->tableExists() || !$this->repository->stateTableExists()) {
                    throw new MigrationException('El sistema de migraciones no está inicializado; no hay nada que recuperar.');
                }

                $target = null;
                foreach ($migrations as $migration) {
                    if ($migration['id'] === $migrationId) {
                        $target = $migration;
                        break;
                    }
                }
                if ($target === null) {
                    throw new MigrationException(sprintf('La migración %s no existe en el directorio de migraciones.', $migrationId));
                }
                if (isset($this->repository->allIndexedById()[$migrationId])) {
                    throw new MigrationException(sprintf('La migración %s ya está registrada como ejecutada; no hay nada que recuperar.', $migrationId));
                }
                $state = $this->repository->allStatesIndexedById()[$migrationId] ?? null;
                if ($state === null) {
                    throw new MigrationException(sprintf('La migración %s no tiene un estado de recuperación pendiente.', $migrationId));
                }
                if (!$target['retry_safe']) {
                    throw new MigrationException(
                        sprintf(
                            'La migración %s no está marcada como retry_safe: no se puede repetir automáticamente. Inspecciona el esquema y los datos y repara el journal manualmente.',
                            $migrationId
                        )
                    );
                }

                $this->repository->deleteState($migrationId);

                return [
                    'id' => $migrationId,
                    'estado_anterior' => $state['estado'],
                    'detalle' => $state['detalle'],
                ];
            },
            self::LOCK_TIMEOUT_SECONDS
        );
    }

    /**
     * @return list<array{id_migracion:string,archivo:string,descripcion:string,checksum:string,ejecutada_en:string}>
     */
    public function history(): array
    {
        if (!$this->repository->tableExists()) {
            throw new MigrationException('El sistema de migraciones aún no está inicializado.');
        }

        return $this->repository->history();
    }

    /**
     * @return list<array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>,preflight:list<array{sql:string,expected:int,message:string}>}>
     */
    private function discoverAndValidate(): array
    {
        $migrations = [];
        $filesById = [];
        foreach ($this->migrationPaths() as $path) {
            $migration = $this->loadMigrationFromFile($path);
            if ($migration['transactional']) {
                $this->validateTransactionalStatements($migration);
            }
            $filesById[$migration['id']][] = $migration['file'];
            $migrations[] = $migration;
        }

        $duplicates = [];
        foreach ($filesById as $id => $fileNames) {
            if (count($fileNames) > 1) {
                $duplicates[] = sprintf('%s: %s', $id, implode(', ', $fileNames));
            }
        }
        if ($duplicates !== []) {
            throw new MigrationException(
                "Hay identificadores de migración duplicados; no se ejecutó SQL:\n - "
                . implode("\n - ", $duplicates)
            );
        }

        return $migrations;
    }

    /**
     * Enumerates paths without executing PHP migration files; status/history remain read-only.
     *
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        $pattern = $this->migrationDirectoryRealpath . DIRECTORY_SEPARATOR . '*.php';
        $files = glob($pattern);
        if ($files === false) {
            throw new MigrationException('No se pudo enumerar el directorio de migraciones.');
        }
        sort($files, SORT_STRING);

        $paths = [];
        foreach ($files as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $realPath = realpath($path);
            if (!is_string($realPath) || !is_file($realPath)) {
                throw new MigrationException(sprintf('No existe el archivo de migración: %s', $path));
            }
            $normalizedPath = str_replace('\\', '/', $realPath);
            $normalizedDirectory = str_replace('\\', '/', $this->migrationDirectoryRealpath);
            if (strpos($normalizedPath, $normalizedDirectory . '/') !== 0) {
                throw new MigrationException(
                    sprintf('El archivo de migración está fuera del directorio permitido: %s', $path)
                );
            }
            $paths[] = $realPath;
        }

        return $paths;
    }

    /**
     * @param list<array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>}> $migrations
     * @return list<array{id:string,file:string,path:string,description:string,checksum:string,executed:bool,executed_at:string|null,status:string,transactional:bool,statements:int,detail:string|null}>
     */
    private function statusFor(array $migrations): array
    {
        $executed = $this->repository->allIndexedById();
        $states = $this->repository->allStatesIndexedById();
        $status = [];
        $seenIds = [];

        foreach ($migrations as $migration) {
            $seenIds[$migration['id']] = true;
            $executedRow = $executed[$migration['id']] ?? null;
            $stateRow = $states[$migration['id']] ?? null;
            $fileStatus = 'pending';
            $executedAt = null;
            $detail = $stateRow['detalle'] ?? null;

            if ($executedRow !== null) {
                $executedAt = $executedRow['ejecutada_en'];
                if ($executedRow['archivo'] !== $migration['file']) {
                    $fileStatus = 'identity_mismatch';
                } elseif (!self::checksumMatches($executedRow['checksum'], $migration['accepted_checksums'])) {
                    $fileStatus = 'checksum_mismatch';
                } elseif ($stateRow !== null
                    && (!self::checksumMatches($stateRow['checksum'], $migration['accepted_checksums'])
                        || $stateRow['estado'] !== 'completed')) {
                    $fileStatus = 'recovery_required';
                } else {
                    $fileStatus = 'executed';
                }
            } elseif ($stateRow !== null) {
                if (!self::checksumMatches($stateRow['checksum'], $migration['accepted_checksums'])) {
                    $fileStatus = 'recovery_required';
                } elseif ($stateRow['estado'] === 'in_progress') {
                    $fileStatus = 'interrupted';
                } elseif ($stateRow['estado'] === 'failed') {
                    $fileStatus = 'failed';
                } elseif ($stateRow['estado'] === 'completed') {
                    $fileStatus = 'recovery_required';
                } else {
                    $fileStatus = 'recovery_required';
                }
            }

            $status[] = [
                'id' => $migration['id'],
                'file' => $migration['file'],
                'path' => $migration['path'],
                'description' => $migration['description'],
                'checksum' => $migration['checksum'],
                'executed' => $executedRow !== null,
                'executed_at' => $executedAt,
                'status' => $fileStatus,
                'transactional' => $migration['transactional'],
                'statements' => count($migration['statements']),
                'detail' => $detail,
            ];
        }

        foreach ($states as $id => $stateRow) {
            if (isset($seenIds[$id])) {
                continue;
            }
            if (!in_array($stateRow['estado'], ['in_progress', 'failed', 'completed'], true)) {
                continue;
            }

            $status[] = [
                'id' => $id,
                'file' => '(archivo de migración ausente)',
                'path' => '',
                'description' => 'Journal sin archivo correspondiente',
                'checksum' => $stateRow['checksum'],
                'executed' => false,
                'executed_at' => null,
                'status' => $stateRow['estado'] === 'in_progress' ? 'interrupted' : 'recovery_required',
                'transactional' => false,
                'statements' => 0,
                'detail' => $stateRow['detalle'],
            ];
        }

        return $status;
    }

    /**
     * @return array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>,preflight:list<array{sql:string,expected:int,message:string}>}
     */
    private function loadMigrationFromFile(string $path): array
    {
        $realPath = realpath($path);
        if (!is_string($realPath) || !is_file($realPath)) {
            throw new MigrationException(sprintf('No existe el archivo de migración: %s', $path));
        }

        $normalizedPath = str_replace('\\', '/', $realPath);
        $normalizedDirectory = str_replace('\\', '/', $this->migrationDirectoryRealpath);
        if (strpos($normalizedPath, $normalizedDirectory . '/') !== 0) {
            throw new MigrationException(
                sprintf('El archivo de migración está fuera del directorio permitido: %s', $path)
            );
        }

        $definition = require $realPath;
        if (!is_array($definition)) {
            throw new MigrationException(sprintf('El archivo %s debe devolver un arreglo.', $realPath));
        }

        $id = $definition['id'] ?? null;
        $description = $definition['description'] ?? null;
        $transactional = $definition['transactional'] ?? false;
        $statements = $definition['statements'] ?? null;
        $preflight = $definition['preflight'] ?? [];
        $retrySafe = $definition['retry_safe'] ?? false;
        $standalone = $definition['standalone'] ?? false;

        if (!is_string($id) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,190}$/D', trim($id)) !== 1) {
            throw new MigrationException(sprintf('La migración %s no tiene un id válido (máximo 191 caracteres ASCII).', $realPath));
        }
        if (!is_bool($retrySafe)) {
            throw new MigrationException(sprintf('La migración %s debe declarar retry_safe como booleano.', $realPath));
        }
        if (!is_bool($standalone)) {
            throw new MigrationException(sprintf('La migración %s debe declarar standalone como booleano.', $realPath));
        }
        if (!is_string($description) || trim($description) === '') {
            throw new MigrationException(sprintf('La migración %s no tiene una descripción válida.', $realPath));
        }
        if (!is_bool($transactional)) {
            throw new MigrationException(sprintf('La migración %s debe declarar transactional como booleano.', $realPath));
        }
        if (!is_array($statements) || $statements === []) {
            throw new MigrationException(sprintf('La migración %s debe incluir statements no vacío como arreglo.', $realPath));
        }
        if (!is_array($preflight)) {
            throw new MigrationException(sprintf('La migración %s debe declarar preflight como arreglo.', $realPath));
        }

        $normalized = [];
        foreach ($statements as $sql) {
            if (!is_string($sql) || trim($sql) === '') {
                throw new MigrationException(sprintf('La migración %s contiene una sentencia inválida.', $realPath));
            }
            $normalized[] = trim($sql);
        }

        $normalizedPreflight = [];
        foreach ($preflight as $check) {
            if (!is_array($check) || !is_string($check['sql'] ?? null)
                || preg_match('/^\s*SELECT\b/i', $check['sql']) !== 1
                || strpos($check['sql'], ';') !== false
                || !is_int($check['expected'] ?? null)
                || !is_string($check['message'] ?? null)
                || trim($check['message']) === '') {
                throw new MigrationException(sprintf('La migración %s contiene una comprobación preflight inválida.', $realPath));
            }
            $normalizedPreflight[] = [
                'sql' => trim($check['sql']),
                'expected' => $check['expected'],
                'message' => trim($check['message']),
            ];
        }

        $sums = self::checksumsFor($realPath);

        return [
            'id' => trim($id),
            'file' => basename($realPath),
            'path' => $realPath,
            'description' => trim($description),
            'checksum' => $sums['canonical'],
            'accepted_checksums' => $sums['accepted'],
            'transactional' => $transactional,
            'retry_safe' => $retrySafe,
            'standalone' => $standalone,
            'statements' => $normalized,
            'preflight' => $normalizedPreflight,
        ];
    }

    /**
     * El checksum canónico no depende de los finales de línea (Git puede convertirlos según el sistema).
     * Los registros anteriores, calculados sobre los bytes originales, siguen siendo válidos.
     *
     * @return array{canonical:string,accepted:list<string>}
     */
    private static function checksumsFor(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new MigrationException(sprintf('No se pudo leer %s para calcular su checksum.', $path));
        }
        $lf = str_replace("\r\n", "\n", $content);
        $canonical = hash('sha256', $lf);

        // Se leen con una expresión regular (sin ejecutar el archivo) para que status/history sigan siendo de solo lectura.
        $superseded = [];
        if (preg_match("/'supersedes_checksums'\s*=>\s*\[(.*?)\]/s", $lf, $block) === 1) {
            preg_match_all('/[a-f0-9]{64}/', $block[1], $hashes);
            $superseded = $hashes[0];
        }

        return [
            'canonical' => $canonical,
            'accepted' => array_values(array_unique(array_merge([
                $canonical,
                hash('sha256', $content),
                hash('sha256', str_replace("\n", "\r\n", $lf)),
            ], $superseded))),
        ];
    }

    /**
     * @param list<string> $accepted
     */
    private static function checksumMatches(string $recorded, array $accepted): bool
    {
        foreach ($accepted as $candidate) {
            if (hash_equals($recorded, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>,preflight:list<array{sql:string,expected:int,message:string}>} $migration
     */
    private function assertPreflight(array $migration): void
    {
        foreach ($migration['preflight'] as $check) {
            $actual = $this->db->query($check['sql'])->fetchColumn();
            if (!is_numeric($actual) || (int) $actual !== $check['expected']) {
                throw new MigrationException(
                    sprintf(
                        'Preflight rechazó la migración %s: %s (resultado=%s, esperado=%d). No se ejecutó SQL de la migración.',
                        $migration['id'],
                        $check['message'],
                        var_export($actual, true),
                        $check['expected']
                    )
                );
            }
        }
    }

    /**
     * Ejecuta una sentencia y cierra cualquier conjunto de resultados que devuelva (por ejemplo un `SELECT 1`
     * dentro de un EXECUTE condicional); si no, PDO bloquea la siguiente sentencia con el error 2014.
     */
    private function executeStatement(string $statement): void
    {
        $result = $this->db->query($statement);
        if ($result !== false) {
            if ($result->columnCount() > 0) {
                $result->fetchAll();
            }
            $result->closeCursor();
        }
    }

    /**
     * @param array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>} $migration
     */
    private function executeTransactionalMigration(array $migration): void
    {
        $this->validateTransactionalStatements($migration);
        $this->db->beginTransaction();
        try {
            foreach ($migration['statements'] as $statement) {
                $this->executeStatement($statement);
            }
            $this->repository->save(
                $migration['id'],
                $migration['file'],
                $migration['description'],
                $migration['checksum']
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new MigrationException(
                sprintf(
                    'Falló la migración transaccional %s; los cambios y su registro se revirtieron si todas las tablas involucradas son transaccionales (InnoDB): %s',
                    $migration['id'],
                    $exception->getMessage()
                ),
                0,
                $exception
            );
        }
    }

    /**
     * DDL de MariaDB puede confirmar implícitamente y no se puede deshacer con ROLLBACK.
     * Los estados in_progress/failed son deliberadamente bloqueantes: requieren inspección
     * manual del esquema y datos, y no deben reintentarse automáticamente.
     *
     * @param array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>} $migration
     */
    private function executeRecoverableMigration(array $migration, string $dbName): void
    {
        $this->repository->beginNonTransactional($migration['id'], $migration['checksum']);

        try {
            foreach ($migration['statements'] as $statement) {
                $this->executeStatement($statement);
            }

            $this->db->beginTransaction();
            try {
                $this->repository->save(
                    $migration['id'],
                    $migration['file'],
                    $migration['description'],
                    $migration['checksum']
                );
                $this->repository->markNonTransactionalCompleted($migration['id'], $migration['checksum']);
                $this->db->commit();
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                try {
                    $this->db->rollBack();
                } catch (Throwable $rollbackException) {
                    $exception = new MigrationException(
                        sprintf(
                            'Además de fallar la migración, no se pudo revertir la transacción SQL abierta: %s',
                            $rollbackException->getMessage()
                        ),
                        0,
                        $exception
                    );
                }
            }
            $recoveryMessage = sprintf(
                'Migración no transaccional fallida/interrumpida [%s] en base [%s]. MariaDB puede haber confirmado DDL parcial. No se reintentó ni registró como exitosa; inspecciona el esquema y los datos antes de reconciliar el journal: %s',
                $migration['id'],
                $dbName,
                $exception->getMessage()
            );
            try {
                if ($this->repository->stateTableExists()) {
                    $this->repository->markNonTransactionalFailed($migration['id'], $recoveryMessage);
                }
            } catch (Throwable $journalException) {
                $recoveryMessage .= ' Además, no se pudo actualizar el journal: ' . $journalException->getMessage();
            }
            throw new MigrationException($recoveryMessage, 0, $exception);
        }
    }

    /**
     * @param array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>} $migration
     */
    private function validateTransactionalStatements(array $migration): void
    {
        foreach ($migration['statements'] as $statement) {
            if (strpos($statement, ';') !== false
                || preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', ltrim($statement)) !== 1) {
                throw new MigrationException(
                    sprintf(
                        'La migración %s se declaró transaccional, pero contiene SQL fuera de la lista segura DML (INSERT/UPDATE/DELETE/REPLACE) o un separador de sentencias. Declárala no transaccional y sujeta a recuperación manual, o divídela correctamente.',
                        $migration['id']
                    )
                );
            }
        }
    }

    /**
     * @param array{id:string,file:string,path:string,description:string,checksum:string,transactional:bool,statements:list<string>} $migration
     */
    private function assertChecksumUnchanged(array $migration): void
    {
        $currentChecksum = self::checksumsFor($migration['path'])['canonical'];
        if (!hash_equals($migration['checksum'], $currentChecksum)) {
            throw new MigrationException(
                sprintf('El archivo de migración %s cambió después de ser validado; no se ejecutó.', $migration['file'])
            );
        }
    }
}
