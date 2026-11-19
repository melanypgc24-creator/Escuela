<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('professors.read');
require_once dirname(__DIR__, 2) . '/config/database.php';

$profesores = $conexion->query(
    'SELECT id_profesor, nombre, apellidos, telefono FROM profesor ORDER BY apellidos, nombre'
)->fetchAll(PDO::FETCH_ASSOC);
$canManage = \App\Core\Permissions::allows(current_authenticated_role(), 'professors.manage');
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Profesores</h1>
    <?php if ($canManage): ?><a href="crear_m.php">Agregar profesor</a><?php endif; ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Nombre</th><th>Apellidos</th><th>Teléfono</th><?php if ($canManage): ?><th>Acciones</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($profesores as $profesor): ?>
                    <tr>
                        <td><?= $escape($profesor['nombre']) ?></td>
                        <td><?= $escape($profesor['apellidos']) ?></td>
                        <td><?= $escape($profesor['telefono']) ?></td>
                        <?php if ($canManage): ?><td><a href="editar_m.php?id=<?= $escape($profesor['id_profesor']) ?>">Editar</a></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($profesores === []): ?>
                    <tr><td colspan="<?= $canManage ? '4' : '3' ?>">No hay profesores registrados.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
