<?php
// /parent/save_email.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/db.php';      // $pdo
require_once __DIR__ . '/includes/helpers.php'; // e(), redirect(), BASE_URL

if (!isset($_SESSION['parent']['id'])) {
    // Si l'utilisateur n'est pas connecté
    redirect('login.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Validation
    if (empty($email)) {
        $error = "Veuillez entrer un email.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email n'est pas valide.";
    } elseif (empty($password)) {
        $error = "Veuillez entrer un mot de passe.";
    } elseif (strlen($password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } else {
        try {
            // 🔐 HASH du mot de passe
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE menage 
                SET email = :email, password = :password 
                WHERE id = :id
            ");

            $stmt->execute([
                ':email'    => $email,
                ':password' => $passwordHash,
                ':id'       => $_SESSION['parent']['id']
            ]);

            // Session
            $_SESSION['parent']['email'] = $email;

            redirect('dashboard.php');

        } catch (Throwable $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// Si on arrive ici, c'est qu'il y a eu une erreur
if ($error) {
    $_SESSION['form_error'] = $error;
    redirect('dashboard.php');
}