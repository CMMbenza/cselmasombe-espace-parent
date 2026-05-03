<?php
// /parent/eleve/my_submissions.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
if ($eid <= 0) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

// --- Gestion "dernière visite" ---
$nowStr = (new DateTime('now'))->format('Y-m-d H:i:s');
if (empty($_SESSION['eleve_submissions_last_visit'])) {
  // Première visite : initialiser à maintenant, ainsi rien ne sera marqué "Nouveau"
  $_SESSION['eleve_submissions_last_visit'] = $nowStr;
}
$lastVisitStr = $_SESSION['eleve_submissions_last_visit'];
$lastVisitTs  = strtotime($lastVisitStr) ?: 0;

// vérifier que l'élève appartient au ménage
$chk = $pdo->prepare("SELECT e.id, e.classe, c.description AS classe_desc
                      FROM eleve e JOIN classe c ON c.id=e.classe
                      WHERE e.id=:eid AND e.menage=:mid LIMIT 1");
$chk->execute([':eid'=>$eid, ':mid'=>$mid]);
$el = $chk->fetch(PDO::FETCH_ASSOC);
if (!$el) { set_current_eleve(0); header('Location: '.BASE_URL.'/dashboard.php'); exit; }

// charger les soumissions de cet élève
$sql = "SELECT s.id AS submission_id, s.quiz_id, s.statut, s.note_totale, s.date_submitted,
               q.titre, q.type_quiz, q.format, q.date_limite
        FROM quiz_submission s
        JOIN quiz q ON q.id = s.quiz_id
        WHERE s.eleve_id = :eid
        ORDER BY s.date_submitted DESC, s.id DESC";
$st = $pdo->prepare($sql);
$st->execute([':eid'=>$eid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Helper pour badges de statut
function badge_for_statut(string $statut): string {
  $map = [
    'corrige' => 'success',
    'remis'   => 'warning',
  ];
  $cls = $map[$statut] ?? 'secondary';
  return '<span class="badge text-bg-'.$cls.'">'.htmlspecialchars($statut, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
}

?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="mt-3">
            <h1 class="h5 mb-0">Mes soumissions</h1>
            <a class="btn btn-dark" href="<?= BASE_URL ?>/eleve/quizzes.php">&larr; Retour aux quiz</a>
        </div>
        <div class="small text-muted">
            Dernière visite : <strong><?= htmlspecialchars($lastVisitStr, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></strong>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Quiz</th>
                        <th>Type / Format</th>
                        <th>Statut</th>
                        <th class="text-end">Note</th>
                        <th>Remis le</th>
                        <th style="width:1%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7"><em>Aucune soumission pour l’instant.</em></td>
                    </tr>
                    <?php else: foreach ($rows as $r): ?>
                    <?php
              $isNew = false;
              $submittedTs = strtotime((string)$r['date_submitted']) ?: 0;
              if ($submittedTs > $lastVisitTs) $isNew = true;

              $statutBadge = badge_for_statut((string)$r['statut']);
              $noteCell = ($r['note_totale'] !== null)
                ? number_format((float)$r['note_totale'], 2, ',', ' ')
                : '—';
            ?>
                    <tr>
                        <td><?= (int)$r['submission_id'] ?></td>
                        <td>
                            <div class="fw-semibold d-flex align-items-center gap-2">
                                <?= htmlspecialchars($r['titre'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>
                                <?php if ($isNew): ?>
                                <span class="badge text-bg-info">Nouveau</span>
                                <?php endif; ?>
                                <?php if ($r['statut']==='corrige'): ?>
                                <span class="badge text-bg-success">Corrigé</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted">Date limite :
                                <?= htmlspecialchars((string)$r['date_limite'] ?: '—', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>
                            </div>
                        </td>
                        <td>
                            <?= htmlspecialchars($r['type_quiz'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?> /
                            <span
                                class="badge text-bg-secondary"><?= htmlspecialchars($r['format'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></span>
                        </td>
                        <td><?= $statutBadge ?></td>
                        <td class="text-end"><?= $noteCell ?></td>
                        <td><?= htmlspecialchars($r['date_submitted'], ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary"
                                href="<?= BASE_URL ?>/eleve/submission_view.php?id=<?= (int)$r['submission_id'] ?>">
                                Voir
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            <div class="small text-muted">Astuce : le badge <span class="badge text-bg-info">Nouveau</span> apparaît
                pour les remises postérieures à votre dernière visite.</div>
        </div>
    </div>
</div>
<?php
// --- Mettre à jour la dernière visite APRÈS affichage ---
$_SESSION['eleve_submissions_last_visit'] = $nowStr;

require_once __DIR__ . '/../layout/footer.php';