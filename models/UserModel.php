<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class UserModel
{
    public function __construct(private PDO $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id_usuario, correo, `password`, rol, id_alumno, id_profesor
             FROM escuela_usuarios
             WHERE correo = :correo
             LIMIT 1'
        );
        $statement->execute(['correo' => $email]);
        $user = $statement->fetch();

        return $user ?: null;
    }
}
