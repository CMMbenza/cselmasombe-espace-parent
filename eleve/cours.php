<?php
// /parent/eleve/cours.php
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
    SELECT e.id, e.nom, e.prenom, e.classe, CONCAT(c.description ,' ', cy.description) AS classe_desc
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    JOIN cycle cy ON cy.id = c.cycle
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

// 2) Récupération des résumés de cours enregistrés pour cette classe via la table resume_cours et journal_classe
$sqlResumes = "
    SELECT 
        rc.id AS resume_id,
        rc.fiche_no,
        rc.domaine,
        rc.discipline,
        rc.titre_lecon,
        rc.type_lecon,
        rc.competence_attendue,
        rc.resume_texte,
        rc.devoir,
        rc.piece_jointe AS resume_pj,
        rc.created_at AS resume_date,
        j.id AS journal_id,
        j.jour_date,
        j.matieres,
        j.note AS journal_note,
        j.piece_jointe AS journal_pj,
        c.intitule AS cours_nom,
        a.nom AS prof_nom,
        a.prenom AS prof_prenom
    FROM resume_cours rc
    JOIN journal_classe j ON j.id = rc.journal_id
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN agent a ON a.id = j.prof_id
    WHERE j.classe_id = :classe_id
      AND j.statut = 'valider'
    ORDER BY j.jour_date DESC, rc.id DESC
";
$stRes = $pdo->prepare($sqlResumes);
$stRes->execute([':classe_id' => $classeId]);
$resumes = $stRes->fetchAll(PDO::FETCH_ASSOC);

// 3) Récupération des cours et chapitres associés (support de cours)
$sqlChapitres = "
    SELECT 
        cc.id AS chapitre_id,
        cc.titre AS chapitre_titre,
        cc.date_creation AS chapitre_date,
        co.intitule AS cours_nom,
        a.nom AS prof_nom,
        a.prenom AS prof_prenom
    FROM cours_chapitres cc
    JOIN cours co ON co.id = cc.cours_id
    LEFT JOIN agent a ON a.id = cc.prof_id
    WHERE cc.classe_id = :classe_id
    ORDER BY co.intitule ASC, cc.id ASC
";
$st = $pdo->prepare($sqlChapitres);
$st->execute([':classe_id' => $classeId]);
$chapitres = $st->fetchAll(PDO::FETCH_ASSOC);

// Indexer les leçons par chapitre
$chapitreIds = array_column($chapitres, 'chapitre_id');
$leconsParChapitre = [];

if (!empty($chapitreIds)) {
    $inQuery = implode(',', array_fill(0, count($chapitreIds), '?'));
    $sqlLecons = "
        SELECT id, chapitre_id, titre, description, fichier, type_format, date_creation
        FROM cours_lecons
        WHERE chapitre_id IN ($inQuery)
        ORDER BY id ASC
    ";
    $stL = $pdo->prepare($sqlLecons);
    $stL->execute($chapitreIds);
    $rawLecons = $stL->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawLecons as $l) {
        $leconsParChapitre[$l['chapitre_id']][] = $l;
    }
}

// Fonction utilitaire pour icône de format
function getFormatIcon(string $format): string {
    return match ($format) {
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'video' => 'bi-file-earmark-play text-primary',
        'audio' => 'bi-file-earmark-music text-warning',
        default => 'bi-file-earmark-word text-info',
    };
}
?>

<div class="container py-4">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 mb-1"><i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Résumés de cours & Supports</h2>
            <p class="text-muted mb-0">
                Élève : <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom'], ENT_QUOTES, 'UTF-8') ?></strong>
                | Classe : <span class="badge text-bg-info"><?= htmlspecialchars($eleve['classe_desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SECTION 1 : RÉSUMÉS DE COURS (resume_cours) -->
    <!-- ========================================== -->
    <div class="card border-0 shadow-sm mb-5">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-file-earmark-text me-2"></i>Résumés des leçons dispensées</h5>
            <span class="badge bg-white text-primary rounded-pill"><?= count($resumes) ?> résumé(s)</span>
        </div>
        <div class="card-body p-3">
            <?php if (empty($resumes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-folder-x display-6 d-block mb-2 text-secondary"></i>
                    Aucun résumé de cours n'a été publié pour le moment.
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($resumes as $res): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="card h-100 border shadow-sm">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-primary-subtle text-primary fw-semibold">
                                            <?= htmlspecialchars($res['cours_nom'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <small class="text-muted">
                                            <i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y', strtotime($res['jour_date'])) ?>
                                        </small>
                                    </div>

                                    <h6 class="card-title fw-bold text-dark mb-1">
                                        <?= htmlspecialchars($res['titre_lecon'] ?: 'Leçon du journal', ENT_QUOTES, 'UTF-8') ?>
                                    </h6>

                                    <p class="text-muted small mb-2">
                                        <strong>Discipline :</strong> <?= htmlspecialchars($res['discipline'] ?: 'N/C', ENT_QUOTES, 'UTF-8') ?><br>
                                        <strong>Enseignant :</strong> <?= htmlspecialchars(trim(($res['prof_prenom'] ?? '') . ' ' . ($res['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'N/C' ?>
                                    </p>

                                    <div class="mt-auto pt-3 border-top d-flex gap-2 flex-wrap">
                                        <!-- Bouton Voir Résumé -->
                                        <button type="button" 
                                                class="btn btn-sm btn-success btn-view-resume flex-grow-1"
                                                data-cours="<?= htmlspecialchars($res['cours_nom'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-titre="<?= htmlspecialchars($res['titre_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-fiche="<?= htmlspecialchars($res['fiche_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-domaine="<?= htmlspecialchars($res['domaine'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-discipline="<?= htmlspecialchars($res['discipline'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-type="<?= htmlspecialchars($res['type_lecon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-competence="<?= htmlspecialchars($res['competence_attendue'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-resume="<?= htmlspecialchars($res['resume_texte'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-devoir="<?= htmlspecialchars($res['devoir'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-pj="<?= htmlspecialchars($res['resume_pj'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-file-text me-1"></i>Voir résumé
                                        </button>

                                        <!-- Bouton Vérifier Journal à côté -->
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-primary btn-view-journal"
                                                data-cours="<?= htmlspecialchars($res['cours_nom'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-date="<?= date('d/m/Y', strtotime($res['jour_date'])) ?>"
                                                data-prof="<?= htmlspecialchars(trim(($res['prof_prenom'] ?? '') . ' ' . ($res['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                                data-matieres="<?= htmlspecialchars($res['matieres'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-note="<?= htmlspecialchars($res['journal_note'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                data-pj="<?= htmlspecialchars($res['journal_pj'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-journal-check me-1"></i>Vérifier journal
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SECTION 2 : SUPPORTS ET CHAPITRES DE COURS -->
    <!-- ========================================== -->
    <h4 class="h5 mb-3 text-secondary"><i class="bi bi-folder-symlink me-2"></i>Chapitres et supports téléchargeables</h4>
    <?php if (empty($chapitres)): ?>
        <div class="alert alert-info shadow-sm text-center py-4" role="alert">
            <i class="bi bi-info-circle display-6 d-block mb-2 text-info"></i>
            Aucun chapitre de cours disponible pour le moment pour cette classe.
        </div>
    <?php else: ?>
        <div class="row g-4 mb-4">
            <?php foreach ($chapitres as $chap): ?>
                <?php $lecons = $leconsParChapitre[$chap['chapitre_id']] ?? []; ?>
                <div class="col-12 col-lg-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-white border-bottom py-3">
                            <span class="badge bg-primary-subtle text-primary mb-2">
                                <?= htmlspecialchars($chap['cours_nom'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <h5 class="card-title text-dark mb-1">
                                <i class="bi bi-bookmark-fill text-primary me-1"></i>
                                <?= htmlspecialchars($chap['chapitre_titre'], ENT_QUOTES, 'UTF-8') ?>
                            </h5>
                            <small class="text-muted">
                                Enseignant : <?= htmlspecialchars(trim(($chap['prof_prenom'] ?? '') . ' ' . ($chap['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'N/A' ?>
                            </small>
                        </div>
                        <div class="card-body p-3">
                            <h6 class="text-uppercase text-muted small fw-bold mb-3">Leçons & Ressources :</h6>
                            <?php if (empty($lecons)): ?>
                                <p class="text-muted small fst-italic">Aucune leçon publiée dans ce chapitre.</p>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($lecons as $lec): ?>
                                        <div class="list-group-item px-2 py-2 mb-2 border rounded bg-light">
                                            <div class="d-flex justify-content-between align-items-start gap-2">
                                                <div class="d-flex align-items-start gap-2">
                                                    <i class="bi <?= getFormatIcon($lec['type_format']) ?> fs-4"></i>
                                                    <div>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($lec['titre'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <?php if (!empty($lec['description'])): ?>
                                                            <small class="text-muted d-block"><?= htmlspecialchars($lec['description'], ENT_QUOTES, 'UTF-8') ?></small>
                                                        <?php endif; ?>
                                                        <small class="text-muted" style="font-size: 0.75rem;">
                                                            Format : <span class="text-uppercase fw-semibold"><?= htmlspecialchars($lec['type_format'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        </small>
                                                    </div>
                                                </div>
                                                <?php if (!empty($lec['fichier'])): ?>
                                                    <a href="<?= BASE_URL . '/uploads/cours/' . htmlspecialchars($lec['fichier'], ENT_QUOTES, 'UTF-8') ?>" 
                                                       target="_blank" 
                                                       class="btn btn-sm btn-outline-primary shrink-0">
                                                        <i class="bi bi-download me-1"></i>Ouvrir
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL 1 : VOIR RÉSUMÉ DE COURS             -->
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

<!-- ========================================== -->
<!-- MODAL 2 : VÉRIFIER JOURNAL DE CLASSE      -->
<!-- ========================================== -->
<div class="modal fade" id="modalJournalClasse" tabindex="-1" aria-labelledby="modalJournalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fs-6" id="modalJournalLabel">
                    <i class="bi bi-journal-check me-2"></i>Détails du journal de classe
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <span class="badge bg-primary fs-6 mb-2" id="jnlCours"></span>
                    <div class="small text-muted"><strong>Date :</strong> <span id="jnlDate" class="text-dark"></span></div>
                    <div class="small text-muted"><strong>Enseignant :</strong> <span id="jnlProf" class="text-dark"></span></div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-dark mb-1">Matières / Contenu dispensé :</h6>
                    <div class="p-2 bg-light rounded border text-dark" style="white-space: pre-wrap;" id="jnlMatieres"></div>
                </div>

                <div id="boxJnlNote" class="mb-3 d-none">
                    <h6 class="fw-bold text-warning-emphasis mb-1">Remarques / Notes du professeur :</h6>
                    <div class="p-2 bg-warning-subtle rounded border border-warning text-dark" style="white-space: pre-wrap;" id="jnlNote"></div>
                </div>

                <div id="boxJnlPj" class="mt-3 d-none">
                    <a id="jnlPjLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-download me-1"></i>Télécharger l'attachement du journal
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
    // 1) Gestion Modal Voir Résumé
    const modalResumeEl = document.getElementById('modalResumeCours');
    if (modalResumeEl) {
        const bsModalResume = new bootstrap.Modal(modalResumeEl);

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
                document.getElementById('resResumeTexte').textContent = d.resume || 'Aucun texte disponible.';

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

                bsModalResume.show();
            });
        });
    }

    // 2) Gestion Modal Vérifier Journal
    const modalJournalEl = document.getElementById('modalJournalClasse');
    if (modalJournalEl) {
        const bsModalJournal = new bootstrap.Modal(modalJournalEl);

        document.querySelectorAll('.btn-view-journal').forEach(btn => {
            btn.addEventListener('click', function () {
                const d = this.dataset;

                document.getElementById('jnlCours').textContent = d.cours || '';
                document.getElementById('jnlDate').textContent = d.date || '';
                document.getElementById('jnlProf').textContent = d.prof || 'N/C';
                document.getElementById('jnlMatieres').textContent = d.matieres || 'Non renseigné';

                // Note / Remarque
                const boxNote = document.getElementById('boxJnlNote');
                if (d.note && d.note.trim() !== '') {
                    document.getElementById('jnlNote').textContent = d.note;
                    boxNote.classList.remove('d-none');
                } else {
                    boxNote.classList.add('d-none');
                }

                // Pièce jointe du journal
                const boxPj = document.getElementById('boxJnlPj');
                const pjLink = document.getElementById('jnlPjLink');
                if (d.pj && d.pj.trim() !== '') {
                    pjLink.href = '<?= BASE_URL ?>/uploads/attachement_journal_de_class/' + encodeURIComponent(d.pj);
                    boxPj.classList.remove('d-none');
                } else {
                    boxPj.classList.add('d-none');
                }

                bsModalJournal.show();
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>