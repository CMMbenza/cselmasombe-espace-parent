<?php
// /parent/eleve/quiz_detail.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
$quizId = (int)($_GET['id'] ?? 0);

if ($eid <= 0 || $quizId <= 0) {
    header('Location: ' . BASE_URL . '/parent/eleve/quizzes_liste.php');
    exit;
}

// 1) Vérifier l'accès au quiz via la classe de l'élève
$stmt = $pdo->prepare("
    SELECT 
        q.*,
        a.nom AS agent_nom, 
        a.prenom AS agent_prenom,
        p.libelle AS periode_libelle,
        c.description AS classe_desc
    FROM quiz q
    JOIN quiz_classe qc ON qc.quiz_id = q.id
    JOIN eleve e ON e.classe = qc.classe_id
    JOIN classe c ON c.id = e.classe
    LEFT JOIN agent a ON a.id = q.agent_id
    LEFT JOIN periodes p ON p.id = q.periode_id
    WHERE q.id = :qid AND e.id = :eid AND e.menage = :mid AND q.statut = 'approuvé'
    LIMIT 1
");
$stmt->execute([':qid' => $quizId, ':eid' => $eid, ':mid' => $mid]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header('Location: ' . BASE_URL . '/parent/eleve/quizzes_liste.php');
    exit;
}

// 2) Charger les pièces jointes du quiz
$stAtt = $pdo->prepare("SELECT * FROM quiz_attachment WHERE quiz_id = :qid");
$stAtt->execute([':qid' => $quizId]);
$attachments = $stAtt->fetchAll(PDO::FETCH_ASSOC);

// 3) Charger les questions du quiz
$stQ = $pdo->prepare("
    SELECT * FROM quiz_question 
    WHERE quiz_id = :qid 
    ORDER BY sort_order ASC, id ASC
");
$stQ->execute([':qid' => $quizId]);
$questions = $stQ->fetchAll(PDO::FETCH_ASSOC);

// 4) Charger les choix de réponses QCM & les mots-clés RQ
$questionIds = array_map(fn($q) => (int)$q['id'], $questions);
$choicesByQuestion = [];
$keywordsByQuestion = [];

if (!empty($questionIds)) {
    $in = implode(',', array_fill(0, count($questionIds), '?'));
    
    // Choix QCM
    $stc = $pdo->prepare("SELECT * FROM quiz_choice WHERE question_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $stc->execute($questionIds);
    while ($c = $stc->fetch(PDO::FETCH_ASSOC)) {
        $choicesByQuestion[(int)$c['question_id']][] = $c;
    }

    // Mots-clés pour RQ
    $stk = $pdo->prepare("SELECT * FROM quiz_question_keyword WHERE question_id IN ($in)");
    $stk->execute($questionIds);
    while ($k = $stk->fetch(PDO::FETCH_ASSOC)) {
        $keywordsByQuestion[(int)$k['question_id']][] = $k;
    }
}

// 5) Vérifier si l'élève a soumis des réponses pour ce quiz
$stSub = $pdo->prepare("
    SELECT * FROM quiz_submission 
    WHERE quiz_id = :qid AND eleve_id = :eid 
    LIMIT 1
");
$stSub->execute([':qid' => $quizId, ':eid' => $eid]);
$submission = $stSub->fetch(PDO::FETCH_ASSOC);

$studentAnswers = [];
if ($submission) {
    $stAns = $pdo->prepare("SELECT * FROM quiz_answer WHERE submission_id = :sid");
    $stAns->execute([':sid' => (int)$submission['id']]);
    while ($ans = $stAns->fetch(PDO::FETCH_ASSOC)) {
        $studentAnswers[(int)$ans['question_id']] = $ans;
    }
}
?>

<div class="container py-4">
    <!-- Navigation de retour -->
    <div class="mb-3">
        <a href="<?= BASE_URL ?>/eleve/quizzes_liste.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Retour aux archives
        </a>
    </div>

    <!-- En-tête du Quiz -->
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-primary text-white py-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <h1 class="h4 mb-0"><?= htmlspecialchars($quiz['titre'], ENT_QUOTES, 'UTF-8') ?></h1>
                <span class="badge bg-light text-primary fs-6">
                    <?= htmlspecialchars(strtoupper($quiz['type_quiz']), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($quiz['format'], ENT_QUOTES, 'UTF-8') ?>)
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Classe :</strong> <?= htmlspecialchars($quiz['classe_desc'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-1"><strong>Période :</strong> <?= htmlspecialchars($quiz['periode_libelle'] ?? 'Non définie', ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-0"><strong>Enseignant :</strong> <?= htmlspecialchars(trim(($quiz['agent_prenom'] ?? '') . ' ' . ($quiz['agent_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Année scolaire :</strong> <?= htmlspecialchars($quiz['anneeScolaire'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-1"><strong>Date limite :</strong> <?= !empty($quiz['date_limite']) ? date('d/m/Y', strtotime($quiz['date_limite'])) : 'Aucune' ?></p>
                    <!-- <p class="mb-0">
                        <strong>Statut de l'élève :</strong> 
                        <?php if ($submission): ?>
                            <span class="badge bg-success">Remis le <?= date('d/m/Y H:i', strtotime($submission['date_submitted'])) ?></span>
                            <?php if ($submission['statut'] === 'corrige'): ?>
                                <span class="badge bg-info">Note : <?= htmlspecialchars((string)$submission['note_totale'], ENT_QUOTES, 'UTF-8') ?> pts</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-secondary">Non effectué</span>
                        <?php endif; ?>
                    </p> -->
                </div>
            </div>

            <?php if (!empty($quiz['description'])): ?>
                <hr>
                <div class="mb-0">
                    <h6 class="fw-bold">Consignes / Description :</h6>
                    <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($quiz['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
            <?php endif; ?>

            <!-- Attachments du quiz -->
            <?php if (!empty($attachments)): ?>
                <hr>
                <h6 class="fw-bold"><i class="bi bi-paperclip me-1"></i> Pièces jointes de l'enseignant :</h6>
                <ul class="list-inline mb-0">
                    <?php foreach ($attachments as $att): ?>
                        <li class="list-inline-item">
                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($att['file_path'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-download me-1"></i> <?= htmlspecialchars($att['original_name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Questions et Réponses -->
    <h2 class="h5 mb-3"><i class="bi bi-patch-question me-2"></i>Contenu des questions (<?= count($questions) ?>)</h2>

    <?php if (empty($questions)): ?>
        <div class="alert alert-warning">Aucune question n'a été ajoutée à ce quiz pour le moment.</div>
    <?php else: ?>
        <?php foreach ($questions as $idx => $q): 
            $qid = (int)$q['id'];
            $choices  = $choicesByQuestion[$qid] ?? [];
            $keywords = $keywordsByQuestion[$qid] ?? [];
            $userAns  = $studentAnswers[$qid] ?? null;
        ?>
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Question <?= $idx + 1 ?> (<?= htmlspecialchars($q['TYPE'], ENT_QUOTES, 'UTF-8') ?>)</span>
                    <span class="badge bg-secondary"><?= htmlspecialchars((string)$q['points'], ENT_QUOTES, 'UTF-8') ?> Pts</span>
                </div>
                <div class="card-body">
                    <p class="fs-6 fw-semibold text-dark mb-3">
                        <?= nl2br(htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>

                    <!-- Type QCM -->
                    <?php if ($q['TYPE'] === 'QCM'): ?>
                        <div class="list-group mb-3">
                            <?php foreach ($choices as $c): 
                                $isCorrect = (bool)$c['is_correct'];
                                $isSelected = ($userAns && (int)$userAns['choice_id'] === (int)$c['id']);
                                
                                $class = '';
                                if ($isCorrect) {
                                    $class = 'list-group-item-success';
                                } elseif ($isSelected && !$isCorrect) {
                                    $class = 'list-group-item-danger';
                                }
                            ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center <?= $class ?>">
                                    <div>
                                        <?= htmlspecialchars($c['choice_text'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($isSelected): ?>
                                            <span class="badge bg-primary ms-2">Choisi par l'élève</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($isCorrect): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i> Bonne réponse</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <!-- Type RQ (Réponse Ouverte / Courte) -->
                    <?php else: ?>
                        <div class="p-3 bg-light rounded border mb-3">
                            <h6 class="fw-bold text-success"><i class="bi bi-check-circle me-1"></i> Réponse attendue par l'enseignant :</h6>
                            <p class="mb-2"><?= htmlspecialchars($q['expected_answer'] ?? 'Aucune réponse modèle fournie.', ENT_QUOTES, 'UTF-8') ?></p>

                            <?php if (!empty($keywords)): ?>
                                <div class="mt-2">
                                    <small class="text-muted d-block fw-bold mb-1">Mots-clés attendus :</small>
                                    <?php foreach ($keywords as $kw): ?>
                                        <span class="badge bg-secondary me-1">
                                            <?= htmlspecialchars($kw['keyword'], ENT_QUOTES, 'UTF-8') ?> (poids: <?= htmlspecialchars((string)$kw['poids'], ENT_QUOTES, 'UTF-8') ?>)
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($userAns): ?>
                            <div class="p-3 bg-white border rounded">
                                <h6 class="fw-bold text-primary"><i class="bi bi-person-lines-fill me-1"></i> Réponse soumise par l'élève :</h6>
                                <p class="mb-0 text-dark"><?= nl2br(htmlspecialchars($userAns['reponse_text'] ?? 'Aucun texte fourni.', ENT_QUOTES, 'UTF-8')) ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Affichage des points obtenus / Feedback s'il y a eu correction -->
                    <?php if ($userAns && $userAns['points_obtenus'] !== null): ?>
                        <div class="mt-3 p-2 bg-warning-subtle border border-warning rounded small">
                            <strong>Note sur la question :</strong> <?= htmlspecialchars((string)$userAns['points_obtenus'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string)$q['points'], ENT_QUOTES, 'UTF-8') ?> pts
                            <?php if (!empty($userAns['feedback'])): ?>
                                <br><strong>Remarque/Feedback :</strong> <?= htmlspecialchars($userAns['feedback'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>