<?php
declare(strict_types=1);

namespace App\Core;

final class Permissions
{
    private const ROLE_PERMISSIONS = [
        'administrador' => ['*'],
        'director' => [
            'students.read', 'professors.read', 'classes.read', 'attendance.read',
            'tariffs.read', 'payments.read', 'debts.read', 'private_classes.read',
            'reports.read', 'billing_config.read', 'notifications.read_own',
            'notifications.mark_own', 'enrollments.read',
        ],
        'maestro' => [
            'students.read_assigned', 'classes.read_assigned', 'attendance.read_assigned',
            'attendance.write_assigned', 'notifications.read_own', 'notifications.mark_own', 'enrollments.read_assigned',
        ],
        'recepcionista' => [
            'students.read', 'students.write', 'professors.read', 'classes.read',
            'tariffs.read', 'payments.read', 'payments.create', 'debts.read',
            'private_classes.read', 'private_classes.create', 'reports.operational', 'reports.read',
            'enrollments.read',
        ],
        'alumno' => [
            'students.read_own', 'classes.read_own', 'attendance.read_own',
            'debts.read_own', 'payments.read_own', 'private_classes.read_own',
            'notifications.read_own', 'notifications.mark_own', 'enrollments.read_own',
        ],
    ];

    public static function allows(string $role, string $permission): bool
    {
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function __construct()
    {
    }
}
