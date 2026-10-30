<?php
$host = 'localhost'; 
$dbname = 'escuela'; 
$username = 'root'; 
$password = '';      

try {
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    //echo "Conexión exitosa";

} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>