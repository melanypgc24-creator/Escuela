<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class MembershipModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, name, description, price, duration_days, active FROM memberships ORDER BY name')->fetchAll();
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare('INSERT INTO memberships (name, description, price, duration_days, active) VALUES (:name, :description, :price, :duration_days, :active)');
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }
}
