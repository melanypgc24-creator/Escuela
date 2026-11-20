<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\AccountStatus;
use App\Core\DatabaseLock;
use App\Core\LegacyIdAllocator;
use App\Core\TableSchemaInspector;
use DomainException;
use PDO;
use PDOException;

/**
 * Administración de cuentas. `usuarios` es la tabla central de autenticación:
 * los alumnos se relacionan con `alumnos` (id_alumno), los maestros con `profesor`
 * (id_profesor) y recepción, dirección y administración no necesitan perfil.
 * Cambiar de rol nunca recrea la cuenta ni borra vínculos o historial.
 */
final class AccountModel
{
    public const STAFF_ROLES = ['maestro', 'recepcionista', 'director', 'administrador'];
    public const ALL_ROLES = ['alumno', 'maestro', 'recepcionista', 'director', 'administrador'];

    private ?array $roleEnum = null;
    private array $columnCache = [];

    public function __construct(private PDO $db)
    {
    }

    public function supportsActiveFlag(): bool
    {
        return AccountStatus::hasActiveColumn($this->db);
    }

    public function supportsStaffProfile(): bool
    {
        foreach (['nombre', 'apellidos', 'telefono', 'fecha_nacimiento'] as $column) {
            if (!$this->hasColumn('usuarios', $column)) {
                return false;
            }
        }
        return true;
    }

    /** Roles que la base de datos acepta hoy (el ENUM crece con la migración 001). */
    public function availableRoles(): array
    {
        if ($this->roleEnum === null) {
            $statement = $this->db->query(
                "SELECT column_type FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'rol'"
            );
            $type = (string) ($statement ? $statement->fetchColumn() : '');
            preg_match_all("/'([^']+)'/", $type, $matches);
            $this->roleEnum = array_values(array_intersect(self::ALL_ROLES, $matches[1] ?? []));
        }
        return $this->roleEnum;
    }

    public function all(): array
    {
        $rows = $this->db->query($this->selectSql() . ' ORDER BY u.id_usuario DESC')->fetchAll();
        return array_map([$this, 'present'], $rows);
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare($this->selectSql() . ' WHERE u.id_usuario = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ? $this->present($row) : null;
    }

    public function create(array $data, int $actorId): int
    {
        $role = (string) ($data['rol'] ?? '');
        if (!in_array($role, self::STAFF_ROLES, true)) {
            throw new DomainException('Solo se pueden crear cuentas de maestro, recepción, dirección o administración. Los alumnos se registran desde el formulario público.');
        }
        $this->assertRoleAvailable($role);
        $email = $this->validEmail((string) ($data['correo'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new DomainException('La contraseña debe tener entre 8 y 72 caracteres.');
        }
        $profile = $this->validProfile($data, $role === 'maestro');

        $userId = DatabaseLock::run(
            $this->db,
            LegacyIdAllocator::lockNameForTable('usuarios'),
            function () use ($role, $email, $password, $profile): int {
                $this->db->beginTransaction();
                try {
                    if ($this->emailTaken($email, null, true)) {
                        throw new DomainException('Ese correo ya tiene una cuenta.');
                    }

                    $professorId = null;
                    if ($role === 'maestro') {
                        $professorId = $this->insertProfessor($profile);
                    }

                    $columns = ['correo', '`password`', 'rol', 'id_alumno', 'id_profesor'];
                    $params = [
                        'correo' => $email,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'rol' => $role,
                        'id_alumno' => null,
                        'id_profesor' => $professorId,
                    ];
                    if ($role !== 'maestro' && $this->supportsStaffProfile()) {
                        foreach (['nombre', 'apellidos', 'telefono', 'fecha_nacimiento'] as $field) {
                            $columns[] = $field;
                            $params[$field] = $profile[$field];
                        }
                    }
                    if ($role === 'maestro' && $this->hasColumn('usuarios', 'fecha_nacimiento')) {
                        $columns[] = 'fecha_nacimiento';
                        $params['fecha_nacimiento'] = $profile['fecha_nacimiento'];
                    }

                    $nextId = null;
                    if (!TableSchemaInspector::usesAutoIncrement($this->db, 'usuarios', 'id_usuario')) {
                        $nextId = LegacyIdAllocator::nextId($this->db, 'usuarios', 'id_usuario');
                        array_unshift($columns, 'id_usuario');
                        $params['id_usuario'] = $nextId;
                    }
                    $placeholders = array_map(
                        static fn (string $column): string => ':' . trim($column, '`'),
                        $columns
                    );
                    $this->db->prepare(
                        'INSERT INTO usuarios (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                    )->execute($params);
                    $id = $nextId ?? (int) $this->db->lastInsertId();
                    if ($nextId === null && $id < 1) {
                        throw new \RuntimeException('La base de datos no devolvió un identificador de usuario válido.');
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

        $this->audit($actorId, 'crear_cuenta', $userId, ['rol' => $role]);
        return $userId;
    }

    public function update(int $id, array $data, int $actorId): void
    {
        $account = $this->find($id);
        if ($account === null) {
            throw new DomainException('No se encontró la cuenta seleccionada.');
        }
        $email = $this->validEmail((string) ($data['correo'] ?? ''));
        $profile = $this->validProfile($data, false);

        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id_usuario FROM usuarios WHERE id_usuario = :id FOR UPDATE')->execute(['id' => $id]);
            if ($this->emailTaken($email, $id, true)) {
                throw new DomainException('Ese correo ya pertenece a otra cuenta.');
            }
            $this->db->prepare('UPDATE usuarios SET correo = :correo WHERE id_usuario = :id')
                ->execute(['correo' => $email, 'id' => $id]);

            $role = $account['rol'];
            if ($role === 'alumno' && $account['id_alumno'] !== null) {
                $this->db->prepare(
                    'UPDATE alumnos SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono,
                            fecha_nacimiento = COALESCE(:fecha_nacimiento, fecha_nacimiento)
                     WHERE id_alumno = :id'
                )->execute([
                    'nombre' => $profile['nombre'], 'apellidos' => $profile['apellidos'],
                    'telefono' => $profile['telefono'], 'fecha_nacimiento' => $profile['fecha_nacimiento'],
                    'id' => $account['id_alumno'],
                ]);
            } elseif ($role === 'maestro' && $account['id_profesor'] !== null) {
                $this->db->prepare(
                    'UPDATE profesor SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono
                     WHERE id_profesor = :id'
                )->execute([
                    'nombre' => $profile['nombre'], 'apellidos' => $profile['apellidos'],
                    'telefono' => $profile['telefono'], 'id' => $account['id_profesor'],
                ]);
                if ($this->hasColumn('usuarios', 'fecha_nacimiento')) {
                    $this->db->prepare('UPDATE usuarios SET fecha_nacimiento = :fecha WHERE id_usuario = :id')
                        ->execute(['fecha' => $profile['fecha_nacimiento'], 'id' => $id]);
                }
            } elseif ($this->supportsStaffProfile()) {
                $this->db->prepare(
                    'UPDATE usuarios SET nombre = :nombre, apellidos = :apellidos, telefono = :telefono,
                            fecha_nacimiento = :fecha_nacimiento
                     WHERE id_usuario = :id'
                )->execute([
                    'nombre' => $profile['nombre'], 'apellidos' => $profile['apellidos'],
                    'telefono' => $profile['telefono'], 'fecha_nacimiento' => $profile['fecha_nacimiento'], 'id' => $id,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->audit($actorId, 'editar_cuenta', $id, []);
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        if (!$this->supportsActiveFlag()) {
            throw new DomainException('Activar o desactivar cuentas requiere aplicar la migración 20261120_006 (pendiente de autorización).');
        }
        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($id);
            if (!$active) {
                if ($id === $actorId) {
                    throw new DomainException('No puedes desactivar tu propia cuenta.');
                }
                if ($account['rol'] === 'administrador') {
                    $this->assertAnotherActiveAdministrator($id);
                }
            }
            $this->db->prepare('UPDATE usuarios SET activo = :activo WHERE id_usuario = :id')
                ->execute(['activo' => $active ? 1 : 0, 'id' => $id]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actorId, $active ? 'activar_cuenta' : 'desactivar_cuenta', $id, []);
    }

    public function resetPassword(int $id, string $password, string $confirmation, int $actorId): void
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new DomainException('La contraseña debe tener entre 8 y 72 caracteres.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new DomainException('Las contraseñas no coinciden.');
        }

        $this->db->beginTransaction();
        try {
            $this->lockAccount($id);
            $this->db->prepare('UPDATE usuarios SET `password` = :password WHERE id_usuario = :id')
                ->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actorId, 'restablecer_contrasena', $id, []);
    }

    public function delete(int $id, int $actorId): void
    {
        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($id);
            if ($id === $actorId) {
                throw new DomainException('No puedes eliminar tu propia cuenta.');
            }
            if ($account['rol'] === 'administrador') {
                $this->assertAnotherActiveAdministrator($id);
            }
            $email = $this->db->prepare('SELECT correo FROM usuarios WHERE id_usuario = :id');
            $email->execute(['id' => $id]);
            $correo = (string) $email->fetchColumn();

            // Solo se elimina la cuenta de acceso: la ficha de alumno o profesor y su historial se conservan.
            $this->db->prepare('DELETE FROM usuarios WHERE id_usuario = :id')->execute(['id' => $id]);
            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new DomainException('No se puede eliminar la cuenta porque tiene registros vinculados.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actorId, 'eliminar_cuenta', $id, ['rol' => (string) $account['rol'], 'correo' => $correo]);
    }

    public function changeRole(int $id, string $role, int $actorId): void
    {
        if (!in_array($role, self::ALL_ROLES, true)) {
            throw new DomainException('Selecciona un rol válido.');
        }
        $this->assertRoleAvailable($role);

        $this->db->beginTransaction();
        try {
            $account = $this->lockAccount($id);
            $previous = (string) $account['rol'];
            if ($previous === $role) {
                $this->db->rollBack();
                return;
            }
            if ($previous === 'administrador') {
                if ($id === $actorId) {
                    throw new DomainException('No puedes quitarte a ti mismo el rol de administrador.');
                }
                $this->assertAnotherActiveAdministrator($id);
            }

            $current = $this->find($id);
            if ($role === 'alumno' && $account['id_alumno'] === null) {
                throw new DomainException('Esta cuenta no tiene ficha de alumno. Las cuentas de alumno se crean desde el registro público.');
            }
            if ($role === 'maestro' && $account['id_profesor'] === null) {
                $professorId = $this->insertProfessor([
                    'nombre' => $current['nombre'] ?: 'Pendiente',
                    'apellidos' => $current['apellidos'] ?: 'Pendiente',
                    'telefono' => $current['telefono'],
                ]);
                $this->db->prepare('UPDATE usuarios SET id_profesor = :profesor WHERE id_usuario = :id')
                    ->execute(['profesor' => $professorId, 'id' => $id]);
            }
            if (in_array($role, ['recepcionista', 'director', 'administrador'], true) && $this->supportsStaffProfile()) {
                // Conserva los datos personales visibles al pasar a personal sin perfil propio.
                $this->db->prepare(
                    'UPDATE usuarios SET nombre = COALESCE(nombre, :nombre), apellidos = COALESCE(apellidos, :apellidos),
                            telefono = COALESCE(telefono, :telefono), fecha_nacimiento = COALESCE(fecha_nacimiento, :fecha)
                     WHERE id_usuario = :id'
                )->execute([
                    'nombre' => $current['nombre'] ?: null, 'apellidos' => $current['apellidos'] ?: null,
                    'telefono' => $current['telefono'] ?: null, 'fecha' => $current['fecha_nacimiento'] ?: null, 'id' => $id,
                ]);
            }

            $this->db->prepare('UPDATE usuarios SET rol = :rol WHERE id_usuario = :id')->execute(['rol' => $role, 'id' => $id]);
            $check = $this->db->prepare('SELECT rol FROM usuarios WHERE id_usuario = :id');
            $check->execute(['id' => $id]);
            if ($check->fetchColumn() !== $role) {
                throw new DomainException('La base de datos no admite este rol. Aplica las migraciones del esquema.');
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        $this->audit($actorId, 'cambiar_rol', $id, ['rol_anterior' => $previous, 'rol_nuevo' => $role]);
    }

    private function selectSql(): string
    {
        $select = ['u.id_usuario', 'u.correo', 'u.rol', 'u.id_alumno', 'u.id_profesor',
            'a.nombre AS a_nombre', 'a.apellidos AS a_apellidos', 'a.telefono AS a_telefono', 'a.fecha_nacimiento AS a_fecha',
            'p.nombre AS p_nombre', 'p.apellidos AS p_apellidos', 'p.telefono AS p_telefono'];
        $select[] = $this->supportsActiveFlag() ? 'u.activo' : '1 AS activo';
        foreach (['nombre', 'apellidos', 'telefono', 'fecha_nacimiento'] as $column) {
            $select[] = $this->hasColumn('usuarios', $column) ? 'u.' . $column . ' AS u_' . $column : 'NULL AS u_' . $column;
        }
        $select[] = $this->hasColumn('alumnos', 'matricula') ? 'a.matricula AS a_matricula' : 'NULL AS a_matricula';
        $select[] = 'a.estatus AS a_estatus';
        $select[] = 'c.color AS a_cinturon';
        return 'SELECT ' . implode(', ', $select) . '
                FROM usuarios u
                LEFT JOIN alumnos a ON a.id_alumno = u.id_alumno
                LEFT JOIN cinturon c ON c.id_cinturon = a.id_cinturon
                LEFT JOIN profesor p ON p.id_profesor = u.id_profesor';
    }

    private function present(array $row): array
    {
        $pick = static function (array $candidates): ?string {
            foreach ($candidates as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
            return null;
        };
        $order = match ($row['rol']) {
            'alumno' => ['a', 'u', 'p'],
            'maestro' => ['p', 'u', 'a'],
            default => ['u', 'a', 'p'],
        };
        $field = static function (string $name) use ($row, $order, $pick): ?string {
            $values = [];
            foreach ($order as $source) {
                $key = $source . '_' . ($name === 'fecha_nacimiento' ? ($source === 'u' ? 'fecha_nacimiento' : 'fecha') : $name);
                $values[] = $row[$key] ?? null;
            }
            return $pick($values);
        };
        return [
            'id_usuario' => (int) $row['id_usuario'],
            'correo' => (string) $row['correo'],
            'rol' => (string) $row['rol'],
            'id_alumno' => $row['id_alumno'] === null ? null : (int) $row['id_alumno'],
            'id_profesor' => $row['id_profesor'] === null ? null : (int) $row['id_profesor'],
            'activo' => (int) $row['activo'] === 1,
            'nombre' => $field('nombre'),
            'apellidos' => $field('apellidos'),
            'telefono' => $field('telefono'),
            'fecha_nacimiento' => $field('fecha_nacimiento'),
            'matricula' => $row['a_matricula'] ?? null,
            'cinturon' => $row['a_cinturon'] ?? null,
            'estatus_alumno' => $row['a_estatus'] === null ? null : (int) $row['a_estatus'] === 1,
        ];
    }

    private function validEmail(string $email): string
    {
        $email = trim($email);
        $isLocal = preg_match('/^[^@\s]+@localhost$/iD', $email) === 1;
        if ($email === '' || strlen($email) > 100 || (!$isLocal && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new DomainException('Escribe un correo electrónico válido.');
        }
        return $email;
    }

    private function validProfile(array $data, bool $required): array
    {
        $profile = [];
        foreach (['nombre' => 100, 'apellidos' => 100, 'telefono' => 20] as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));
            if (mb_strlen($value, 'UTF-8') > $max) {
                throw new DomainException('Uno de los datos personales excede la longitud permitida.');
            }
            $profile[$field] = $value === '' ? null : $value;
        }
        if ($required && ($profile['nombre'] === null || $profile['apellidos'] === null)) {
            throw new DomainException('Escribe el nombre y los apellidos del maestro.');
        }
        $birth = trim((string) ($data['fecha_nacimiento'] ?? ''));
        $profile['fecha_nacimiento'] = null;
        if ($birth !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
            if ($date === false || $date->format('Y-m-d') !== $birth || $date > new \DateTimeImmutable('today')) {
                throw new DomainException('La fecha de nacimiento no es válida.');
            }
            $profile['fecha_nacimiento'] = $birth;
        }
        return $profile;
    }

    private function emailTaken(string $email, ?int $exceptId, bool $lock): bool
    {
        $statement = $this->db->prepare(
            'SELECT id_usuario FROM usuarios WHERE correo = :correo' . ($exceptId !== null ? ' AND id_usuario <> :id' : '')
            . ' LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $params = ['correo' => $email];
        if ($exceptId !== null) {
            $params['id'] = $exceptId;
        }
        $statement->execute($params);
        return $statement->fetchColumn() !== false;
    }

    private function insertProfessor(array $profile): int
    {
        $columns = ['nombre', 'apellidos', 'telefono'];
        $params = [
            'nombre' => $profile['nombre'],
            'apellidos' => $profile['apellidos'],
            'telefono' => $profile['telefono'] ?? '',
        ];
        $nextId = null;
        if (!TableSchemaInspector::usesAutoIncrement($this->db, 'profesor', 'id_profesor')) {
            $nextId = (int) $this->db->query('SELECT COALESCE(MAX(id_profesor), 0) + 1 FROM profesor')->fetchColumn();
            array_unshift($columns, 'id_profesor');
            $params['id_profesor'] = $nextId;
        }
        $this->db->prepare(
            'INSERT INTO profesor (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')'
        )->execute($params);
        return $nextId ?? (int) $this->db->lastInsertId();
    }

    private function lockAccount(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT id_usuario, rol, id_alumno, id_profesor FROM usuarios WHERE id_usuario = :id FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $account = $statement->fetch();
        if ($account === false) {
            throw new DomainException('No se encontró la cuenta seleccionada.');
        }
        return $account;
    }

    private function assertAnotherActiveAdministrator(int $exceptId): void
    {
        $activeFilter = $this->supportsActiveFlag() ? ' AND activo = 1' : '';
        $statement = $this->db->prepare(
            "SELECT id_usuario FROM usuarios WHERE rol = 'administrador' AND id_usuario <> :id" . $activeFilter . ' FOR UPDATE'
        );
        $statement->execute(['id' => $exceptId]);
        if ($statement->fetchAll() === []) {
            throw new DomainException('Debe quedar al menos un administrador activo en el sistema.');
        }
    }

    private function assertRoleAvailable(string $role): void
    {
        if (!in_array($role, $this->availableRoles(), true)) {
            throw new DomainException('El rol seleccionado todavía no existe en la base de datos. Aplica las migraciones del esquema (con autorización) e inténtalo de nuevo.');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!isset($this->columnCache[$key])) {
            $statement = $this->db->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1'
            );
            $statement->execute(['t' => $table, 'c' => $column]);
            $this->columnCache[$key] = $statement->fetchColumn() !== false;
        }
        return $this->columnCache[$key];
    }

    private function audit(int $actorId, string $action, int $entityId, array $details): void
    {
        try {
            (new AuditModel($this->db))->record($actorId, $action, 'usuarios', $entityId, $details);
        } catch (PDOException $exception) {
            error_log('Auditoría de cuentas no disponible (' . $action . '): ' . $exception->getMessage());
        }
    }
}
