<?php
// /parent/palmares_history.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();

require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$mid = (int)$_SESSION['parent']['id'];
$eleve_id = isset($_GET['eleve']) ? (int)$_GET['eleve'] : 0;

if ($eleve_id <= 0) {
    header('Location: ' . BASE_URL . '/parent/palmares.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Vérification que l'élève appartient bien au parent connecté
|--------------------------------------------------------------------------
*/
$stmtEleve = $pdo->prepare("
    SELECT 
        e.id,
        e.nom,
        e.postnom,
        e.prenom,
        e.genre,
        e.anneeScolaire,
        c.description AS classe_desc,
        cy.description AS cycle_desc
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    LEFT JOIN cycle cy ON cy.id = c.cycle
    WHERE e.id = :eleve_id AND e.menage = :mid
    LIMIT 1
");
$stmtEleve->execute([
    ':eleve_id' => $eleve_id,
    ':mid'      => $mid
]);
$eleve = $stmtEleve->fetch(PDO::FETCH_ASSOC);

if (!$eleve) {
    // Élève introuvable ou n'appartient pas à ce parent
    header('Location: ' . BASE_URL . '/parent/palmares.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. Récupération de TOUS les palmarès de cet élève (+ Calcul du Rang)
|--------------------------------------------------------------------------
*/
$stmtPalmares = $pdo->prepare("
    SELECT 
        pt.*,
        CONCAT(c.description ,' - ', cy.description) AS classe_nom,        
        (
            SELECT COUNT(*) + 1
            FROM palmares_trimestre p_rank
            WHERE p_rank.classe_id = pt.classe_id
              AND p_rank.trimestre = pt.trimestre
              AND p_rank.percent > pt.percent
        ) AS place
    FROM palmares_trimestre pt
    JOIN classe c ON c.id = pt.classe_id
    JOIN cycle cy ON cy.id = c.cycle
    WHERE pt.eleve_id = :eleve_id
    ORDER BY pt.id DESC
");
$stmtPalmares->execute([':eleve_id' => $eleve_id]);
$history = $stmtPalmares->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Helpers d'affichage
|--------------------------------------------------------------------------
*/
function badgeAutorise(int $v): string
{
    if ($v === 1) {
        return '<span class="badge text-bg-success">Autorisé</span>';
    }
    return '<span class="badge text-bg-danger">Non autorisé</span>';
}

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
?>

<div class="container py-4">

    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">
                Historique des résultats :
                <span
                    class="text-primary"><?= e($eleve['nom'] . ' ' . $eleve['postnom'] . ' ' . $eleve['prenom']) ?></span>
            </h1>
            <div class="text-muted small">
                Classe actuelle : <?= e($eleve['classe_desc']) ?> (<?= e($eleve['cycle_desc'] ?? '—') ?>) | Genre :
                <?= e($eleve['genre']) ?>
            </div>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/parent/palmares.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Retour aux enfants
            </a>
        </div>
    </div>

    <!-- Tableau de l'historique -->
    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Trimestre</th>
                        <th>Classe</th>
                        <th>Année Scolaire</th>
                        <th>Total Obtenu</th>
                        <th>Pourcentage</th>
                        <th class="text-center">Rang</th>
                        <th>Observation</th>
                        <th>Autorisation</th>
                        <th width="120" class="text-end">Détails</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <em>Aucun historique de palmarès disponible pour cet élève.</em>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($history as $row): ?>
                    <tr>
                        <td>
                            <span class="badge text-bg-primary fs-6">
                                <?= e($row['trimestre']) ?>
                            </span>
                        </td>
                        <td><?= e($row['classe_nom']) ?></td>
                        <td><?= e($row['anneeScolaire'] ?? $eleve['anneeScolaire']) ?></td>
                        <td>
                            <?php if ((int)$row['autorise'] === 1): ?>
                            <strong>
                                <?= number_format((float)$row['total'], 2, ',', ' ') ?> /
                                <?= number_format((float)$row['max_total'], 2, ',', ' ') ?>
                            </strong>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$row['autorise'] === 1): ?>
                            <span class="badge text-bg-dark fs-6">
                                <?= number_format((float)$row['percent'], 2, ',', ' ') ?> %
                            </span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int)$row['autorise'] === 1): ?>
                            <?= formatRang((int)$row['place']) ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['obs'])): ?>
                            <span class="badge text-bg-info"><?= e($row['obs']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= badgeAutorise((int)$row['autorise']) ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#modalHistory<?= (int)$row['id'] ?>">
                                Voir
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

<!-- MODALES DE DÉTAILS POUR CHAQUE TRIMESTRE -->
<?php foreach ($history as $row): ?>
<div class="modal fade" id="modalHistory<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title">
                    Détails - <?= e($row['trimestre']) ?> (<?= e($row['classe_nom']) ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ((int)$row['autorise'] === 1): ?>

                <!-- Synthèse -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border">
                            <small class="text-muted d-block mb-1">Pourcentage</small>
                            <span class="fs-4 fw-bold text-dark">
                                <?= number_format((float)$row['percent'], 2, ',', ' ') ?> %
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border">
                            <small class="text-muted d-block mb-1">Total Obtenu</small>
                            <span class="fs-4 fw-bold text-primary">
                                <?= number_format((float)$row['total'], 2, ',', ' ') ?> /
                                <?= number_format((float)$row['max_total'], 2, ',', ' ') ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded text-center border border-warning">
                            <small class="text-muted d-block mb-1">Rang Calculé</small>
                            <div>
                                <?= formatRang((int)$row['place']) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tableau par domaine -->
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="text-start">Domaine</th>
                                <th>Points Obtenus</th>
                                <th>Maximum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-start fw-semibold">Langues</td>
                                <td><?= number_format((float)($row['lang'] ?? 0), 2, ',', ' ') ?></td>
                                <td><?= number_format((float)($row['max_lang'] ?? 0), 2, ',', ' ') ?></td>
                            </tr>
                            <tr>
                                <td class="text-start fw-semibold">Mathématiques</td>
                                <td><?= number_format((float)($row['math'] ?? 0), 2, ',', ' ') ?></td>
                                <td><?= number_format((float)($row['max_math'] ?? 0), 2, ',', ' ') ?></td>
                            </tr>
                            <tr>
                                <td class="text-start fw-semibold">Culture Générale</td>
                                <td><?= number_format((float)($row['cult'] ?? 0), 2, ',', ' ') ?></td>
                                <td><?= number_format((float)($row['max_cult'] ?? 0), 2, ',', ' ') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($row['obs'])): ?>
                <div class="mt-3 p-2 bg-info-subtle border border-info-subtle rounded small">
                    <strong>Observation :</strong> <?= e($row['obs']) ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="alert alert-danger mb-0">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Accès aux résultats de ce trimestre bloqué. Veuillez contacter l'administration de l'établissement.
                </div>
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