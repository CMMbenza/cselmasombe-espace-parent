<?php
// /parent/eleve/select.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();

$eid = (int)($_GET['id'] ?? 0);
if ($eid > 0) {
  // Sécurise : vérifie que l'élève appartient au ménage connecté
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM eleve WHERE id=:id AND menage=:mid");
  $stmt->execute([':id'=>$eid, ':mid'=>(int)$_SESSION['parent']['id']]);
  if ((int)$stmt->fetchColumn() === 1) {
    set_current_eleve($eid);
  }
}
redirect(BASE_URL . '/dashboard.php');
