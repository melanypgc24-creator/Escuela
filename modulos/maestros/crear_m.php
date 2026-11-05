<?php
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $_POST['nombre'];
    $apellidos = $_POST['apellidos'];
    $telefono = $_POST['telefono'];

    $sql = "INSERT INTO profesor (nombre, apellidos, telefono) VALUES (:nombre, :apellidos, :telefono)";
    $stmt = $conexion->prepare($sql);
    
    $stmt->execute([
        ':nombre' => $nombre,
        ':apellidos' => $apellidos,
        ':telefono' => $telefono
    ]);
    
    echo "<b>¡Maestro guardado correctamente!</b> <a href='maestros.php'>Volver a la lista</a><br><br>";
}

include '../../includes/header.php';
?>

<h1>Registrar nuevo Maestro</h1>

<form method="POST" action="">
    <label>Nombre:</label><br>
    <input type="text" name="nombre" required><br><br>

    <label>Apellidos:</label><br>
    <input type="text" name="apellidos" required><br><br>

    <label>Teléfono:</label><br>
    <input type="tel" name="telefono"><br><br>

    <button type="submit">Guardar Maestro</button>
</form>

<?php include '../../includes/footer.php'; ?>