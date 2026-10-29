<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class StudentModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query('SELECT id, first_name, last_name, email, phone, birth_date, status, created_at FROM students ORDER BY last_name, first_name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id, first_name, last_name, email, phone, birth_date, status, created_at FROM students WHERE id = :id');
        $statement->execute(['id' => $id]);
        $student = $statement->fetch();
        return $student ?: null;
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO students (first_name, last_name, email, phone, birth_date, status)
             VALUES (:first_name, :last_name, :email, :phone, :birth_date, :status)'
        );
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data['id'] = $id;
        $statement = $this->db->prepare(
            'UPDATE students SET first_name = :first_name, last_name = :last_name, email = :email,
             phone = :phone, birth_date = :birth_date, status = :status WHERE id = :id'
        );
        $statement->execute($data);
        return $statement->rowCount() > 0 || $this->find($id) !== null;
    }

    public function delete(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM students WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() > 0;
    }
}
