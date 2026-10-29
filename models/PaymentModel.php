<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class PaymentModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT p.id, p.student_id, CONCAT(s.first_name, " ", s.last_name) AS student_name,
                    p.amount, p.paid_at, p.method, p.reference
             FROM payments p JOIN students s ON s.id = p.student_id ORDER BY p.paid_at DESC'
        )->fetchAll();
    }

    public function create(array $data): int
    {
        $statement = $this->db->prepare('INSERT INTO payments (student_id, amount, paid_at, method, reference) VALUES (:student_id, :amount, :paid_at, :method, :reference)');
        $statement->execute($data);
        return (int) $this->db->lastInsertId();
    }
}
