<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class DebtModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT d.id, d.student_id, CONCAT(s.first_name, " ", s.last_name) AS student_name,
                    d.description, d.amount, d.due_date, d.status
             FROM debts d JOIN students s ON s.id = d.student_id
             WHERE d.status = "pending" ORDER BY d.due_date'
        )->fetchAll();
    }
}
