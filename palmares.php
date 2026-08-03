<?php
// /parent/palmares.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();

require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$mid = (int)$_SESSION['parent']['id'];

/*
|--------------------------------------------------------------------------
| Récupération des enfants + dernier palmarès + Calcul dynamique du Rang
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
        pt.created_at,

        -- Calcul de la place en fonction du pourcentage dans la même classe et le même trimestre
        (
            SELECT COUNT(*) + 1
            FROM palmares_trimestre p_rank
            JOIN eleve e_rank ON e_rank.id = p_rank.eleve_id
            WHERE e_rank.classe = e.classe
              AND p_rank.trimestre = pt.trimestre
              AND p_rank.percent > pt.percent
        ) AS place

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

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h5 mb-0">Mes enfants</h1>
            <div class="text-muted small">Dernier palmarès disponible de chaque élève.</div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Cycle</th>
                        <th>Trimestre</th>
                        <th>Total</th>
                        <th>%</th>
                        <th class="text-center">Rang</th>
                        <th>Observation</th>
                        <th>Autorisation</th>
                        <th width="180"></th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4">
                            <em>Aucun enfant trouvé.</em>
                        </td>
                    </tr>
                    <?php else: ?>

                    <?php foreach ($rows as $r): ?>
                    <?php if ((int)($r['autorise'] ?? 0) === 1): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>

                        <td>
                            <div class="fw-semibold">
                                <?= e($r['nom'].' '.$r['postnom'].' '.$r['prenom']) ?>
                            </div>
                            <div class="small text-muted">
                                <?= e($r['genre']) ?>
                            </div>
                        </td>

                        <td><?= e($r['classe_desc']) ?></td>

                        <td><?= e($r['cycle_desc'] ?? '—') ?></td>

                        <td>
                            <?php if (!empty($r['trimestre'])): ?>
                                <span class="badge text-bg-primary">
                                    <?= e($r['trimestre']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
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

                        <td class="text-center">
                            <?= formatRang(!empty($r['trimestre']) && $r['place'] !== null ? (int)$r['place'] : null) ?>
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

                        <td><?= badgeAutorise((int)$r['autorise']) ?></td>

                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#detailModal<?= (int)$r['id'] ?>">
                                Détails
                            </button>

                            <a class="btn btn-sm btn-outline-dark"
                                href="<?= BASE_URL ?>/palmares_history.php?eleve=<?= (int)$r['id'] ?>">
                                Historique
                            </a>
                        </td>
                    </tr>

                    <?php else: ?>
                    <tr>
                        <td colspan="11">
                            <div class="alert alert-danger mb-0 py-2">
                                Accès non autorisé pour cet élève.
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

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
                                                    <h6 class="mb-3 border-bottom pb-2">Informations générales</h6>

                                                    <div class="mb-3">
                                                        <div class="small text-muted">Genre</div>
                                                        <div class="fw-semibold"><?= e($r['genre']) ?></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <div class="small text-muted">Classe</div>
                                                        <div class="fw-semibold"><?= e($r['classe_desc']) ?></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <div class="small text-muted">Cycle</div>
                                                        <div class="fw-semibold"><?= e($r['cycle_desc'] ?? '—') ?></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <div class="small text-muted">Année scolaire</div>
                                                        <div class="fw-semibold"><?= e($r['anneeScolaire']) ?></div>
                                                    </div>

                                                    <div class="mb-3">
                                                        <div class="small text-muted">Observation</div>
                                                        <div class="fw-semibold"><?= !empty($r['obs']) ? e($r['obs']) : '—' ?></div>
                                                    </div>

                                                    <div>
                                                        <?= badgeAutorise((int)$r['autorise']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-lg-8">
                                            <?php if (!empty($r['trimestre'])): ?>
                                                <div class="row g-3 mb-3">
                                                    <div class="col-md-4">
                                                        <div class="p-3 bg-light rounded text-center border">
                                                            <small class="text-muted d-block mb-1">Pourcentage</small>
                                                            <span class="fs-4 fw-bold text-dark">
                                                                <?= number_format((float)$r['percent'], 2, ',', ' ') ?> %
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-3 bg-light rounded text-center border">
                                                            <small class="text-muted d-block mb-1">Total Obtenu</small>
                                                            <span class="fs-4 fw-bold text-primary">
                                                                <?= number_format((float)$r['total'], 2, ',', ' ') ?> / <?= number_format((float)$r['max_total'], 2, ',', ' ') ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-3 bg-light rounded text-center border border-warning">
                                                            <small class="text-muted d-block mb-1">Rang Calculé</small>
                                                            <div>
                                                                <?= formatRang($r['place'] !== null ? (int)$r['place'] : null) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- <div class="table-responsive">
                                                    <table class="table table-bordered table-sm align-middle text-center">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Domaine</th>
                                                                <th>Points Obtenus</th>
                                                                <th>Maximum</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr>
                                                                <td class="text-start fw-semibold">Langues</td>
                                                                <td><?= number_format((float)($r['lang'] ?? 0), 2, ',', ' ') ?></td>
                                                                <td><?= number_format((float)($r['max_lang'] ?? 0), 2, ',', ' ') ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-start fw-semibold">Mathématiques</td>
                                                                <td><?= number_format((float)($r['math'] ?? 0), 2, ',', ' ') ?></td>
                                                                <td><?= number_format((float)($r['max_math'] ?? 0), 2, ',', ' ') ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td class="text-start fw-semibold">Culture Générale</td>
                                                                <td><?= number_format((float)($r['cult'] ?? 0), 2, ',', ' ') ?></td>
                                                                <td><?= number_format((float)($r['max_cult'] ?? 0), 2, ',', ' ') ?></td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-warning mb-0">
                                                    Aucun palmarès enregistré pour cet élève.
                                                </div>
                                            <?php endif; ?> -->
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