<?php
// /eleve/mes_resultats.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
// Assurez-vous d'avoir une fonction de vérification de session élève
require_eleve();

require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$eleve_id = (int)$_SESSION['eleve']['id'];
$classe_id = (int)$_SESSION['eleve']['classe_id'];

/*
|--------------------------------------------------------------------------
| 1. Récupération du Palmarès Trimestriel (+ Calcul du Rang)
|--------------------------------------------------------------------------
*/
$stmtPalmares = $pdo->prepare("
    SELECT 
        pt.*,
        (
            SELECT COUNT(*) + 1
            FROM palmares_trimestre p_rank
            WHERE p_rank.classe_id = pt.classe_id
              AND p_rank.trimestre = pt.trimestre
              AND p_rank.percent > pt.percent
        ) AS place
    FROM palmares_trimestre pt
    WHERE pt.eleve_id = :eleve_id
    ORDER BY pt.id DESC
");
$stmtPalmares->execute([':eleve_id' => $eleve_id]);
$palmaresList = $stmtPalmares->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 2. Récupération des Soumissions de Quiz / Évaluations
|--------------------------------------------------------------------------
*/
$stmtSubmissions = $pdo->prepare("
    SELECT 
        qs.id AS submission_id,
        qs.date_submitted,
        qs.note_totale,
        qs.statut AS statut_soumission,
        q.titre AS quiz_titre,
        q.type_quiz,
        q.format,
        q.description,
        p.nom AS periode_nom,
        (
            SELECT SUM(points) 
            FROM quiz_question 
            WHERE quiz_id = q.id
        ) AS total_points_quiz
    FROM quiz_submission qs
    JOIN quiz q ON q.id = qs.quiz_id
    LEFT JOIN periodes p ON p.id = qs.periode_id
    WHERE qs.eleve_id = :eleve_id
    ORDER BY qs.date_submitted DESC
");
$stmtSubmissions->execute([':eleve_id' => $eleve_id]);
$submissions = $stmtSubmissions->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Fonctions d'affichage helpers
|--------------------------------------------------------------------------
*/
function formatRang(?int $place): string
{
    if ($place === null || $place <= 0) {
        return '<span class="text-muted">—</span>';
    }
    return match ($place) {
        1 => '<span class="badge bg-warning text-dark fs-6"><i class="bi bi-trophy-fill me-1"></i>1<sup>er</sup></span>',
        2 => '<span class="badge bg-secondary fs-6">2<sup>ème</sup></span>',
        3 => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fs-6">3<sup>ème</sup></span>',
        default => '<span class="badge bg-light text-dark border fs-6">' . $place . '<sup>ème</sup></span>',
    };
}

function badgeStatut(string $statut): string
{
    return match ($statut) {
        'corrige' => '<span class="badge text-bg-success">Corrigé</span>',
        'remis' => '<span class="badge text-bg-warning">En attente de correction</span>',
        default => '<span class="badge text-bg-secondary">' . e($statut) . '</span>',
    };
}
?>

<div class="container py-4">

    <div class="mb-4">
        <h1 class="h4 mb-1">Mes Résultats & Évaluations</h1>
        <p class="text-muted small">Consultez vos bulletins trimestriels et les détails de vos travaux remis.</p>
    </div>

    <!-- ONGLETS DE NAVIGATION -->
    <ul class="nav nav-tabs mb-4" id="resultTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="palmares-tab" data-bs-toggle="tab" data-bs-target="#palmares-pane" type="button" role="tab">
                <i class="bi bi-journal-bookmark me-1"></i> Palmarès Trimestriel
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="quiz-tab" data-bs-toggle="tab" data-bs-target="#quiz-pane" type="button" role="tab">
                <i class="bi bi-check2-square me-1"></i> Évaluations & Quiz (<?= count($submissions) ?>)
            </button>
        </li>
    </ul>

    <div class="tab-content" id="resultTabsContent">

        <!-- SECTION 1 : PALMARÈS TRIMESTRIEL -->
        <div class="tab-pane fade show active" id="palmares-pane" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Trimestre</th>
                                <th>Langues</th>
                                <th>Mathématiques</th>
                                <th>Culture Gén.</th>
                                <th>Total</th>
                                <th>Pourcentage</th>
                                <th class="text-center">Rang</th>
                                <th>Observation</th>
                                <th>Autorisation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($palmaresList)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <em>Aucun palmarès disponible pour le moment.</em>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($palmaresList as $p): ?>
                                    <?php if ((int)$p['autorise'] === 1): ?>
                                        <tr>
                                            <td><span class="fw-bold"><?= e($p['trimestre']) ?></span></td>
                                            <td><?= number_format((float)$p['lang'], 2, ',', ' ') ?> / <?= number_format((float)$p['max_lang'], 2, ',', ' ') ?></td>
                                            <td><?= number_format((float)$p['math'], 2, ',', ' ') ?> / <?= number_format((float)$p['max_math'], 2, ',', ' ') ?></td>
                                            <td><?= number_format((float)$p['cult'], 2, ',', ' ') ?> / <?= number_format((float)$p['max_cult'], 2, ',', ' ') ?></td>
                                            <td>
                                                <strong>
                                                    <?= number_format((float)$p['total'], 2, ',', ' ') ?> / <?= number_format((float)$p['max_total'], 2, ',', ' ') ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-dark">
                                                    <?= number_format((float)$p['percent'], 2, ',', ' ') ?> %
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <?= formatRang((int)$p['place']) ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($p['obs'])): ?>
                                                    <span class="badge text-bg-info"><?= e($p['obs']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-success">Autorisé</span>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td><span class="fw-bold"><?= e($p['trimestre']) ?></span></td>
                                            <td colspan="8">
                                                <div class="alert alert-danger mb-0 py-1 px-2 small">
                                                    Accès aux points bloqué. Veuillez régulariser votre situation administrative.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SECTION 2 : ÉVALUATIONS & QUIZ -->
        <div class="tab-pane fade" id="quiz-pane" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Titre du Travail</th>
                                <th>Type</th>
                                <th>Période</th>
                                <th>Date de remise</th>
                                <th>Statut</th>
                                <th>Note obtenue</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        <em>Aucune évaluation soumise pour le moment.</em>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $sub): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e($sub['quiz_titre']) ?></div>
                                            <small class="text-muted"><?= e($sub['format']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= e($sub['type_quiz']) ?>
                                            </span>
                                        </td>
                                        <td><?= e($sub['periode_nom'] ?? '—') ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($sub['date_submitted'])) ?></td>
                                        <td><?= badgeStatut($sub['statut_soumission']) ?></td>
                                        <td>
                                            <?php if ($sub['statut_soumission'] === 'corrige' && $sub['note_totale'] !== null): ?>
                                                <span class="fw-bold text-success">
                                                    <?= number_format((float)$sub['note_totale'], 2, ',', ' ') ?>
                                                </span>
                                                <small class="text-muted">/ <?= number_format((float)($sub['total_points_quiz'] ?? 0), 2, ',', ' ') ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#modalSub<?= (int)$sub['submission_id'] ?>">
                                                Voir détails
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- MODALES DE DÉTAILS POUR CHAQUE QUIZ -->
<?php foreach ($submissions as $sub): ?>
    <?php
    // Récupération des réponses et des corrections détaillées
    $stmtAnswers = $pdo->prepare("
        SELECT 
            qa.*,
            qq.question_text,
            qq.points AS max_points,
            qq.TYPE AS question_type,
            qc.texte_choix AS choix_libelle
        FROM quiz_answer qa
        JOIN quiz_question qq ON qq.id = qa.question_id
        LEFT JOIN quiz_choice qc ON qc.id = qa.choice_id
        WHERE qa.submission_id = :submission_id
        ORDER BY qq.sort_order ASC
    ");
    $stmtAnswers->execute([':submission_id' => $sub['submission_id']]);
    $answers = $stmtAnswers->fetchAll(PDO::FETCH_ASSOC);

    // Récupération des pièces jointes éventuelles
    $stmtAtt = $pdo->prepare("SELECT * FROM quiz_submission_attachment WHERE submission_id = :sub_id");
    $stmtAtt->execute([':sub_id' => $sub['submission_id']]);
    $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div class="modal fade" id="modalSub<?= (int)$sub['submission_id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title"><?= e($sub['quiz_titre']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    
                    <div class="row g-3 mb-4 bg-light p-3 rounded">
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Type</small>
                            <strong><?= e($sub['type_quiz']) ?></strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Statut</small>
                            <?= badgeStatut($sub['statut_soumission']) ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Note finale</small>
                            <strong class="text-primary">
                                <?= $sub['note_totale'] !== null ? number_format((float)$sub['note_totale'], 2, ',', ' ') : '—' ?>
                            </strong>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-muted d-block">Date de remise</small>
                            <small><?= date('d/m/Y H:i', strtotime($sub['date_submitted'])) ?></small>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3">Détail des questions & réponses :</h6>

                    <?php foreach ($answers as $index => $ans): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="fw-bold">Q<?= $index + 1 ?>. <?= e($ans['question_text']) ?></span>
                                <span class="badge bg-light text-dark border ms-2">
                                    <?= number_format((float)($ans['points_obtenus'] ?? 0), 2, ',', ' ') ?> / <?= number_format((float)$ans['max_points'], 2, ',', ' ') ?> pts
                                </span>
                            </div>

                            <div class="mb-2">
                                <small class="text-muted d-block">Votre réponse :</small>
                                <?php if ($ans['question_type'] === 'QCM'): ?>
                                    <div class="p-2 bg-white rounded border border-light-subtle">
                                        <?= e($ans['choix_libelle'] ?? 'Aucun choix sélectionné') ?>
                                    </div>
                                <?php else: ?>
                                    <div class="p-2 bg-white rounded border border-light-subtle">
                                        <?= nl2br(e($ans['reponse_text'] ?? 'Aucune réponse rédigée')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($ans['feedback'])): ?>
                                <div class="alert alert-info py-2 px-3 mb-0 small">
                                    <i class="bi bi-info-circle me-1"></i> <strong>Correction :</strong> <?= e($ans['feedback']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($attachments)): ?>
                        <h6 class="fw-bold mt-4 mb-2">Fichiers joints transmis :</h6>
                        <ul class="list-group">
                            <?php foreach ($attachments as $att): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= e($att['original_name']) ?></span>
                                    <a href="<?= BASE_URL . '/' . e($att['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        Télécharger
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/layout/footer.php'; ?>