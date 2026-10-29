<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class DashboardModel
{
    public function __construct(private PDO $db)
    {
    }

    public function summary(): array
    {
        return [
            'active_students' => (int) $this->db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn(),
            'active_memberships' => (int) $this->db->query('SELECT COUNT(*) FROM memberships WHERE active = 1')->fetchColumn(),
            'pending_debts' => (int) $this->db->query("SELECT COUNT(*) FROM debts WHERE status = 'pending'")->fetchColumn(),
            'payments_this_month' => (float) $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND paid_at < DATE_ADD(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 1 MONTH)")->fetchColumn(),
        ];
    }
}
