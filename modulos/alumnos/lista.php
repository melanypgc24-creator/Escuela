<?php
require_once '../../config/database.php';

$sql = "SELECT a.id_alumno, a.nombre, a.apellidos, a.fecha_nacimiento, a.telefono, a.fecha_ingreso, a.estatus, c.color AS cinturon 
        FROM alumnos a 
        LEFT JOIN cinturon c ON a.id_cinturon = c.id_cinturon";
$stmt = $conexion->prepare($sql);
$stmt->execute();
$alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Lista de Alumnos</h1>
<a href="crear_al.php">Agregar Nuevo Alumno</a>
<br><br>

<table border="1">
    <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>Apellidos</th>
        <th>Fecha de nacimiento</th>
        <th>Teléfono</th>
        <th>Fecha de Ingreso</th>
        <th>Cinturón</th>
        <th>Estatus</th>
        <th>Acciones</th>
    </tr>
    <?php foreach ($alumnos as $alumno): ?>
    <tr>
        <td><?php echo $alumno['id_alumno']; ?></td>
        <td><?php echo $alumno['nombre']; ?></td>
        <td><?php echo $alumno['apellidos']; ?></td>
        <td><?php echo $alumno['fecha_nacimiento']; ?></td>
        <td><?php echo $alumno['telefono']; ?></td>
        <td><?php echo $alumno['fecha_ingreso']; ?></td>
        <td><?php echo $alumno['cinturon'] ?? 'Sin asignar'; ?></td>
        <!-- Convertimos el 1 o 0 de la BD en texto legible -->
        <td><?php echo $alumno['estatus'] == 1 ? 'Activo' : 'Inactivo'; ?></td>
        <td>
            <a href="editar_al.php?id=<?php echo $alumno['id_alumno']; ?>">Editar</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>