<?php
// /parent/eleve/quiz_view.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
$qid = (int)($_GET['id'] ?? 0);

if ($eid <= 0 || $qid <= 0) {
  header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

// Vérifie que l'élève appartient au ménage
$stmt = $pdo->prepare("
SELECT 
    e.id, 
    e.classe, 
    c.description AS classe_desc, 
    cy.description AS cycle_description
FROM eleve e
JOIN classe c ON c.id = e.classe
JOIN cycle cy ON cy.id = c.cycle
WHERE e.id = :id 
  AND e.menage = :mid
LIMIT 1;
");
$stmt->execute([':id' => $eid, ':mid' => $mid]);
$el = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$el) {
  set_current_eleve(0);
  header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

// Charge le quiz (approuvé + même classe)
$stmt = $pdo->prepare("
  SELECT
      q.id,
      q.agent_id,
      q.type_quiz,
      q.format,
      q.titre,
      q.description,
      q.date_limite,
      q.statut,
      q.created_at
  FROM quiz q
  JOIN quiz_classe qc ON qc.quiz_id = q.id
  WHERE q.id = :id
    AND qc.classe_id = :cid
    AND q.statut = 'approuvé'
  LIMIT 1
");
$stmt->execute([
    ':id'  => $qid,
    ':cid' => (int)$el['classe']
]);
$q = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$q) {
  header('Location: ' . BASE_URL . '/eleve/quizzes.php');
  exit;
}

// Pièces jointes du quiz
$atts = $pdo->prepare("
  SELECT id, original_name, mime_type, file_path, file_size, uploaded_at
  FROM quiz_attachment
  WHERE quiz_id = :qid
  ORDER BY id
");
$atts->execute([':qid' => $qid]);
$files = $atts->fetchAll(PDO::FETCH_ASSOC);

// Questions (aperçu)
$qs = [];
if (in_array($q['format'], ['QCM','RQ'], true)) {
  $qsStmt = $pdo->prepare("
    SELECT id, TYPE, question_text, points, sort_order
    FROM quiz_question
    WHERE quiz_id = :qid
    ORDER BY sort_order, id
  ");
  $qsStmt->execute([':qid' => $qid]);
  $qs = $qsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Statut / note de la soumission (si existe)
$sub = null;
$noteDisplay = null;
$subStmt = $pdo->prepare("SELECT id, statut, note_totale, date_submitted FROM quiz_submission WHERE quiz_id=:qid AND eleve_id=:eid LIMIT 1");
$subStmt->execute([':qid'=>$qid, ':eid'=>$eid]);
$sub = $subStmt->fetch(PDO::FETCH_ASSOC);

if ($sub) {
  if ($sub['note_totale'] !== null) {
    $noteDisplay = number_format((float)$sub['note_totale'], 2, ',', ' ');
  } else {
    // Pour RQ/PJ en attente on laisse vide ; si tu veux, on peut calculer somme points_obtenus (RQ partiellement noté), sinon garde NULL.
    $noteDisplay = null;
  }
}
$now = time();
$isExpired = false;

if (!empty($q['date_limite'])) {
    $isExpired = strtotime($q['date_limite']) < $now;
}
$alreadySubmitted = ($sub !== null);
?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">Quiz — <?= e($q['titre']) ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/eleve/quizzes.php">&larr; Retour pour voir les
            questions</a>
    </div>

    <?php if ($sub): ?>
    <div class="alert alert-info py-2">
        Statut de votre soumission : <strong><?= e($sub['statut']) ?></strong>
        <?php if ($noteDisplay !== null): ?>
        — Votre note : <strong><?= $noteDisplay ?></strong>
        <?php elseif ($q['format']==='RQ'): ?>
        — En attente de correction par le professeur.
        <?php elseif ($q['format']==='PJ'): ?>
        — En attente de traitement.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <div class="small text-muted">Classe</div>
                    <div class="fw-semibold"><?= e($el['classe_desc']) ?> <?= e($el['cycle_description']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Type</div>
                    <div class="fw-semibold"><?= e($q['type_quiz']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Format</div>
                    <div class="fw-semibold"><?= e($q['format']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Date limite</div>
                    <div class="fw-semibold text-danger"><?= e((string)$q['date_limite'] ?: '—') ?></div>
                </div>
            </div>

            <?php if (!empty($q['description'])): ?>
            <hr>
            <div><?= nl2br(e($q['description'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($files): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">Pièces jointes</h2>
            <ul class="mb-0">
                <?php foreach ($files as $f): ?>
                <li>
                    <a href="<?= e($f['file_path']) ?>" target="_blank" rel="noopener">
                        <?= e($f['original_name']) ?> (<?= e($f['mime_type']) ?>, <?= (int)$f['file_size'] ?> o)
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($qs): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">Aperçu des questions</h2>
            <ol class="mb-0">
                <?php foreach ($qs as $i => $qq): ?>
                <li class="mb-2">
                    <div>
                        <strong>(<?= e($qq['TYPE']) ?>)</strong>
                        <?= nl2br(e($qq['question_text'])) ?>
                        <span class="text-muted small">— <?= e((string)$qq['points']) ?> pts</span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <div class="mt-3 d-flex gap-2">

        <?php if ($isExpired): ?>

        <button class="btn btn-danger" disabled>
            ⛔ Date limite dépassée
        </button>

        <?php elseif ($alreadySubmitted): ?>

        <button class="btn btn-success" disabled>
            ✔ Déjà remis
        </button>

        <?php else: ?>

        <a class="btn btn-primary" href="<?= BASE_URL ?>/eleve/quiz_submit.php?id=<?= (int)$q['id'] ?>">
            Travailler / Remettre
        </a>

        <?php endif; ?>

        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/eleve/quizzes.php">
            ← Retour
        </a>

    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>