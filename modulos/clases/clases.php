<?php
require_once '../../config/database.php';
include '../../includes/header.php';

$sql = "SELECT c.id_clase, c.nombre_clase, c.dias, c.hora_inicio, c.hora_fin, c.capacidad, 
               p.nombre AS prof_nombre, p.apellidos AS prof_apellidos 
        FROM clases c
        LEFT JOIN profesor p ON c.id_profesor = p.id_profesor";
$stmt = $conexion->prepare($sql);
$stmt->execute();
$clases = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<link rel="stylesheet" href="/Escuela/css/estilos.css">
<h1>Lista de Clases</h1>
<a href="crear_cl.php">Agregar Nueva Clase</a>
<br><br>

<table border="1">
    <tr>
        <th>ID</th>
        <th>Nombre de la Clase</th>
        <th>Profesor</th>
        <th>Días</th>
        <th>Horario</th>
        <th>Capacidad Máxima</th>
        <th>Acciones</th>
    </tr>
    <?php foreach ($clases as $clase): ?>
    <tr>
        <td><?php echo $clase['id_clase']; ?></td>
        <td><?php echo htmlspecialchars($clase['nombre_clase']); ?></td>
        
        <td><?php echo htmlspecialchars($clase['prof_nombre'] . ' ' . $clase['prof_apellidos']); ?></td>
        <td><?php echo htmlspecialchars($clase['dias']); ?></td>
        
        <td><?php echo date('H:i', strtotime($clase['hora_inicio'])) . ' - ' . date('H:i', strtotime($clase['hora_fin'])); ?></td>
        <td><?php echo $clase['capacidad']; ?> alumnos</td>
        <td>
            <a href="editar_cl.php?id=<?php echo $clase['id_clase']; ?>">Editar</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php include '../../includes/footer.php'; ?>