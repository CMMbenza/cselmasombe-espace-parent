<?php
// /parent/eleve/submission_view.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
$sid = (int)($_GET['id'] ?? 0);

if ($eid <= 0 || $sid <= 0) { header('Location: '.BASE_URL.'/dashboard.php'); exit; }

// Charger la soumission et s'assurer qu’elle est bien à l’élève du ménage
$sql = "SELECT
          s.id AS submission_id,
          s.quiz_id,
          s.eleve_id,
          s.statut,
          s.note_totale,
          s.date_submitted,

          q.titre,
          q.type_quiz,
          q.format,

          c.id AS classe_id,
          c.description AS classe_desc,
          cy.description AS cycle_desc,

          e.nom,
          e.postnom,
          e.prenom,
          e.menage

        FROM quiz_submission s
        JOIN quiz q           ON q.id = s.quiz_id
        JOIN quiz_classe qc   ON qc.quiz_id = q.id
        JOIN classe c         ON c.id = qc.classe_id
        LEFT JOIN cycle cy    ON cy.id = c.cycle
        JOIN eleve e          ON e.id = s.eleve_id

        WHERE s.id = :sid
        LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([':sid'=>$sid]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub || (int)$sub['eleve_id'] !== $eid || (int)$sub['menage'] !== $mid) {
  header('Location: '.BASE_URL.'/eleve/my_submissions.php'); exit;
}

$format = (string)$sub['format'];

// Questions
$qStmt = $pdo->prepare("SELECT id, TYPE, question_text, points, sort_order
                        FROM quiz_question
                        WHERE quiz_id=:qid
                        ORDER BY sort_order, id");
$qStmt->execute([':qid'=>(int)$sub['quiz_id']]);
$questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

// Réponses
$answers = [];
$aStmt = $pdo->prepare("SELECT question_id, reponse_text, choice_id, points_obtenus
                        FROM quiz_answer WHERE submission_id=:sid");
$aStmt->execute([':sid'=>$sid]);
while ($a = $aStmt->fetch(PDO::FETCH_ASSOC)) {
  $answers[(int)$a['question_id']] = [
    'reponse_text'   => $a['reponse_text'] ?? '',
    'choice_id' => $a['choice_id'] ?? null,
    'points_obtenus' => $a['points_obtenus'] !== null ? (float)$a['points_obtenus'] : null,
  ];
}

// Choix QCM si besoin
$choicesByQ = [];
if ($questions && $format==='QCM') {
  $qids = [];
  foreach ($questions as $q) if ($q['TYPE']==='QCM') $qids[] = (int)$q['id'];
  if ($qids) {
    $in   = implode(',', array_fill(0, count($qids), '?'));
    $cQry = $pdo->prepare("SELECT id, question_id, choice_text, is_correct
                           FROM quiz_choice
                           WHERE question_id IN ($in)
                           ORDER BY sort_order, id");
    $cQry->execute($qids);
    while ($c = $cQry->fetch(PDO::FETCH_ASSOC)) {
      $choicesByQ[(int)$c['question_id']][] = [
        'id' => (int)$c['id'],
        'choice_text' => $c['choice_text'],
        'is_correct'  => (int)$c['is_correct'] === 1,
      ];
    }
  }
}

// Pièces jointes de la soumission
$fileStmt = $pdo->prepare("SELECT id, original_name, mime_type, file_path, file_size, uploaded_at
                           FROM quiz_submission_attachment
                           WHERE submission_id=:sid ORDER BY id");
$fileStmt->execute([':sid'=>$sid]);
$files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

// Note à afficher
$noteDisplay = $sub['note_totale'] !== null
  ? number_format((float)$sub['note_totale'],2,',',' ')
  : '—';
?>
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">Détail de la soumission #<?= (int)$sub['submission_id'] ?></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/eleve/my_submissions.php">&larr; Retour</a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <div class="small text-muted">Quiz</div>
                    <div class="fw-semibold"><?= e($sub['titre']) ?></div>
                    <div class="text-muted small">
                        <?= e($sub['type_quiz']) ?> • Format :
                        <span class="badge text-bg-secondary"><?= e($sub['format']) ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Classe / Cycle</div>
                    <div class="fw-semibold">
                        <?= e($sub['classe_desc']) ?><?= !empty($sub['cycle_desc']) ? ' — '.e($sub['cycle_desc']) : '' ?>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Statut</div>
                    <?php $badge = ($sub['statut']==='corrige')?'success':'warning'; ?>
                    <div class="fw-semibold"><span class="badge text-bg-<?= $badge ?>"><?= e($sub['statut']) ?></span>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Note</div>
                    <div class="fw-semibold"><?= $noteDisplay ?></div>
                </div>
            </div>
            <div class="mt-2 small text-muted">Remis le : <?= e($sub['date_submitted']) ?></div>
        </div>
    </div>

    <?php if ($format === 'PJ'): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">Pièces jointes remises</h2>
            <?php if (!$files): ?>
            <div class="text-muted">Aucun fichier remis.</div>
            <?php else: ?>
            <ul class="mb-0">
                <?php foreach ($files as $f): ?>
                <li>
                    <a href="<?= e($f['file_path']) ?>" target="_blank" rel="noopener">
                        <?= e($f['original_name']) ?>
                    </a>
                    <span class="text-muted small">
                        (<?= e($f['mime_type']) ?>, <?= (int)$f['file_size'] ?> o)
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">Détail des réponses</h2>
            <?php if (!$questions): ?>
            <div class="text-muted">Ce quiz ne contient pas de questions.</div>
            <?php else: ?>
            <ol class="mb-0">
                <?php foreach ($questions as $q):
              $qid  = (int)$q['id'];
              $type = (string)$q['TYPE'];
              $ans  = $answers[$qid] ?? null;
              $pts  = $ans && $ans['points_obtenus'] !== null ? (float)$ans['points_obtenus'] : null;
            ?>
                <li class="mb-3">
                    <div class="mb-1">
                        <strong>(<?= e($type) ?>)</strong>
                        <?= nl2br(e($q['question_text'])) ?>
                        <span class="text-muted small">— Max : <?= e((string)$q['points']) ?> pts</span>
                        <?php if ($pts !== null): ?>
                        <span class="badge text-bg-secondary ms-2">Obtenus :
                            <?= e(number_format($pts,2,',',' ')) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($type === 'QCM'):
    $opts = $choicesByQ[$qid] ?? [];

    $chosenRaw = $ans['choice_id'] ?? '';
    $chosenIds = [];

    if (!empty($chosenRaw)) {
        $chosenIds = array_map('intval', explode(',', (string)$chosenRaw));
    }
?>

                    <?php if (!$opts): ?>
                    <div class="text-muted">Aucun choix disponible.</div>
                    <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($opts as $opt):
                        $isChosen = in_array((int)$opt['id'], $chosenIds);
                        $isRight  = $opt['is_correct'];
                    ?>
                        <li>
                            <span class="<?= $isRight ? 'text-success' : 'text-muted' ?>">
                                <?= nl2br(e($opt['choice_text'])) ?>
                            </span>

                            <?php if ($isRight): ?>
                            <span class="badge text-bg-success ms-1">Correct</span>
                            <?php endif; ?>

                            <?php if ($isChosen): ?>
                            <span class="badge text-bg-primary ms-1">Votre choix</span>
                            <?php endif; ?>

                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php elseif ($type === 'RQ'): ?>
                    <div class="mt-2">
                        <div class="small text-muted">Votre réponse :</div>
                        <div class="border rounded p-2 bg-light" style="white-space:pre-wrap;">
                            <?php
                        $txt = $ans['reponse_text'] ?? '';
                        echo $txt !== '' ? nl2br(e($txt)) : '<em>Aucune réponse saisie.</em>';
                      ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($files): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 mb-2">Pièces jointes (compléments)</h2>
            <ul class="mb-0">
                <?php foreach ($files as $f): ?>
                <li>
                    <a href="<?= e($f['file_path']) ?>" target="_blank" rel="noopener">
                        <?= e($f['original_name']) ?>
                    </a>
                    <span class="text-muted small">
                        (<?= e($f['mime_type']) ?>, <?= (int)$f['file_size'] ?> o)
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="mb-4">
        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/eleve/my_submissions.php">&larr; Retour</a>
        <a class="btn btn-primary ms-2" href="<?= BASE_URL ?>/eleve/quizzes.php">Voir les quiz</a>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>