<?php
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre_clase = $_POST['nombre_clase'];
    $id_profesor = $_POST['id_profesor'];
    $dias = $_POST['dias'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fin = $_POST['hora_fin'];
    $capacidad = $_POST['capacidad'];

    $sql = "INSERT INTO clases (nombre_clase, id_profesor, dias, hora_inicio, hora_fin, capacidad) 
            VALUES (:nombre_clase, :id_profesor, :dias, :hora_inicio, :hora_fin, :capacidad)";
    $stmt = $conexion->prepare($sql);
    
    $stmt->execute([
        ':nombre_clase' => $nombre_clase,
        ':id_profesor' => $id_profesor,
        ':dias' => $dias,
        ':hora_inicio' => $hora_inicio,
        ':hora_fin' => $hora_fin,
        ':capacidad' => $capacidad
    ]);
    
    echo "<b>¡Clase guardada correctamente!</b> <a href='clases.php'>Volver a la lista</a><br><br>";
}

$sql_profesores = "SELECT id_profesor, nombre, apellidos FROM profesor";
$stmt_p = $conexion->prepare($sql_profesores);
$stmt_p->execute();
$profesores = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
<link rel="stylesheet" href="/Escuela/css/estilos.css">
<h1>Crear Nueva Clase</h1>

<form method="POST" action="">
    <label>Nombre de la Clase (Ej. Fundamentos Gi):</label><br>
    <input type="text" name="nombre_clase" required><br><br>

    <label>Profesor a cargo:</label><br>
    <select name="id_profesor" required>
        <option value="">-- Seleccione un profesor --</option>
        <?php foreach ($profesores as $profesor): ?>
            <option value="<?php echo $profesor['id_profesor']; ?>">
                <?php echo $profesor['nombre'] . ' ' . $profesor['apellidos']; ?>
            </option>
        <?php endforeach; ?>
    </select><br><br>

    <label>Días de entrenamiento:</label><br>
    <input type="text" name="dias" placeholder="Lunes, Miércoles y Viernes" required><br><br>

    <label>Hora de Inicio:</label><br>
    <input type="time" name="hora_inicio" required><br><br>

    <label>Hora de Fin:</label><br>
    <input type="time" name="hora_fin" required><br><br>

    <label>Capacidad Máxima de Alumnos:</label><br>
    <input type="number" name="capacidad" min="1" value="20" required><br><br>

    <button type="submit">Guardar Clase</button>
</form>

<?php include '../../includes/footer.php'; ?>