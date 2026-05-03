<?php
// /parent/enfants.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();
require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$mid = (int)$_SESSION['parent']['id'];
$kids = $pdo->prepare("
  SELECT e.id, e.nom, e.postnom, e.prenom, e.genre, e.anneeScolaire,
         c.description AS classe_desc, cy.description AS cycle_desc
  FROM eleve e
  JOIN classe c ON c.id = e.classe
  LEFT JOIN cycle cy ON cy.id = c.cycle
  WHERE e.menage=:mid
  ORDER BY e.nom, e.postnom, e.prenom
");
$kids->execute([':mid'=>$mid]);
$rows = $kids->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container">
  <h1 class="h5 mb-3">Mes enfants</h1>
  <div class="card shadow-sm">
    <div class="card-body table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr><th>#</th><th>Élève</th><th>Genre</th><th>Classe</th><th>Cycle</th><th>Année</th><th></th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7"><em>Aucun enfant trouvé.</em></td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['nom'].' '.$r['postnom'].' '.$r['prenom']) ?></td>
              <td><?= e($r['genre']) ?></td>
              <td><?= e($r['classe_desc']) ?></td>
              <td><?= e($r['cycle_desc'] ?? '—') ?></td>
              <td><?= e($r['anneeScolaire']) ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/eleve/select.php?id=<?= (int)$r['id'] ?>">
                  Se connecter
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
