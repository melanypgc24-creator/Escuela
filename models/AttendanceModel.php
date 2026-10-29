<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AttendanceModel
{
    public function __construct(private PDO $db)
    {
    }

    public function all(): array
    {
        return $this->db->query(
            'SELECT a.id, a.student_id, CONCAT(s.first_name, " ", s.last_name) AS student_name, a.attended_at
             FROM attendance a JOIN students s ON s.id = a.student_id ORDER BY a.attended_at DESC'
        )->fetchAll();
    }

    public function create(int $studentId, string $attendedAt): int
    {
        $statement = $this->db->prepare('INSERT INTO attendance (student_id, attended_at) VALUES (:student_id, :attended_at)');
        $statement->execute(['student_id' => $studentId, 'attended_at' => $attendedAt]);
        return (int) $this->db->lastInsertId();
    }
}
