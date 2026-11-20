<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class MigrationRepository
{
    private string $table;
    private string $stateTable;

    public function __construct(private PDO $db, string $table = 'migraciones')
    {
        TableSchemaInspector::assertIdentifier($table, 'table');
        $this->table = $table;
        $this->stateTable = $table . '_estado';
        TableSchemaInspector::assertIdentifier($this->stateTable, 'stateTable');
    }

    public function initializeTable(): void
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id_migracion` VARCHAR(191) NOT NULL,
                `archivo` VARCHAR(255) NOT NULL,
                `descripcion` VARCHAR(255) NOT NULL,
                `checksum` CHAR(64) NOT NULL,
                `ejecutada_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_migracion`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->table
        );

        $this->db->exec($sql);

        $stateSql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id_migracion` VARCHAR(191) NOT NULL,
                `checksum` CHAR(64) NOT NULL,
                `estado` VARCHAR(16) NOT NULL,
                `detalle` TEXT NULL,
                `iniciado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_migracion`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->stateTable
        );
        $this->db->exec($stateSql);
    }

    public function tableExists(): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $this->table]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function currentDatabaseName(): string
    {
        $name = $this->db->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            throw new MigrationException('No se pudo determinar la base de datos activa para migraciones.');
        }

        return $name;
    }

    public function stateTableExists(): bool
    {
        return $this->namedTableExists($this->stateTable);
    }

    /**
     * @return array<string, array{id_migracion:string,archivo:string,descripcion:string,checksum:string,ejecutada_en:string}>
     */
    public function allIndexedById(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->readMigrations();
    }

    /**
     * @return array<string, array{id_migracion:string,archivo:string,descripcion:string,checksum:string,ejecutada_en:string}>
     */
    private function readMigrations(): array
    {
        $sql = sprintf(
            'SELECT id_migracion, archivo, descripcion, checksum, ejecutada_en
             FROM `%s`
             ORDER BY ejecutada_en, id_migracion',
            $this->table
        );
        $rows = $this->db->query($sql)->fetchAll();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['id_migracion']] = [
                'id_migracion' => (string) $row['id_migracion'],
                'archivo' => (string) $row['archivo'],
                'descripcion' => (string) $row['descripcion'],
                'checksum' => (string) $row['checksum'],
                'ejecutada_en' => (string) $row['ejecutada_en'],
            ];
        }

        return $indexed;
    }

    /**
     * @return array<string, array{id_migracion:string,checksum:string,estado:string,detalle:string|null}>
     */
    public function allStatesIndexedById(): array
    {
        if (!$this->stateTableExists()) {
            return [];
        }

        $sql = sprintf(
            'SELECT id_migracion, checksum, estado, detalle FROM `%s`',
            $this->stateTable
        );
        $rows = $this->db->query($sql)->fetchAll();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['id_migracion']] = [
                'id_migracion' => (string) $row['id_migracion'],
                'checksum' => (string) $row['checksum'],
                'estado' => (string) $row['estado'],
                'detalle' => isset($row['detalle']) ? (string) $row['detalle'] : null,
            ];
        }

        return $indexed;
    }

    public function beginNonTransactional(string $id, string $checksum): void
    {
        if ($this->db->inTransaction()) {
            throw new MigrationException('El journal de recuperación debe confirmarse antes de ejecutar SQL no transaccional.');
        }
        if (!$this->stateTableExists()) {
            throw new MigrationException(
                sprintf('Falta la tabla de recuperación `%s`. Ejecuta el comando explícito "init" antes de migrar.', $this->stateTable)
            );
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                sprintf(
                    'INSERT INTO `%s` (id_migracion, checksum, estado, detalle)
                     VALUES (:id_migracion, :checksum, "in_progress", NULL)',
                    $this->stateTable
                )
            );
            $statement->execute(['id_migracion' => $id, 'checksum' => $checksum]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteState(string $id): void
    {
        if ($this->db->inTransaction()) {
            throw new MigrationException('El estado de recuperación debe liberarse fuera de una transacción abierta.');
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                sprintf('DELETE FROM `%s` WHERE id_migracion = :id_migracion', $this->stateTable)
            );
            $statement->execute(['id_migracion' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new MigrationException(sprintf('No se pudo liberar el estado de recuperación de %s.', $id));
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function markNonTransactionalFailed(string $id, string $detail): void
    {
        if ($this->db->inTransaction()) {
            throw new MigrationException('El estado fallido del journal debe persistirse fuera de una transacción abortada.');
        }
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                sprintf(
                    'UPDATE `%s`
                     SET estado = "failed", detalle = :detalle
                     WHERE id_migracion = :id_migracion AND estado = "in_progress"',
                    $this->stateTable
                )
            );
            $statement->execute([
                'id_migracion' => $id,
                'detalle' => substr($detail, 0, 60000),
            ]);
            if ($statement->rowCount() !== 1) {
                throw new MigrationException(
                    sprintf('No se pudo registrar el estado fallido de la migración %s.', $id)
                );
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function markNonTransactionalCompleted(string $id, string $checksum): void
    {
        if (!$this->db->inTransaction()) {
            throw new MigrationException('El estado completado del journal debe compartir la transacción del historial.');
        }
        $statement = $this->db->prepare(
            sprintf(
                'UPDATE `%s`
                 SET estado = "completed", detalle = NULL
                 WHERE id_migracion = :id_migracion
                   AND checksum = :checksum
                   AND estado = "in_progress"',
                $this->stateTable
            )
        );
        $statement->execute(['id_migracion' => $id, 'checksum' => $checksum]);
        if ($statement->rowCount() !== 1) {
            throw new MigrationException(
                sprintf('No se pudo completar el registro de recuperación de la migración %s.', $id)
            );
        }
    }

    public function save(string $id, string $fileName, string $description, string $checksum): void
    {
        if (!$this->db->inTransaction()) {
            throw new MigrationException('El registro de una migración exitosa debe compartir su transacción.');
        }
        if (!$this->tableExists()) {
            throw new MigrationException(
                sprintf(
                    'La tabla de control `%s` no existe. Ejecuta primero el comando de inicialización explícito.',
                    $this->table
                )
            );
        }

        $sql = sprintf(
            'INSERT INTO `%s` (id_migracion, archivo, descripcion, checksum)
             VALUES (:id_migracion, :archivo, :descripcion, :checksum)',
            $this->table
        );
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'id_migracion' => $id,
            'archivo' => $fileName,
            'descripcion' => $description,
            'checksum' => $checksum,
        ]);
    }

    /**
     * @return list<array{id_migracion:string,archivo:string,descripcion:string,checksum:string,ejecutada_en:string}>
     */
    public function history(): array
    {
        return array_values($this->allIndexedById());
    }

    private function namedTableExists(string $tableName): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $tableName]);

        return (int) $statement->fetchColumn() > 0;
    }
}

