<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('students.read');
$pdo = require dirname(__DIR__, 2) . '/config/conexion.php';
$conexion = $pdo;

$sql = 'SELECT a.id_alumno, a.matricula, a.nombre, a.apellidos, a.fecha_nacimiento,
               a.telefono, a.fecha_ingreso, a.estatus, c.color AS cinturon
        FROM alumnos a
        LEFT JOIN cinturon c ON a.id_cinturon = c.id_cinturon
        ORDER BY a.apellidos, a.nombre';
$alumnos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$role = current_authenticated_role();
$canWrite = \App\Core\Permissions::allows($role, 'students.write');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Lista de alumnos</h1>
    <?php if ($canWrite): ?><a href="crear_al.php">Agregar alumno</a><?php endif; ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Matrícula</th><th>Nombre</th><th>Apellidos</th><th>Fecha de nacimiento</th>
                    <th>Teléfono</th><th>Fecha de ingreso</th><th>Cinturón</th><th>Estado</th>
                    <?php if ($canWrite): ?><th>Acciones</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnos as $alumno): ?>
                    <tr>
                        <td><?= $escape($alumno['matricula']) ?></td>
                        <td><?= $escape($alumno['nombre']) ?></td>
                        <td><?= $escape($alumno['apellidos']) ?></td>
                        <td><?= $escape($alumno['fecha_nacimiento']) ?></td>
                        <td><?= $escape($alumno['telefono']) ?></td>
                        <td><?= $escape($alumno['fecha_ingreso']) ?></td>
                        <td><?= $escape($alumno['cinturon'] ?? 'Sin asignar') ?></td>
                        <td><?= (int) $alumno['estatus'] === 1 ? 'Activo' : 'Inactivo' ?></td>
                        <?php if ($canWrite): ?>
                            <td><a href="editar_al.php?id=<?= $escape($alumno['id_alumno']) ?>">Editar</a></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($alumnos === []): ?>
                    <tr><td colspan="<?= $canWrite ? '9' : '8' ?>">No hay alumnos registrados.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
