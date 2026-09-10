<?php
// /parent/eleve/journal.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();

if ($eid <= 0) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// 1) Vérification que l'élève appartient au ménage
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, e.prenom, e.classe, c.description AS classe_desc
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    WHERE e.id = :eid AND e.menage = :mid
    LIMIT 1
");
$stmt->execute([':eid' => $eid, ':mid' => $mid]);
$eleve = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eleve) {
    set_current_eleve(0);
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$classeId = (int)$eleve['classe'];

// ==========================================
// A) RECUPERATION DU JOURNAL DU JOUR + RESUME
// ==========================================
$sqlJour = "
    SELECT 
        j.id,
        j.jour_date,
        j.matieres,
        j.note,
        j.piece_jointe,
        c.intitule AS cours_nom,
        a.nom AS prof_nom,
        a.prenom AS prof_prenom,
        rc.id AS resume_id,
        rc.fiche_no,
        rc.domaine,
        rc.discipline,
        rc.titre_lecon,
        rc.type_lecon,
        rc.competence_attendue,
        rc.resume_texte,
        rc.devoir,
        rc.piece_jointe AS resume_pj
    FROM journal_classe j
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN agent a ON a.id = j.prof_id
    LEFT JOIN resume_cours rc ON rc.journal_id = j.id
    WHERE j.classe_id = :classe_id
      AND j.statut = 'valider'
      AND j.jour_date = CURRENT_DATE()
    ORDER BY j.id DESC
";
$stJour = $pdo->prepare($sqlJour);
$stJour->execute([':classe_id' => $classeId]);
$journalDuJour = $stJour->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// B) RECUPERATION DE L'HISTORIQUE DU JOURNAL + RESUME
// ==========================================
$search = trim((string)($_GET['q'] ?? ''));
$filterDate = trim((string)($_GET['date'] ?? ''));

// Pagination
$perPage = 6;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$whereClauses = [
    "j.classe_id = :classe_id",
    "j.statut = 'valider'",
    "j.jour_date < CURRENT_DATE()",
    "YEAR(j.jour_date) = YEAR(CURRENT_DATE())"
];
$params = [':classe_id' => $classeId];

if ($search !== '') {
    $whereClauses[] = "(j.matieres LIKE :search OR c.intitule LIKE :search OR j.note LIKE :search OR rc.titre_lecon LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterDate !== '') {
    $whereClauses[] = "j.jour_date = :filter_date";
    $params[':filter_date'] = $filterDate;
}

$whereSql = implode(' AND ', $whereClauses);

// Compter le total d'entrées historiques
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM journal_classe j
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN resume_cours rc ON rc.journal_id = j.id
    WHERE $whereSql
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Entrées historiques
$sqlHistorique = "
    SELECT 
        j.id,
        j.jour_date,
        j.matieres,
        j.note,
        j.piece_jointe,
        c.intitule AS cours_nom,
        a.nom AS prof_nom,
        a.prenom AS prof_prenom,
        rc.id AS resume_id,
        rc.fiche_no,
        rc.domaine,
        rc.discipline,
        rc.titre_lecon,
        rc.type_lecon,
        rc.competence_attendue,
        rc.resume_texte,
        rc.devoir,
        rc.piece_jointe AS resume_pj
    FROM journal_classe j
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN agent a ON a.id = j.prof_id
    LEFT JOIN resume_cours rc ON rc.journal_id = j.id
    WHERE $whereSql
    ORDER BY j.jour_date DESC, j.id DESC
    LIMIT $perPage OFFSET $offset
";
$stHisto = $pdo->prepare($sqlHistorique);
$stHisto->execute($params);
$historique = $stHisto->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h3 mb-1"><i class="bi bi-journal-check text-success me-2"></i>Journal de classe</h2>
            <p class="text-muted mb-0">
                Élève : <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom'], ENT_QUOTES, 'UTF-8') ?></strong>
                | Classe : <span class="badge text-bg-info"><?= htmlspecialchars($eleve['classe_desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SECTION 1 : JOURNAL DU JOUR                -->
    <!-- ========================================== -->
    <div class="card border-primary shadow-sm mb-5">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fs-6 fw-bold">
                <i class="bi bi-calendar2-day me-2"></i>Journal d'aujourd'hui (<?= date('d/m/Y') ?>)
            </h5>
            <span class="badge bg-white text-primary rounded-pill"><?= count($journalDuJour) ?> leçon(s)</span>
        </div>
        <div class="card-body">
            <?php if (empty($journalDuJour)): ?>
                <div class="text-center py-3 text-muted">
                    <i class="bi bi-calendar-x fs-2 d-block mb-1 text-secondary"></i>
                    Aucun cours ou devoir enregistré pour aujourd'hui.
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($journalDuJour as $item): ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light shadow-sm">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="text-primary fw-bold mb-0">
                                        <i class="bi bi-book me-1"></i><?= htmlspecialchars($item['cours_nom'], ENT_QUOTES, 'UTF-8') ?>
                                    </h6>
                                    <small class="text-muted">
                                        Prof : <?= htmlspecialchars(trim(($item['prof_prenom'] ?? '') . ' ' . ($item['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'N/C' ?>
                                    </small>
                                </div>
                                
                                <p class="mb-2 text-dark">
                                    <strong>Matière dispensée :</strong><br>
                                    <?= nl2br(htmlspecialchars($item['matieres'], ENT_QUOTES, 'UTF-8')) ?>
                                </p>

                                <?php if (!empty($item['note'])): ?>
                                    <div class="p-2 bg-white rounded border-start border-3 border-warning mb-2">
                                        <small class="text-muted d-block fw-bold"><i class="bi bi-sticky me-1"></i>Remarques / Devoir :</small>
                                        <small class="text-dark"><?= nl2br(htmlspecialchars($item['note'], ENT_QUOTES, 'UTF-8')) ?></small>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex gap-2 flex-wrap mt-2">
                                    <?php if (!empty($item['piece_jointe'])): ?>
                                        <a href="<?= BASE_URL ?>/uploads/attachement_journal_de_class/<?= urlencode($item['piece_jointe']) ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-paperclip me-1"></i>Pièce jointe journal
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($item['resume_id'])): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-success btn-view-resume"
                                                data-cours="<?= htmlspecialchars($item['cours_nom'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-titre="<?= htmlspecialchars($item['titre_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-fiche="<?= htmlspecialchars($item['fiche_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-domaine="<?= htmlspecialchars($item['domaine'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-discipline="<?= htmlspecialchars($item['discipline'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-type="<?= htmlspecialchars($item['type_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-competence="<?= htmlspecialchars($item['competence_attendue'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-resume="<?= htmlspecialchars($item['resume_texte'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-devoir="<?= htmlspecialchars($item['devoir'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-pj="<?= htmlspecialchars($item['resume_pj'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-file-text me-1"></i>Voir résumé
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SECTION 2 : HISTORIQUE DU JOURNAL          -->
    <!-- ========================================== -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="h5 mb-0 text-secondary"><i class="bi bi-clock-history me-2"></i>Historique du journal</h4>
    </div>

    <!-- Barre de recherche -->
    <div class="card shadow-sm mb-4 border-0 bg-light">
        <div class="card-body p-3">
            <form method="GET" action="" class="row g-2">
                <div class="col-md-6">
                    <input type="text" name="q" class="form-control" placeholder="Rechercher un cours, une leçon..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-secondary"><i class="bi bi-filter me-1"></i>Filtrer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tableau Historique -->
    <?php if (empty($historique)): ?>
        <div class="alert alert-info text-center py-4 shadow-sm">
            <i class="bi bi-info-circle fs-4 d-block mb-2"></i>
            Aucun historique correspondant disponible.
        </div>
    <?php else: ?>
        <div class="table-responsive shadow-sm rounded">
            <table class="table table-hover align-middle bg-white mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 18%;">Cours</th>
                        <th style="width: 38%;">Leçon / Devoirs</th>
                        <th style="width: 15%;">Enseignant</th>
                        <th style="width: 17%; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historique as $h): ?>
                        <tr>
                            <td class="fw-bold text-secondary">
                                <?= date('d/m/Y', strtotime($h['jour_date'])) ?>
                            </td>
                            <td class="fw-semibold text-primary">
                                <?= htmlspecialchars($h['cours_nom'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td>
                                <div><?= nl2br(htmlspecialchars($h['matieres'], ENT_QUOTES, 'UTF-8')) ?></div>
                                <?php if (!empty($h['note'])): ?>
                                    <div class="text-warning-emphasis small mt-1">
                                        <strong>Devoir/Obs :</strong> <?= htmlspecialchars($h['note'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= htmlspecialchars(trim(($h['prof_prenom'] ?? '') . ' ' . ($h['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'N/C' ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1 flex-wrap">
                                    <?php if (!empty($h['piece_jointe'])): ?>
                                        <a href="<?= BASE_URL ?>/uploads/attachement_journal_de_class/<?= urlencode($h['piece_jointe']) ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-primary" title="Télécharger la PJ du journal">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($h['resume_id'])): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-success btn-view-resume"
                                                title="Voir le résumé du cours"
                                                data-cours="<?= htmlspecialchars($h['cours_nom'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-titre="<?= htmlspecialchars($h['titre_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-fiche="<?= htmlspecialchars($h['fiche_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-domaine="<?= htmlspecialchars($h['domaine'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-discipline="<?= htmlspecialchars($h['discipline'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-type="<?= htmlspecialchars($h['type_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-competence="<?= htmlspecialchars($h['competence_attendue'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-resume="<?= htmlspecialchars($h['resume_texte'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-devoir="<?= htmlspecialchars($h['devoir'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-pj="<?= htmlspecialchars($h['resume_pj'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-file-text me-1"></i>Voir résumé
                                        </button>
                                    <?php endif; ?>

                                    <?php if (empty($h['piece_jointe']) && empty($h['resume_id'])): ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($search) ?>&date=<?= urlencode($filterDate) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL : VOIR RESUMÉ DU COURS               -->
<!-- ========================================== -->
<div class="modal fade" id="modalResumeCours" tabindex="-1" aria-labelledby="modalResumeLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fs-6" id="modalResumeLabel">
                    <i class="bi bi-journal-bookmark me-2"></i>Résumé de cours — <span id="resCoursNom"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div class="border-bottom pb-3 mb-3">
                    <h4 class="h5 text-primary mb-2" id="resTitreLecon"></h4>
                    <div class="row g-2 small text-muted">
                        <div class="col-sm-6"><strong>Fiche N° :</strong> <span id="resFicheNo" class="text-dark"></span></div>
                        <div class="col-sm-6"><strong>Type de leçon :</strong> <span id="resTypeLecon" class="text-dark"></span></div>
                        <div class="col-sm-6"><strong>Domaine :</strong> <span id="resDomaine" class="text-dark"></span></div>
                        <div class="col-sm-6"><strong>Discipline :</strong> <span id="resDiscipline" class="text-dark"></span></div>
                    </div>
                </div>

                <div id="boxCompetence" class="mb-3 d-none">
                    <h6 class="fw-bold text-secondary mb-1"><i class="bi bi-award me-1"></i>Compétence attendue :</h6>
                    <div class="p-2 bg-light rounded border text-dark" id="resCompetence"></div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-success mb-1"><i class="bi bi-file-text me-1"></i>Résumé du cours :</h6>
                    <div class="p-3 bg-light rounded border text-dark" style="white-space: pre-wrap;" id="resResumeTexte"></div>
                </div>

                <div id="boxDevoir" class="mb-3 d-none">
                    <h6 class="fw-bold text-warning-emphasis mb-1"><i class="bi bi-pencil-square me-1"></i>Devoir à domicile :</h6>
                    <div class="p-2 bg-warning-subtle rounded border border-warning text-dark" style="white-space: pre-wrap;" id="resDevoir"></div>
                </div>

                <div id="boxPjResume" class="mt-3 d-none">
                    <a id="resPjLink" href="#" target="_blank" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-download me-1"></i>Télécharger la pièce jointe du résumé
                    </a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('modalResumeCours');
    if (!modalEl) return;
    const bsModal = new bootstrap.Modal(modalEl);

    document.querySelectorAll('.btn-view-resume').forEach(btn => {
        btn.addEventListener('click', function () {
            const d = this.dataset;

            document.getElementById('resCoursNom').textContent = d.cours || '';
            document.getElementById('resTitreLecon').textContent = d.titre || 'Sans titre';
            document.getElementById('resFicheNo').textContent = d.fiche || 'N/C';
            document.getElementById('resTypeLecon').textContent = d.type || 'N/C';
            document.getElementById('resDomaine').textContent = d.domaine || 'N/C';
            document.getElementById('resDiscipline').textContent = d.discipline || 'N/C';

            // Compétence attendue
            const boxComp = document.getElementById('boxCompetence');
            if (d.competence && d.competence.trim() !== '') {
                document.getElementById('resCompetence').textContent = d.competence;
                boxComp.classList.remove('d-none');
            } else {
                boxComp.classList.add('d-none');
            }

            // Texte du résumé
            document.getElementById('resResumeTexte').textContent = d.resume || 'Aucun texte saisi.';

            // Devoir
            const boxDevoir = document.getElementById('boxDevoir');
            if (d.devoir && d.devoir.trim() !== '') {
                document.getElementById('resDevoir').textContent = d.devoir;
                boxDevoir.classList.remove('d-none');
            } else {
                boxDevoir.classList.add('d-none');
            }

            // Pièce jointe du résumé
            const boxPj = document.getElementById('boxPjResume');
            const pjLink = document.getElementById('resPjLink');
            if (d.pj && d.pj.trim() !== '') {
                pjLink.href = '<?= BASE_URL ?>/uploads/resume_cours/' + encodeURIComponent(d.pj);
                boxPj.classList.remove('d-none');
            } else {
                boxPj.classList.add('d-none');
            }

            bsModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>