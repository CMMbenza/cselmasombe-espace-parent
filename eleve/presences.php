<?php
// /parent/eleve/presences.php
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

// 2) Statistiques globales de présence de l'élève
$sqlStats = "
    SELECT 
        COUNT(ad.id) AS total_appels,
        SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS total_presents,
        SUM(CASE WHEN ad.statut = 'absent' THEN 1 ELSE 0 END) AS total_absents
    FROM appel_detail ad
    WHERE ad.eleve_id = :eid
";
$stStats = $pdo->prepare($sqlStats);
$stStats->execute([':eid' => $eid]);
$stats = $stStats->fetch(PDO::FETCH_ASSOC);

$totalAppels = (int)($stats['total_appels'] ?? 0);
$totalPresents = (int)($stats['total_presents'] ?? 0);
$totalAbsents = (int)($stats['total_absents'] ?? 0);
$tauxPresence = $totalAppels > 0 ? round(($totalPresents / $totalAppels) * 100, 1) : 100;

// 3) Historique détaillé des appels
$sqlHistorique = "
    SELECT 
        a.date_appel,
        a.anneeScolaire,
        ad.statut,
        ad.remarque
    FROM appel_detail ad
    JOIN appel a ON a.id = ad.appel_id
    WHERE ad.eleve_id = :eid
    ORDER BY a.date_appel DESC
";
$stHist = $pdo->prepare($sqlHistorique);
$stHist->execute([':eid' => $eid]);
$historique = $stHist->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 mb-1"><i class="bi bi-calendar-check-fill text-primary me-2"></i>Suivi des Présences</h2>
            <p class="text-muted mb-0">
                Élève : <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom'], ENT_QUOTES, 'UTF-8') ?></strong> 
                | Classe : <span class="badge text-bg-info"><?= htmlspecialchars($eleve['classe_desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
        </div>
    </div>

    <!-- Cartes Résumé / Statistiques -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <small class="text-muted d-block mb-1">Total Appels</small>
                <span class="fs-3 fw-bold text-dark"><?= $totalAppels ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <small class="text-muted d-block mb-1">Présences</small>
                <span class="fs-3 fw-bold text-success"><?= $totalPresents ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
                <small class="text-muted d-block mb-1">Absences</small>
                <span class="fs-3 fw-bold text-danger"><?= $totalAbsents ?></span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
                <small class="text-muted d-block mb-1">Taux d'assiduité</small>
                <span class="fs-3 fw-bold text-primary"><?= $tauxPresence ?>%</span>
            </div>
        </div>
    </div>

    <!-- Historique des présences -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="card-title mb-0 text-dark">
                <i class="bi bi-clock-history me-2 text-primary"></i>Historique détaillé des journées
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($historique)): ?>
                <div class="p-4 text-center text-muted">
                    Aucun appel n'a encore été enregistré pour cet élève.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date de l'appel</th>
                                <th>Statut</th>
                                <th>Remarque / Motif</th>
                                <th>Année Scolaire</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historique as $row): ?>
                                <tr>
                                    <td class="fw-bold">
                                        <i class="bi bi-calendar3 me-2 text-muted"></i>
                                        <?= date('d/m/Y', strtotime($row['date_appel'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($row['statut'] === 'present'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <i class="bi bi-check-circle-fill me-1"></i>Présent(e)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                                <i class="bi bi-x-circle-fill me-1"></i>Absent(e)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= !empty($row['remarque']) ? htmlspecialchars($row['remarque'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted small fst-italic">Aucune remarque</span>' ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= htmlspecialchars($row['anneeScolaire'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>