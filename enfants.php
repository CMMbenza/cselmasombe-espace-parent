<?php
// /parent/enfants.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();

require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$mid = (int)$_SESSION['parent']['id'];

/*
|--------------------------------------------------------------------------
| Récupération des enfants + dernier palmarès uniquement
|--------------------------------------------------------------------------
*/
$kids = $pdo->prepare("
    SELECT 
        e.id,
        e.nom,
        e.postnom,
        e.prenom,
        e.genre, 
        e.anneeScolaire,

        c.description AS classe_desc,
        cy.description AS cycle_desc,

        pt.id            AS palmares_id,
        pt.trimestre,

        pt.lang,
        pt.math,
        pt.cult,

        pt.max_lang,
        pt.max_math,
        pt.max_cult,

        pt.total,
        pt.percent,

        pt.max_total,
        pt.max_percent,

        pt.obs,
        pt.autorise,
        pt.created_at

    FROM eleve e

    JOIN classe c 
        ON c.id = e.classe

    LEFT JOIN cycle cy 
        ON cy.id = c.cycle

    LEFT JOIN palmares_trimestre pt
        ON pt.id = (
            SELECT p2.id
            FROM palmares_trimestre p2
            WHERE p2.eleve_id = e.id
            ORDER BY p2.id DESC
            LIMIT 1
        )

    WHERE e.menage = :mid

    ORDER BY 
        e.nom,
        e.postnom,
        e.prenom
");

$kids->execute([
    ':mid' => $mid
]);

$rows = $kids->fetchAll(PDO::FETCH_ASSOC);

function badgeAutorise(int $v): string
{
    if ($v === 1) {
        return '<span class="badge text-bg-success">Autorisé</span>';
    }

    return '<span class="badge text-bg-danger">Non autorisé</span>';
}
?>

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

        <div>

            <h1 class="h5 mb-0">
                Mes enfants
            </h1>

            <div class="text-muted small">
                Dernier palmarès disponible de chaque élève.
            </div>

        </div>

    </div>

    <div class="card shadow-sm">

        <div class="card-body table-responsive">

            <table class="table table-sm table-hover align-middle">

                <thead class="table-light">

                    <tr>
                        <th>#</th>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Cycle</th>
                        <th>Trimestre</th>
                        <th>Total</th>
                        <th>%</th>
                        <th>Observation</th>
                        <th>Autorisation</th>
                        <th width="180"></th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (!$rows): ?>

                    <tr>
                        <td colspan="10">
                            <em>Aucun enfant trouvé.</em>
                        </td>
                    </tr>

                    <?php else: ?>

                    <?php foreach ($rows as $r): ?>

                    <tr>

                        <td>
                            <?= (int)$r['id'] ?>
                        </td>

                        <td>

                            <div class="fw-semibold">
                                <?= e($r['nom'].' '.$r['postnom'].' '.$r['prenom']) ?>
                            </div>

                            <div class="small text-muted">
                                <?= e($r['genre']) ?>
                            </div>

                        </td>

                        <td>
                            <?= e($r['classe_desc']) ?>
                        </td>

                        <td>
                            <?= e($r['cycle_desc'] ?? '—') ?>
                        </td>

                        <td>

                            <?php if (!empty($r['trimestre'])): ?>

                            <span class="badge text-bg-primary">
                                <?= e($r['trimestre']) ?>
                            </span>

                            <?php else: ?>

                            <span class="text-muted">
                                —
                            </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php if ($r['total'] !== null): ?>

                            <strong>
                                <?= number_format((float)$r['total'], 2, ',', ' ') ?>
                                /
                                <?= number_format((float)$r['max_total'], 2, ',', ' ') ?>
                            </strong>

                            <?php else: ?>

                            <span class="text-muted">—</span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php if ($r['percent'] !== null): ?>

                            <span class="badge text-bg-dark">
                                <?= number_format((float)$r['percent'], 2, ',', ' ') ?> %
                            </span>

                            <?php else: ?>

                            <span class="text-muted">—</span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?php if (!empty($r['obs'])): ?>

                            <span class="badge text-bg-info">
                                <?= e($r['obs']) ?>
                            </span>

                            <?php else: ?>

                            <span class="text-muted">—</span>

                            <?php endif; ?>

                        </td>

                        <td>
                            <?= badgeAutorise((int)($r['autorise'] ?? 0)) ?>
                        </td>

                        <td class="text-nowrap">

                            <!-- BTN DETAILS -->
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                data-bs-target="#detailModal<?= (int)$r['id'] ?>">
                                Détails
                            </button>

                            <!-- BTN HISTORY -->
                            <a class="btn btn-sm btn-outline-dark"
                                href="<?= BASE_URL ?>/parent/palmares_history.php?eleve=<?= (int)$r['id'] ?>">
                                Historique
                            </a>

                        </td>

                    </tr>

                    <!-- MODAL DETAILS -->
                    <div class="modal fade" id="detailModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">

                        <div class="modal-dialog modal-xl modal-dialog-centered">

                            <div class="modal-content border-0 shadow">

                                <div class="modal-header">

                                    <h5 class="modal-title">

                                        <?= e($r['nom'].' '.$r['postnom'].' '.$r['prenom']) ?>

                                    </h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                                </div>

                                <div class="modal-body">

                                    <div class="row g-4">

                                        <div class="col-lg-4">

                                            <div class="card border-0 bg-light h-100">

                                                <div class="card-body">

                                                    <h6 class="mb-3">
                                                        Informations générales
                                                    </h6>

                                                    <div class="mb-3">

                                                        <div class="small text-muted">
                                                            Genre
                                                        </div>

                                                        <div class="fw-semibold">
                                                            <?= e($r['genre']) ?>
                                                        </div>

                                                    </div>

                                                    <div class="mb-3">

                                                        <div class="small text-muted">
                                                            Classe
                                                        </div>

                                                        <div class="fw-semibold">
                                                            <?= e($r['classe_desc']) ?>
                                                        </div>

                                                    </div>

                                                    <div class="mb-3">

                                                        <div class="small text-muted">
                                                            Cycle
                                                        </div>

                                                        <div class="fw-semibold">
                                                            <?= e($r['cycle_desc'] ?? '—') ?>
                                                        </div>

                                                    </div>

                                                    <div class="mb-3">

                                                        <div class="small text-muted">
                                                            Année scolaire
                                                        </div>

                                                        <div class="fw-semibold">
                                                            <?= e($r['anneeScolaire']) ?>
                                                        </div>

                                                    </div>

                                                    <div class="mb-3">

                                                        <div class="small text-muted">
                                                            Observation
                                                        </div>

                                                        <div class="fw-semibold">
                                                            <?= !empty($r['obs']) ? e($r['obs']) : '—' ?>
                                                        </div>

                                                    </div>

                                                    <div>
                                                        <?= badgeAutorise((int)($r['autorise'] ?? 0)) ?>
                                                    </div>

                                                </div>

                                            </div>

                                        </div>

                                        <div class="col-lg-8">

                                            <?php if (!empty($r['trimestre'])): ?>

                                            <div class="d-flex justify-content-between align-items-center mb-3">

                                                <div>

                                                    <h5 class="mb-0">
                                                        Dernier palmarès
                                                    </h5>

                                                    <div class="small text-muted">
                                                        <?= e($r['trimestre']) ?>
                                                    </div>

                                                </div>

                                                <span class="badge text-bg-primary fs-6">
                                                    <?= number_format((float)$r['percent'], 2, ',', ' ') ?> %
                                                </span>

                                            </div>

                                            <div class="row g-3">

                                                <!-- LANG -->
                                                <div class="col-md-4">

                                                    <div class="card shadow-sm border-0 h-100">

                                                        <div class="card-body">

                                                            <div class="small text-muted mb-1">
                                                                Langues
                                                            </div>

                                                            <div class="h5 mb-1">
                                                                <?= number_format((float)$r['lang'], 2, ',', ' ') ?>
                                                            </div>

                                                            <div class="small text-muted">
                                                                /
                                                                <?= number_format((float)$r['max_lang'], 2, ',', ' ') ?>
                                                            </div>

                                                            <?php
                                                            $langPercent = 0;

                                                            if ((float)$r['max_lang'] > 0) {
                                                                $langPercent = ((float)$r['lang'] / (float)$r['max_lang']) * 100;
                                                            }
                                                            ?>

                                                            <div class="progress mt-2" style="height:8px;">
                                                                <div class="progress-bar bg-primary"
                                                                    style="width: <?= min(100, max(0, $langPercent)) ?>%;">
                                                                </div>
                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>

                                                <!-- MATH -->
                                                <div class="col-md-4">

                                                    <div class="card shadow-sm border-0 h-100">

                                                        <div class="card-body">

                                                            <div class="small text-muted mb-1">
                                                                Mathématiques
                                                            </div>

                                                            <div class="h5 mb-1">
                                                                <?= number_format((float)$r['math'], 2, ',', ' ') ?>
                                                            </div>

                                                            <div class="small text-muted">
                                                                /
                                                                <?= number_format((float)$r['max_math'], 2, ',', ' ') ?>
                                                            </div>

                                                            <?php
                                                            $mathPercent = 0;

                                                            if ((float)$r['max_math'] > 0) {
                                                                $mathPercent = ((float)$r['math'] / (float)$r['max_math']) * 100;
                                                            }
                                                            ?>

                                                            <div class="progress mt-2" style="height:8px;">
                                                                <div class="progress-bar bg-success"
                                                                    style="width: <?= min(100, max(0, $mathPercent)) ?>%;">
                                                                </div>
                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>

                                                <!-- CULTURE -->
                                                <div class="col-md-4">

                                                    <div class="card shadow-sm border-0 h-100">

                                                        <div class="card-body">

                                                            <div class="small text-muted mb-1">
                                                                Culture générale
                                                            </div>

                                                            <div class="h5 mb-1">
                                                                <?= number_format((float)$r['cult'], 2, ',', ' ') ?>
                                                            </div>

                                                            <div class="small text-muted">
                                                                /
                                                                <?= number_format((float)$r['max_cult'], 2, ',', ' ') ?>
                                                            </div>

                                                            <?php
                                                            $cultPercent = 0;

                                                            if ((float)$r['max_cult'] > 0) {
                                                                $cultPercent = ((float)$r['cult'] / (float)$r['max_cult']) * 100;
                                                            }
                                                            ?>

                                                            <div class="progress mt-2" style="height:8px;">
                                                                <div class="progress-bar bg-warning"
                                                                    style="width: <?= min(100, max(0, $cultPercent)) ?>%;">
                                                                </div>
                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>

                                                <!-- TOTAL -->
                                                <div class="col-12">

                                                    <div class="card border-0 bg-dark text-white">

                                                        <div class="card-body">

                                                            <div class="row align-items-center">

                                                                <div class="col-md-4">

                                                                    <div class="small text-white-50">
                                                                        Total obtenu
                                                                    </div>

                                                                    <div class="display-6 fw-bold">
                                                                        <?= number_format((float)$r['total'], 2, ',', ' ') ?>
                                                                    </div>

                                                                </div>

                                                                <div class="col-md-4">

                                                                    <div class="small text-white-50">
                                                                        Maximum
                                                                    </div>

                                                                    <div class="display-6 fw-bold">
                                                                        <?= number_format((float)$r['max_total'], 2, ',', ' ') ?>
                                                                    </div>

                                                                </div>

                                                                <div class="col-md-4">

                                                                    <div class="small text-white-50">
                                                                        Pourcentage
                                                                    </div>

                                                                    <div class="display-6 fw-bold">
                                                                        <?= number_format((float)$r['percent'], 2, ',', ' ') ?>
                                                                        %
                                                                    </div>

                                                                </div>

                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>

                                            </div>

                                            <?php else: ?>

                                            <div class="alert alert-warning mb-0">
                                                Aucun palmarès enregistré pour cet élève.
                                            </div>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                </div>

                                <div class="modal-footer">

                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        Fermer
                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                    <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>