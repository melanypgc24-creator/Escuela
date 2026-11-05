<?php
require_once '../../config/database.php';

if (!isset($_GET['id'])) {
    die("Error: No se proporcionó el ID de la clase.");
}
$id_clase = $_GET['id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre_clase = $_POST['nombre_clase'];
    $id_profesor = $_POST['id_profesor'];
    $dias = $_POST['dias'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fin = $_POST['hora_fin'];
    $capacidad = $_POST['capacidad'];

    $sql_update = "UPDATE clases SET 
                    nombre_clase = :nombre_clase, 
                    id_profesor = :id_profesor, 
                    dias = :dias, 
                    hora_inicio = :hora_inicio, 
                    hora_fin = :hora_fin, 
                    capacidad = :capacidad 
                   WHERE id_clase = :id_clase";
                   
    $stmt_update = $conexion->prepare($sql_update);
    $stmt_update->execute([
        ':nombre_clase' => $nombre_clase,
        ':id_profesor' => $id_profesor,
        ':dias' => $dias,
        ':hora_inicio' => $hora_inicio,
        ':hora_fin' => $hora_fin,
        ':capacidad' => $capacidad,
        ':id_clase' => $id_clase
    ]);
    
    echo "<b>¡Clase actualizada correctamente!</b> <a href='lista.php'>Volver a la lista</a><br><br>";
}

$sql_clase = "SELECT * FROM clases WHERE id_clase = :id_clase";
$stmt_clase = $conexion->prepare($sql_clase);
$stmt_clase->execute([':id_clase' => $id_clase]);
$clase = $stmt_clase->fetch(PDO::FETCH_ASSOC);

if (!$clase) {
    die("Error: La clase no existe.");
}


$sql_profesores = "SELECT id_profesor, nombre, apellidos FROM profesor";
$stmt_p = $conexion->prepare($sql_profesores);
$stmt_p->execute();
$profesores = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
//estilos
<link rel="stylesheet" href="/Escuela/css/estilos.css">
<h1>Editar datos de la Clase</h1>

<form method="POST" action="">
    <label>Nombre de la Clase:</label><br>
    <input type="text" name="nombre_clase" value="<?php echo htmlspecialchars($clase['nombre_clase']); ?>" required><br><br>

    <label>Profesor a cargo:</label><br>
    <select name="id_profesor" required>
        <option value="">-- Seleccione un profesor --</option>
        <?php foreach ($profesores as $profesor): ?>
            <option value="<?php echo $profesor['id_profesor']; ?>" 
            <?php echo ($clase['id_profesor'] == $profesor['id_profesor']) ? 'selected' : ''; ?>>
                <?php echo $profesor['nombre'] . ' ' . $profesor['apellidos']; ?>
            </option>
        <?php endforeach; ?>
    </select><br><br>

    <label>Días de entrenamiento:</label><br>
    <input type="text" name="dias" value="<?php echo htmlspecialchars($clase['dias']); ?>" required><br><br>

    <label>Hora de Inicio:</label><br>
    <input type="time" name="hora_inicio" value="<?php echo $clase['hora_inicio']; ?>" required><br><br>

    <label>Hora de Fin:</label><br>
    <input type="time" name="hora_fin" value="<?php echo $clase['hora_fin']; ?>" required><br><br>

    <label>Capacidad Máxima de Alumnos:</label><br>
    <input type="number" name="capacidad" min="1" value="<?php echo $clase['capacidad']; ?>" required><br><br>

    <button type="submit">Actualizar Clase</button>
</form>

<?php include '../../includes/footer.php'; ?>