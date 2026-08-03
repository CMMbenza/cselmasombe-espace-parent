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
// A) RECUPERATION DU JOURNAL DU JOUR
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
        a.prenom AS prof_prenom
    FROM journal_classe j
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN agent a ON a.id = j.prof_id
    WHERE j.classe_id = :classe_id
      AND j.statut = 'valider'
      AND j.jour_date = CURRENT_DATE()
    ORDER BY j.id DESC
";
$stJour = $pdo->prepare($sqlJour);
$stJour->execute([':classe_id' => $classeId]);
$journalDuJour = $stJour->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// B) RECUPERATION DE L'HISTORIQUE DU JOURNAL
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
    $whereClauses[] = "(j.matieres LIKE :search OR c.intitule LIKE :search OR j.note LIKE :search)";
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
        a.prenom AS prof_prenom
    FROM journal_classe j
    JOIN cours c ON c.id = j.cours_id
    LEFT JOIN agent a ON a.id = j.prof_id
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

                                <?php if (!empty($item['piece_jointe'])): ?>
                                    <a href="<?= BASE_URL ?>/uploads/attachement_journal_de_class/<?= urlencode($item['piece_jointe']) ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-paperclip me-1"></i>Télécharger la pièce jointe
                                    </a>
                                <?php endif; ?>
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
                    <input type="text" name="q" class="form-control" placeholder="Rechercher un cours, un thème..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
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
                        <th style="width: 15%;">Date</th>
                        <th style="width: 20%;">Cours</th>
                        <th style="width: 40%;">Leçon / Devoirs</th>
                        <th style="width: 15%;">Enseignant</th>
                        <th style="width: 10%; text-align: center;">Fichier</th>
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
                                <?php if (!empty($h['piece_jointe'])): ?>
                                    <a href="<?= BASE_URL ?>/uploads/attachement_journal_de_class/<?= urlencode($h['piece_jointe']) ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-primary" title="Télécharger">
                                        <i class="bi bi-download"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
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

<?php require_once __DIR__ . '/../layout/footer.php'; ?>