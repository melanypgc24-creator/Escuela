<?php
require_once '../../config/database.php';

if (!isset($_GET['id'])) {
    die("Error: No se proporcionó el ID del alumno.");
}
$id_alumno = $_GET['id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $apellidos = $_POST['apellidos'];
    $telefono = $_POST['telefono'];
    $fecha_nacimiento = $_POST['fecha_nacimiento'];
    $fecha_ingreso = $_POST['fecha_ingreso'];
    $id_cinturon = !empty($_POST['id_cinturon']) ? $_POST['id_cinturon'] : null;
    $estatus = $_POST['estatus'];

    $sql_update = "UPDATE alumnos SET 
                    nombre = :nombre, 
                    apellidos = :apellidos, 
                    telefono = :telefono, 
                    fecha_nacimiento = :fecha_nacimiento, 
                    fecha_ingreso = :fecha_ingreso, 
                    id_cinturon = :id_cinturon, 
                    estatus = :estatus 
                   WHERE id_alumno = :id_alumno";
                   
    $stmt_update = $conexion->prepare($sql_update);
    $stmt_update->execute([
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':telefono' => $telefono,
        ':fecha_nacimiento' => $fecha_nacimiento,
        ':fecha_ingreso' => $fecha_ingreso,
        ':id_cinturon' => $id_cinturon,
        ':estatus' => $estatus,
        ':id_alumno' => $id_alumno
    ]);
    
    echo "<b>¡Alumno actualizado correctamente!</b> <a href='lista.php'>Volver a la lista</a><br><br>";
}

$sql_alumno = "SELECT * FROM alumnos WHERE id_alumno = :id_alumno";
$stmt_alumno = $conexion->prepare($sql_alumno);
$stmt_alumno->execute([':id_alumno' => $id_alumno]);
$alumno = $stmt_alumno->fetch(PDO::FETCH_ASSOC);

if (!$alumno) {
    die("Error: El alumno no existe.");
}

$sql_cinturones = "SELECT id_cinturon, color FROM cinturon";
$stmt_c = $conexion->prepare($sql_cinturones);
$stmt_c->execute();
$cinturones = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

include '../../includes/header.php';
?>
//Agregar estilos
<link rel="stylesheet" href="/Escuela/css/estilos.css">
<h1>Editar Alumno</h1>

<form method="POST" action="">
    <label>Nombre:</label><br>
    <input type="text" name="nombre" value="<?php echo htmlspecialchars($alumno['nombre']); ?>" required><br><br>

    <label>Apellidos:</label><br>
    <input type="text" name="apellidos" value="<?php echo htmlspecialchars($alumno['apellidos']); ?>" required><br><br>

    <label>Teléfono:</label><br>
    <input type="tel" name="telefono" value="<?php echo htmlspecialchars($alumno['telefono']); ?>"><br><br>

    <label>Fecha de Nacimiento:</label><br>
    <input type="date" name="fecha_nacimiento" value="<?php echo $alumno['fecha_nacimiento']; ?>" required><br><br>

    <label>Fecha de Ingreso:</label><br>
    <input type="date" name="fecha_ingreso" value="<?php echo $alumno['fecha_ingreso']; ?>" required><br><br>

    <label>Cinturón:</label><br>
    <select name="id_cinturon" required>
        <option value="">-- Seleccione un cinturón --</option>
        <?php foreach ($cinturones as $cinturon): ?>
            <option value="<?php echo $cinturon['id_cinturon']; ?>" <?php echo ($alumno['id_cinturon'] == $cinturon['id_cinturon']) ? 'selected' : ''; ?>>
                <?php echo $cinturon['color']; ?>
            </option>
        <?php endforeach; ?>
    </select><br><br>

    <label>Estatus:</label><br>
    <select name="estatus">
        <option value="1" <?php echo ($alumno['estatus'] == 1) ? 'selected' : ''; ?>>Activo</option>
        <option value="0" <?php echo ($alumno['estatus'] == 0) ? 'selected' : ''; ?>>Inactivo</option>
    </select><br><br>

    <button type="submit">Guardar</button>
</form>

<?php 
include '../../includes/footer.php'; 
?>