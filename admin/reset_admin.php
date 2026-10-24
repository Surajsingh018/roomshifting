<?php

// Direct connection - no need of db.php
$host = 'localhost';
$dbname = 'roomshift_db';
$user = 'root';
$pass = '';

$newPassword = 'Admin@123';
$hash = password_hash($newPassword, PASSWORD_BCRYPT);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hash]);

    echo 'SUCCESS! Password reset ho gaya!<br><br>';
    echo 'Username: admin<br>';
    echo 'Password: Admin@123<br><br>';
    echo 'Ab login karo.';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
