<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth_check.php';
require_permission('classes.read');
require_once dirname(__DIR__, 2) . '/config/database.php';

$sql = 'SELECT cl.id_clase, cl.nombre_clase, cl.dias, cl.hora_inicio, cl.hora_fin, cl.capacidad,
               p.nombre AS prof_nombre, p.apellidos AS prof_apellidos
        FROM clases cl
        JOIN profesor p ON cl.id_profesor = p.id_profesor
        ORDER BY cl.nombre_clase, cl.hora_inicio';
$clases = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$canManage = current_authenticated_role() === 'administrador';
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<main class="admin-main">
    <h1>Clases</h1>
    <?php if ($canManage): ?><a href="crear_cl.php">Agregar clase</a><?php endif; ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Clase</th><th>Profesor</th><th>Días</th><th>Horario</th><th>Capacidad</th><?php if ($canManage): ?><th>Acciones</th><?php endif; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($clases as $clase): ?>
                    <tr>
                        <td><?= $escape($clase['nombre_clase']) ?></td>
                        <td><?= $escape($clase['prof_nombre'] . ' ' . $clase['prof_apellidos']) ?></td>
                        <td><?= $escape($clase['dias']) ?></td>
                        <td><?= $escape(substr($clase['hora_inicio'], 0, 5) . ' - ' . substr($clase['hora_fin'], 0, 5)) ?></td>
                        <td><?= $escape($clase['capacidad']) ?></td>
                        <?php if ($canManage): ?><td><a href="editar_cl.php?id=<?= $escape($clase['id_clase']) ?>">Editar</a></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($clases === []): ?>
                    <tr><td colspan="<?= $canManage ? '6' : '5' ?>">No hay clases registradas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
