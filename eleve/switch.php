<?php
// /parent/eleve/switch.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)($_GET['eleve_id'] ?? 0);

if ($mid <= 0 || $eid <= 0) {
  header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

// Vérifier que l'élève appartient au ménage
$stmt = $pdo->prepare("SELECT id FROM eleve WHERE id=:eid AND menage=:mid LIMIT 1");
$stmt->execute([':eid'=>$eid, ':mid'=>$mid]);
$ok = $stmt->fetchColumn();

if (!$ok) {
  // Sécurité : ne pas accepter un élève qui n'appartient pas à ce ménage
  header('Location: ' . BASE_URL . '/dashboard.php?err=eleve'); exit;
}

// Activer cet élève dans la session
set_current_eleve($eid);

// (facultatif) Initialiser le timestamp de "dernière visite" des soumissions pour le badge "Nouveau"
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['eleve_submissions_last_visit'] = (new DateTime('now'))->format('Y-m-d H:i:s');

// Rediriger vers l'espace élève (page d’accueil élève)
// Changez cette cible si vous avez une autre home élève (ex: /parent/eleve/index.php)
header('Location: ' . BASE_URL . '/eleve/quizzes.php');
exit;
