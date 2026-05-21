<?php
// --- connection.php ---
// Database connection configuration and shared mysqli connection variable.
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'finals';

$connection = mysqli_connect($host, $user, $password, $database);

if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>

//Nicole Sambile
//John Paul Santos
//Jessica Salalila