<?php
// /parent/includes/db.php
declare(strict_types=1);

// 1. Emplacement du fichier .env à la racine
$envFile = __DIR__ . '/../.env';

if (!file_exists($envFile)) {
    die("Fichier de configuration .env introuvable à la racine.");
}

// 2. Lecture et chargement des variables
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    // Ignorer les commentaires (#)
    if (strpos(trim($line), '#') === 0) continue;

    // Découper sur le premier '='
    if (strpos($line, '=') !== false) {
        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'"); // Retire espaces et guillemets éventuels
        
        $_ENV[$name] = $value;
    }
}

// 3. Connexion PDO
$dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset={$_ENV['DB_CHARSET']}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], $options);
} catch (PDOException $e) {
    error_log("Erreur PDO : " . $e->getMessage());
    die("Erreur de connexion à la base de données.");
}