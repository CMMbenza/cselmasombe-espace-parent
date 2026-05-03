<?php
// /parent/includes/db.php
declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'cselmasombe_admin';
$DB_USER = 'cselmasombe_admin';
$DB_PASS = 'na57k,ad-$h#';
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=$DB_CHARSET";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    die("Erreur de connexion à la base : " . $e->getMessage());
}
