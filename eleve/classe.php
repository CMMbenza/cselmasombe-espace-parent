<?php
// /parent/eleve/classe.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)$_SESSION['parent']['id'];
$eid = get_current_eleve_id();
if ($eid<=0) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$eleve=null; $profs=[];

$stmt = $pdo->prepare("
  SELECT e.id, e.nom, e.postnom, e.prenom, e.classe, c.description AS classe_desc, cy.description AS cycle_desc
  FROM eleve e
  JOIN classe c ON c.id = e.classe
  LEFT JOIN cycle cy ON cy.id = c.cycle
  WHERE e.id=:id AND e.menage=:mid
  LIMIT 1
");
$stmt->execute([':id'=>$eid, ':mid'=>$mid]);
$eleve = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$eleve) { set_current_eleve(0); header('Location: '.BASE_URL.'/dashboard.php'); exit; }

$stmt = $pdo->prepare("
  SELECT a.nom, a.postnom, a.prenom, a.telephone, cr.intitule AS cours
  FROM affectation_prof_classe apc
  JOIN agent a ON a.id = apc.agent_id
  JOIN cours cr ON cr.id = apc.cours_id
  WHERE apc.classe_id=:cid
  ORDER BY a.nom, a.postnom, a.prenom, cr.intitule
");
$stmt->execute([':cid'=>(int)$eleve['classe']]);
$profs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container">
  <h1 class="h5 mb-3">Ma classe & profs</h1>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="mb-2"><strong><?= e($eleve['nom'].' '.$eleve['postnom'].' '.$eleve['prenom']) ?></strong></div>
      <div class="small text-muted mb-3">Classe: <strong><?= e($eleve['classe_desc']) ?></strong> — Cycle: <strong><?= e($eleve['cycle_desc'] ?? '—') ?></strong></div>

      <h6>Enseignants</h6>
      <?php if (!$profs): ?>
        <div class="text-muted">Aucun professeur affecté.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr class="table-light"><th>Professeur</th><th>Cours</th><th>Téléphone</th></tr></thead>
            <tbody>
              <?php foreach ($profs as $p): ?>
                <tr>
                  <td><?= e($p['nom'].' '.$p['postnom'].' '.$p['prenom']) ?></td>
                  <td><?= e($p['cours']) ?></td>
                  <td><?= e($p['telephone'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../layout/footer.php'; ?>
