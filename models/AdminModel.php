<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DatabaseLock;
use App\Core\LegacyIdAllocator;
use App\Core\TableSchemaInspector;
use PDO;
use RuntimeException;

final class AdminModel
{
    public function __construct(private PDO $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id_usuario AS id, correo AS email, correo AS name, `password` AS password_hash
             FROM usuarios
             WHERE correo = :email AND rol = "administrador"
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $admin = $statement->fetch();
        return $admin ?: null;
    }

    public function create(string $name, string $email, string $password): int
    {
        return DatabaseLock::run(
            $this->db,
            LegacyIdAllocator::lockNameForTable('usuarios'),
            function () use ($email, $password): int {
                $this->db->beginTransaction();
                try {
                    $usesAutoIncrement = TableSchemaInspector::usesAutoIncrement($this->db, 'usuarios', 'id_usuario');
                    if ($usesAutoIncrement) {
                        $statement = $this->db->prepare(
                            'INSERT INTO usuarios (correo, `password`, rol, id_alumno, id_profesor)
                             VALUES (:email, :password_hash, "administrador", NULL, NULL)'
                        );
                        $statement->execute([
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ]);
                        $id = (int) $this->db->lastInsertId();
                        if ($id < 1) {
                            throw new RuntimeException('La base de datos no devolvió un identificador de usuario válido.');
                        }
                    } else {
                        $nextId = LegacyIdAllocator::nextId($this->db, 'usuarios', 'id_usuario');
                        $statement = $this->db->prepare(
                            'INSERT INTO usuarios (id_usuario, correo, `password`, rol, id_alumno, id_profesor)
                             VALUES (:id_usuario, :email, :password_hash, "administrador", NULL, NULL)'
                        );
                        $statement->execute([
                            'id_usuario' => $nextId,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ]);
                        $id = $nextId;
                    }

                    $this->db->commit();
                    return $id;
                } catch (\Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $exception;
                }
            }
        );
    }
}
