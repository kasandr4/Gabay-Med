<?php
// config/db.php
// This file creates ONE connection to MySQL that every other page will reuse.

$db_host = "localhost";
$db_user = "root";       // default XAMPP username
$db_pass = "";           // default XAMPP password is blank
$db_name = "gabaymed";   // change this if you named your database something else

// Create the connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// If the connection fails, stop everything and show the error.
// (In a real production system we'd hide this detail from users, but
// while building, seeing the real error helps us fix problems fast.)
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Force UTF-8 so names with special characters (ñ, etc.) save correctly
$conn->set_charset("utf8mb4");
?>
