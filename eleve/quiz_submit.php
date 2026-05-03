<?php
// /parent/eleve/quiz_submit.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
$qid = (int)($_GET['id'] ?? 0);

if ($eid <= 0 || $qid <= 0) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Vérification que l'élève appartient au ménage
$stmt = $pdo->prepare("SELECT id, classe FROM eleve WHERE id=:id AND menage=:mid LIMIT 1");
$stmt->execute([':id'=>$eid, ':mid'=>$mid]);
$el = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$el) {
    set_current_eleve(0);
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Charger le quiz
$stmt = $pdo->prepare("
    SELECT q.*
    FROM quiz q
    JOIN quiz_classe qc ON qc.quiz_id = q.id
    WHERE q.id = :id
      AND qc.classe_id = :cid
      AND q.statut = 'approuvé'
    LIMIT 1
");
$stmt->execute([
    ':id'  => $qid,
    ':cid' => $el['classe']
]);

$quiz = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quiz) {
    header('Location: ' . BASE_URL . '/eleve/quizzes.php');
    exit;
}

// Charger les pièces jointes du professeur
$attachments = [];
$attStmt = $pdo->prepare("
    SELECT id, original_name, mime_type, file_path, file_size, uploaded_at
    FROM quiz_attachment
    WHERE quiz_id = :qid
    ORDER BY id
");
$attStmt->execute([':qid' => $qid]);
$attachments = $attStmt->fetchAll(PDO::FETCH_ASSOC);

// Vérifier que la période existe (FK)
$periodeId = (int)($quiz['periode_id'] ?? 0);
if ($periodeId <= 0) {
    $error = "Ce quiz n'est pas lié à une période valide. Contactez l'administration.";
}

// Charger questions + mots-clés
$questions = [];
$keywordsByQ = [];
if (in_array($quiz['format'], ['QCM','RQ'], true)) {
    $qStmt = $pdo->prepare("SELECT * FROM quiz_question WHERE quiz_id=:qid ORDER BY sort_order,id");
    $qStmt->execute([':qid'=>$qid]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $qids = array_map(fn($q)=> (int)$q['id'], $questions);
    if ($qids) {
        $in = implode(',', array_fill(0, count($qids), '?'));
        $kStmt = $pdo->prepare("SELECT question_id, keyword FROM quiz_question_keyword WHERE question_id IN ($in)");
        $kStmt->execute($qids);
        $keywordsRaw = $kStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($keywordsRaw as $kw){
            $keywordsByQ[(int)$kw['question_id']][] = $kw['keyword'];
        }
    }
}

// Charger soumission existante
$submission = null;
$stmt = $pdo->prepare("SELECT * FROM quiz_submission WHERE quiz_id=:qid AND eleve_id=:eid LIMIT 1");
$stmt->execute([':qid'=>$qid, ':eid'=>$eid]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
$submissionId = $submission['id'] ?? null;
$locked = isset($submission['statut']) && $submission['statut'] === 'corrige';

// Réponses existantes
$answers = [];
if ($submissionId) {
    $aStmt = $pdo->prepare("SELECT question_id, reponse_text, choice_id, points_obtenus FROM quiz_answer WHERE submission_id=:sid");
    $aStmt->execute([':sid'=>$submissionId]);
    while($a = $aStmt->fetch(PDO::FETCH_ASSOC)) {
        $answers[(int)$a['question_id']] = $a;
    }
}

// POST
$ok = $ok ?? '';
$error = $error ?? '';
$totalScore = 0.0;

if ($_SERVER['REQUEST_METHOD']==='POST' && !$error) {
    if ($locked) {
        $error = "Votre soumission a déjà été corrigée. Vous ne pouvez plus la modifier.";
    } else {
        try {
            $pdo->beginTransaction();

            // Créer ou mettre à jour la soumission
            if (!$submissionId) {
                $ins = $pdo->prepare("INSERT INTO quiz_submission (quiz_id, eleve_id, periode_id, statut) VALUES (:qid,:eid,:pid,'remis')");
                $ins->execute([':qid'=>$qid, ':eid'=>$eid, ':pid'=>$periodeId]);
                $submissionId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE quiz_submission SET statut='remis', periode_id=:pid WHERE id=:sid")
                    ->execute([':sid'=>$submissionId, ':pid'=>$periodeId]);
                $pdo->prepare("DELETE FROM quiz_answer WHERE submission_id=:sid")->execute([':sid'=>$submissionId]);
            }
            
            foreach($questions as $q) {
                $qidQ = (int)$q['id'];
                $keywords = $keywordsByQ[$qidQ] ?? [];

                if ($q['TYPE'] === 'QCM') {

                    $selectedChoices = $_POST['qcm_'.$qidQ] ?? [];

                    if (!empty($selectedChoices) && is_array($selectedChoices)) {

                        $selectedChoices = array_map('intval', $selectedChoices);

                        // 🔹 récupérer TOUTES les réponses
                        $stmt = $pdo->prepare("SELECT id, is_correct FROM quiz_choice WHERE question_id=:qid");
                        $stmt->execute([':qid'=>$qidQ]);
                        $allChoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $allIds = array_column($allChoices, 'id');

                        // 🔹 récupérer bonnes réponses
                        $correctIds = [];
                        foreach ($allChoices as $c) {
                            if ($c['is_correct'] == 1) {
                                $correctIds[] = (int)$c['id'];
                            }
                        }

                        $totalCorrect = count($correctIds);
                        $totalChoices = count($allIds);

                        $points = 0;

                        // 🚨 CAS TRICHE
                        if (
                            count($selectedChoices) > $totalCorrect // coche trop
                            || count($selectedChoices) == $totalChoices // coche tout
                        ) {
                            $points = 0;

                            // (optionnel) message debug/log
                            // echo "Triche détectée";
                        }

                        // ✅ CAS NORMAL (points partiels)
                        else {

                            if ($totalCorrect > 0) {

                                $pointsParBonne = (float)$q['points'] / $totalCorrect;

                                foreach ($selectedChoices as $id) {
                                    if (in_array($id, $correctIds)) {
                                        $points += $pointsParBonne;
                                    }
                                }

                                $points = round($points, 2);
                            }
                        }

                        // 💾 sauvegarde
                        $pdo->prepare("
                            INSERT INTO quiz_answer (submission_id, question_id, choice_id, points_obtenus)
                            VALUES (:sid,:qid,:cid,:pts)
                        ")->execute([
                            ':sid'=>$submissionId,
                            ':qid'=>$qidQ,
                            ':cid'=>implode(',', $selectedChoices),
                            ':pts'=>$points
                        ]);

                        $totalScore += $points;
                    }
                } else { // RQ
                    $txt = trim((string)($_POST['rq_'.$qidQ] ?? ''));
                    $score = 0.0;
                    if ($txt!=='') {
                        // Calcul automatique par mots-clés
                        $matches = 0;
                        foreach($keywords as $kw){
                            if (stripos($txt, $kw)!==false) $matches++;
                        }
                        $score = count($keywords)>0 ? round(($matches/count($keywords))* (float)$q['points'],2) : 0.0;

                        $pdo->prepare("INSERT INTO quiz_answer (submission_id, question_id, reponse_text, points_obtenus) VALUES (:sid,:qid,:txt,:pts)")
                            ->execute([':sid'=>$submissionId, ':qid'=>$qidQ, ':txt'=>$txt, ':pts'=>$score]);
                        $totalScore += $score;
                    }
                }
            }

            $pdo->prepare("UPDATE quiz_submission SET note_totale=:nt, statut='corrige' WHERE id=:sid")
                ->execute([':nt'=>$totalScore, ':sid'=>$submissionId]);

            $pdo->commit();
            $ok = "Le travail a été réalisé avec succès.. Note obtenue : $totalScore / ".array_sum(array_map(fn($q)=> (float)$q['points'],$questions));
            $locked = true; // bloquer le formulaire après soumission
        } catch(Throwable $e){
            $pdo->rollBack();
            $error = "Échec de la soumission : ".$e->getMessage();
        }
    }
}
?>

<style>
/* Placeholder visible si vide */
.rq-input:empty::before {
    content: attr(data-placeholder);
    color: #6c757d;
    pointer-events: none;
}
</style>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<div class="container my-4">
    <div class="col-12 card mb-3">
        <div class="card-body">
            <h1 class="h5 mb-3 text-uppercase">Cours : <?= e($quiz['titre']) ?></h1>
            <h4>Consigne du professeur : </h4>
            <p class="text-danger"><?= e($quiz['description']) ?></p>
        </div>
    </div>

    <?php if (!empty($attachments)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="mb-3">📎 Fichiers joints par le professeur</h5>

            <?php foreach ($attachments as $file): 
            $path = e($file['file_path']);
            $type = $file['mime_type'];
        ?>

            <div class="mb-4">

                <p class="fw-bold mb-2">
                    <?= e($file['original_name']) ?>
                </p>

                <?php if (str_starts_with($type, 'image/')): ?>

                <!-- PREVIEW IMAGE -->
                <img src="<?= $path ?>" class="img-fluid rounded shadow-sm"
                    style="max-height:400px; object-fit:contain;">

                <?php elseif ($type === 'application/pdf'): ?>

                <!-- PREVIEW PDF -->
                <iframe src="<?= $path ?>" width="100%" height="500px" style="border-radius:8px;">
                </iframe>

                <?php else: ?>

                <!-- AUTRES FICHIERS -->
                <a href="<?= $path ?>" class="btn btn-primary" target="_blank">
                    📥 Télécharger
                </a>

                <?php endif; ?>

            </div>

            <?php endforeach; ?>

        </div>
    </div>
    <?php endif; ?>


    <?php if($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if($ok): ?>
    <div class="alert alert-success"><?= e($ok) ?></div>
    <a href="<?= BASE_URL ?>/eleve/submission_view.php?id=<?= $submissionId ?>" class="btn btn-primary mb-3">Voir la
        correction</a>
    <a href="<?= BASE_URL ?>/eleve/quizzes.php" class="btn btn-dark mb-3">Voir d'autre quiz</a>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card shadow-sm">
        <div class="card-body">

            <ol>
                <?php foreach($questions as $q): ?>
                <li class="mb-3">
                    <div class="mb-1">
                        <strong>(<?= e($q['TYPE']) ?>)</strong> <?= nl2br(e($q['question_text'])) ?>
                    </div>

                    <?php if (!empty($q['expected_answer'])): ?>
                    <div class="d-none alert alert-light small mb-1">
                        <strong>Réponse attendue :</strong> <?= nl2br(e($q['expected_answer'])) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($keywordsByQ[$q['id']] ?? [])): ?>
                    <div class="d-none alert alert-info small mb-2">
                        <strong>Mots-clés :</strong> <?= e(implode(', ',$keywordsByQ[$q['id']])) ?>
                    </div>
                    <?php endif; ?>

                    <?php if($q['TYPE']==='QCM'):
                        $cStmt = $pdo->prepare("SELECT * FROM quiz_choice WHERE question_id=:qid ORDER BY sort_order,id");
                        $cStmt->execute([':qid'=>$q['id']]);
                        $choices = $cStmt->fetchAll(PDO::FETCH_ASSOC);
                        $savedChoice = $answers[$q['id']]['choice_id'] ?? null;
                    ?>

                    <?php foreach($choices as $c): ?>
                    <?php 
                        $savedChoices = [];
                        if (isset($answers[$q['id']]['choice_id'])) {
                            $savedChoices = explode(',', $answers[$q['id']]['choice_id']);
                        }
                        ?>
                    <div class="form-check">
                        <input type="checkbox" name="qcm_<?= $q['id'] ?>[]" value="<?= $c['id'] ?>"
                            class="form-check-input" <?= $locked?'disabled':'' ?>
                            <?= in_array($c['id'], $savedChoices) ? 'checked' : '' ?> <label
                            class="form-check-label"><?= e($c['choice_text']) ?></label>
                    </div>
                    <?php endforeach; ?>
                    <?php else: // RQ ?>
                    <?php  
                        $savedTextRaw = $answers[$q['id']]['reponse_text'] ?? ''; 
                        $savedText = trim($savedTextRaw); // supprime espaces au début/fin
                        $keywords = $keywordsByQ[$q['id']] ?? [];
                        $keywordsJson = json_encode($keywords);
                        ?>
                    <div class="rq-editor-wrapper" style="position:relative;">
                        <div class="highlighted-content"></div>
                        <div contenteditable="<?= $locked ? 'false' : 'true' ?>" class="form-control rq-input"
                            data-keywords='<?= $keywordsJson ?>' data-placeholder="Tapez votre réponse ici..."
                            style="color: #6c757d;">
                            <?= $savedText !== '' ? e($savedText) : ' ' ?></div>
                        <input type="hidden" name="rq_<?= $q['id'] ?>" class="rq-hidden"
                            value="<?= $savedText !== '' ? e($savedText) : '' ?>">
                    </div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>

            <?php if($quiz['format']==='PJ'): ?>
            <div class="mb-3">
                <label class="form-label">Joindre un fichier (obligatoire)</label>
                <input type="file" name="files[]" class="form-control" multiple accept=".pdf,image/jpeg,image/jpg"
                    <?= $locked?'disabled':'' ?>>
            </div>
            <?php endif; ?>

        </div>
        <div class="card-footer d-flex gap-2">
            <button class="btn btn-primary" <?= $locked?'disabled':'' ?>>Envoyer mes réponses</button>
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/eleve/quizzes.php">Annuler</a>
        </div>
    </form>
</div>

<!-- Modal Avertissement -->
<div class="modal fade" id="warningModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">⚠️ Avertissement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p>
                    ⚠️ Attention : évitez de cocher toutes les propositions d’une question.<br><br>
                    Si vous le faites, la question sera annulée et vous obtiendrez
                    <strong>0 point</strong>, même si certaines réponses sont correctes.
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                    J’ai compris
                </button>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = new bootstrap.Modal(document.getElementById('warningModal'));
    modal.show();

    const editors = document.querySelectorAll('.rq-editor-wrapper');
    editors.forEach(wrapper => {
        const inputDiv = wrapper.querySelector('.rq-input');
        const hiddenInput = wrapper.querySelector('.rq-hidden');
        const keywords = JSON.parse(inputDiv.dataset.keywords);

        function highlightKeywords() {
            let text = inputDiv.textContent || '';
            hiddenInput.value = text; // conserver la valeur pour POST

            let html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            // Surbrillance mots-clés
            keywords.forEach(kw => {
                const re = new RegExp(`(${kw.replace(/[-/\\^$*+?.()|[\]{}]/g,'\\$&')})`, 'gi');
                html = html.replace(re, '<mark>$1</mark>');
            });

            inputDiv.innerHTML = html;

            // Placer le curseur à la fin après mise à jour
            const range = document.createRange();
            const sel = window.getSelection();
            range.selectNodeContents(inputDiv);
            range.collapse(false);
            sel.removeAllRanges();
            sel.addRange(range);
        }

        inputDiv.addEventListener('input', highlightKeywords);
        highlightKeywords();
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>