<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AdminModel
{
    public function __construct(private PDO $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT id, name, email, password_hash FROM admins WHERE email = :email AND active = 1');
        $statement->execute(['email' => $email]);
        $admin = $statement->fetch();
        return $admin ?: null;
    }

    public function create(string $name, string $email, string $password): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO admins (name, email, password_hash) VALUES (:name, :email, :password_hash)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        return (int) $this->db->lastInsertId();
    }
}
