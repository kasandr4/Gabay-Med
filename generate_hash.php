<?php
// generate_hash.php
// TEMPORARY helper — use this once to generate a password hash for
// manually-created test accounts (doctor, admin), then delete this file.
//
// Visit: http://localhost/GabayMed/generate_hash.php?password=YourPasswordHere

$password = $_GET['password'] ?? 'staffpass123';
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "Password: " . htmlspecialchars($password) . "<br>";
echo "Hash: " . htmlspecialchars($hash);
