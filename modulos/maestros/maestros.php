<?php
require_once '../../config/database.php';
include '../../includes/header.php';

$sql = "SELECT id_profesor, nombre, apellidos, telefono FROM profesor";
$stmt = $conexion->prepare($sql);
$stmt->execute();
$profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Lista de Maestros</h1>
<a href="crear_m.php" class="boton_agregar">Agregar Nuevo Maestro</a>
<br><br>

<table border="1">
    <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>Apellidos</th>
        <th>Teléfono</th>
        <th>Acciones</th>
    </tr>
    <?php foreach ($profesores as $profesor): ?>
    <tr>
        <td><?php echo $profesor['id_profesor']; ?></td>
        <td><?php echo htmlspecialchars($profesor['nombre']); ?></td>
        <td><?php echo htmlspecialchars($profesor['apellidos']); ?></td>
        <td><?php echo htmlspecialchars($profesor['telefono']); ?></td>
        <td>
            <a href="editar_m.php?id=<?php echo $profesor['id_profesor']; ?>">Editar</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php include '../../includes/footer.php'; ?>