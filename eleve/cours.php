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

// 2) Récupération des cours et chapitres associés
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
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 mb-1"><i class="bi bi-journal-bookmark-fill text-primary me-2"></i>Cours & Support de cours</h2>
            <p class="text-muted mb-0">
                Élève : <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom'], ENT_QUOTES, 'UTF-8') ?></strong>
                | Classe : <span class="badge text-bg-info"><?= htmlspecialchars($eleve['classe_desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
        </div>
    </div>

    <?php if (empty($chapitres)): ?>
        <div class="alert alert-info shadow-sm text-center py-4" role="alert">
            <i class="bi bi-info-circle display-6 d-block mb-2 text-info"></i>
            Aucun chapitre de cours disponible pour le moment pour cette classe.
        </div>
    <?php else: ?>
        <div class="row g-4">
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>