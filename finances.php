<?php
// /parent/finances.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();
require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

// Helpers
if (!function_exists('fmt_money')) {
    function fmt_money($n) {
        return number_format((float)$n, 2, ',', ' ');
    }
}
if (!function_exists('e')) {
    function e($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// --- Sécurité / session ---
$menageId = (int)($_SESSION['parent']['id'] ?? 0);
if ($menageId <= 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// On suppose que $pdo est disponible via les includes (config global)
global $pdo;

// --- Déterminer l'année scolaire active ---
$activeYear = null;
try {
    $q = $pdo->query("
        SELECT annee_scolaire 
        FROM annee_scolaire 
        WHERE status = 'encours' 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $activeYear = $q->fetchColumn() ?: null;
} catch (Throwable $e) {
    $activeYear = null;
}

if (!$activeYear) {
    // Fallback: année la plus récente vue dans paiements / paiements_divers / menage
    $fallback = null;
    try {
        $q = $pdo->query("
            SELECT MAX(anneeScolaire) FROM (
                SELECT anneeScolaire FROM paiement       WHERE anneeScolaire IS NOT NULL
                UNION ALL
                SELECT anneeScolaire FROM paiement_divers WHERE anneeScolaire IS NOT NULL
                UNION ALL
                SELECT anneeScolaire FROM menage         WHERE anneeScolaire IS NOT NULL
            ) t
        ");
        $fallback = $q->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $fallback = null;
    }
    $activeYear = $fallback ?: (date('Y') . '-' . ((int)date('Y') + 1));
}

// --- Total scolarité payée (réel) pour cette année ---
$school_paid = 0.0;
try {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(montantPayer), 0)
        FROM paiement
        WHERE menage = :mid AND anneeScolaire = :yr
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $school_paid = (float)$st->fetchColumn();
} catch (Throwable $e) {
    $school_paid = 0.0;
}

// --- Total DIVERS payé (réel) pour cette année ---
$totalDiversPayer = 0.0;
try {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(montantPayer), 0)
        FROM paiement_divers
        WHERE menage = :mid AND anneeScolaire = :yr
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $totalDiversPayer = (float)$st->fetchColumn();
} catch (Throwable $e) {
    $totalDiversPayer = 0.0;
}

/* ============================================================
   LOGIQUE "Montant par tranche (réf. cycles des élèves)"
   — À PAYER / PAYÉ (ANNUEL) / RESTE
   (scolarité + frais connexes injectés dans tranche 1)
   ============================================================ */
$diversAPayerRef           = 0.0;
$apayerByTranche           = [];
$apayerByTrancheScolOnly   = [];
$paidByTranche             = [];
$resteByTranche            = [];
$tranchesNums              = [];
$nums                      = [];
$trancheOneKey             = 1;
$totalAPayerToutesTranches = 0.0;
$totalAnnuelAPayer         = 0.0;
$totalAnnuelPaye           = 0.0;
$totalAnnuelReste          = 0.0;
$pool                      = 0.0;

try {
    // 1) Montant de référence DIVERS (depuis scolarite / description)
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(s2.montant), 0) AS total_divers_tarif
        FROM eleve e
        JOIN classe cl ON e.classe = cl.id
        JOIN cycle  cy ON cl.cycle  = cy.id
        JOIN scolarite s2 
          ON s2.cycle = cy.id 
         AND s2.anneeScolaire = :yr
        WHERE e.menage = :mid
          AND (LOWER(s2.description) LIKE '%diver%' OR LOWER(s2.description) LIKE '%connex%')
    ");
    $st->execute([':yr' => $activeYear, ':mid' => $menageId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $diversAPayerRef = $row ? (float)$row['total_divers_tarif'] : 0.0;

    // 2) Montant à payer par tranche (scolaire)
    $st = $pdo->prepare("
        SELECT 
            t.numero_tranche AS num,
            SUM(t.montant)   AS total_tranche
        FROM eleve e
        JOIN classe cl ON e.classe = cl.id
        JOIN cycle  cy ON cl.cycle  = cy.id
        JOIN scolarite s 
          ON s.cycle = cy.id 
         AND s.anneeScolaire = :yr
        JOIN tranche t 
          ON t.frais_id = s.id
        WHERE e.menage = :mid
        GROUP BY t.numero_tranche
        ORDER BY t.numero_tranche
    ");
    $st->execute([':yr' => $activeYear, ':mid' => $menageId]);
    $rowsTr = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rowsTr as $r) {
        $num = (int)$r['num'];
        $apayerByTranche[$num] = (float)$r['total_tranche'];
        $tranchesNums[$num]    = true;
    }

    // 3) Copie scolaire uniquement
    $apayerByTrancheScolOnly = $apayerByTranche;

    if (!is_array($apayerByTranche)) $apayerByTranche = [];
    if (!is_array($tranchesNums))    $tranchesNums    = [];

    // 4) Première tranche (1 si existe, sinon la plus petite)
    $trancheOneKey = 1;
    if (!empty($tranchesNums)) {
        $numsTmp = array_keys($tranchesNums);
        $numsTmp = array_map('intval', $numsTmp);
        sort($numsTmp);
        $trancheOneKey = in_array(1, $numsTmp, true) ? 1 : (int)$numsTmp[0];
    }
    $trancheOneKey = (int)$trancheOneKey;

    // 5) Injection des DIVERS dans la première tranche
    if (!isset($apayerByTranche[$trancheOneKey])) {
        $apayerByTranche[$trancheOneKey] = 0.0;
    }
    $apayerByTranche[$trancheOneKey] += (float)$diversAPayerRef;
    $tranchesNums[$trancheOneKey] = true;

    // 6) Ordonner & total "à payer"
    $nums = array_keys($tranchesNums);
    $nums = array_map('intval', $nums);
    sort($nums);

    $totalAPayerToutesTranches = 0.0;
    foreach ($nums as $n) {
        $totalAPayerToutesTranches += (float)($apayerByTranche[$n] ?? 0.0);
    }

    // 7) Synthèse annuelle (scolarité + divers)
    $totalAnnuelAPayer = $totalAPayerToutesTranches;
    $totalAnnuelPaye   = $school_paid + $totalDiversPayer;
    $totalAnnuelReste  = max($totalAnnuelAPayer - $totalAnnuelPaye, 0.0);

    // 8) Répartition du payé par tranches (cascade)
    $paidByTranche  = [];
    $resteByTranche = [];
    $pool           = $totalAnnuelPaye;

    foreach ($nums as $n) {
        $due  = (float)($apayerByTranche[$n] ?? 0.0);
        $pay  = min($pool, $due);
        $paidByTranche[$n]  = $pay;
        $resteByTranche[$n] = max($due - $pay, 0.0);
        $pool -= $pay;
    }
} catch (Throwable $e) {
    // On laisse simplement les valeurs à 0 en cas d'erreur
}

// --- Derniers paiements scolarité ---
$lastPayments = [];
try {
    $st = $pdo->prepare("
        SELECT 
            id, 
            montantAPayer, 
            montantPayer, 
            resteAPayer, 
            observation, 
            dateCreated
        FROM paiement
        WHERE menage = :mid 
          AND anneeScolaire = :yr
        ORDER BY dateCreated DESC, id DESC
        LIMIT 5
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $lastPayments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastPayments = [];
}
?>
<div class="container mb-4">
    <!-- Titre + année -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h5 mb-1">Situation financière — Parent</h1>
        </div>
        <div class="small text-muted">
            Année scolaire :
            <span class="badge bg-light text-dark border"><?= e($activeYear) ?></span>
        </div>
    </div>

    <div class="row">
        <!-- Colonne principale -->
        <div class="col-lg-12 col-sm-12">

            <!-- Montant par tranche -->
            <div class="card shadow-sm mb-3 border-0">
                <div class="card-header bg-white border-0 pb-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h6 mb-0">Montant par tranche</h2>
                            <small class="text-muted">
                                Référence : cycles des élèves — Scolarité + frais connexes intégrés en Tranche 1.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="card-body pt-2">
                    <?php if (empty($nums)): ?>
                        <div class="alert alert-info mb-0">
                            Aucune tranche trouvée pour vos enfants sur l’année
                            <strong><?= e($activeYear) ?></strong>.
                        </div>
                    <?php else: ?>
                        <!-- Bandeau synthèse annuelle -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted">Montant annuel à payer</div>
                                    <div class="fw-semibold">
                                        <?= fmt_money($totalAnnuelAPayer) ?> $
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted">Montant payé (annuel)</div>
                                    <div class="fw-semibold text-primary">
                                        <?= fmt_money($totalAnnuelPaye) ?> $
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted">Reste (annuel)</div>
                                    <div class="fw-semibold text-danger">
                                        <?= fmt_money($totalAnnuelReste) ?> $
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tranche</th>
                                        <th class="text-end">À payer</th>
                                        <th class="text-end">Payé (alloué)</th>
                                        <th class="text-end">Reste</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $totPaidAlloc = 0.0;
                                    $totReste     = 0.0;
                                    foreach ($nums as $num):
                                        $due  = (float)($apayerByTranche[$num] ?? 0.0);
                                        $pay  = (float)($paidByTranche[$num] ?? 0.0);
                                        $rest = (float)($resteByTranche[$num] ?? max($due - $pay, 0.0));
                                        $totPaidAlloc += $pay;
                                        $totReste     += $rest;

                                        $labelSuffix = '';
                                        if ((int)$num === (int)$trancheOneKey) {
                                            $scolOnly = (float)($apayerByTrancheScolOnly[$trancheOneKey] ?? 0.0);
                                            $labelSuffix =
                                                '<br><small class="text-muted">'
                                                . 'Dont frais connexes : ' . fmt_money($diversAPayerRef) . ' $ — '
                                                . 'Scolarité seule : ' . fmt_money($scolOnly) . ' $'
                                                . '</small>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>Tranche <?= (int)$num ?></strong>
                                            <?= $labelSuffix ?>
                                        </td>
                                        <td class="text-end"><?= fmt_money($due) ?> $</td>
                                        <td class="text-end <?= $rest == 0.0 ? 'text-success' : '' ?>">
                                            <?= fmt_money($pay) ?> $
                                        </td>
                                        <td class="text-end <?= $rest > 0.0 ? 'text-danger' : '' ?>">
                                            <?= fmt_money($rest) ?> $
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th class="text-end">Totaux :</th>
                                        <th class="text-end">
                                            <?= fmt_money($totalAPayerToutesTranches) ?> $
                                        </th>
                                        <th class="text-end">
                                            <?= fmt_money($totPaidAlloc) ?> $
                                        </th>
                                        <th class="text-end">
                                            <?= fmt_money($totReste) ?> $
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <?php if ($pool > 0.0): ?>
                            <div class="alert alert-success mt-3 mb-0">
                                Surplus payé non affecté (au-delà de toutes les tranches) :
                                <strong><?= fmt_money($pool) ?> $</strong>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Derniers paiements (scolarité) -->
            <div class="card shadow-sm h-100 border-0">
                <div class="card-header bg-white border-0 pb-2">
                    <strong>Derniers paiements (scolarité)</strong>
                </div>
                <div class="card-body table-responsive pt-2">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Payé</th>
                                <th class="text-end">Reste</th>
                                <th>Obs.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$lastPayments): ?>
                                <tr>
                                    <td colspan="4">
                                        <em>Aucun paiement récent.</em>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lastPayments as $p): ?>
                                    <tr>
                                        <td><?= e($p['dateCreated']) ?></td>
                                        <td class="text-end text-success">
                                            <?= fmt_money($p['montantPayer']) ?> $
                                        </td>
                                        <td class="text-end text-danger">
                                            <?= fmt_money($p['resteAPayer']) ?> $
                                        </td>
                                        <td class="text-truncate" style="max-width:180px;">
                                            <?= e((string)($p['observation'] ?: '—')) ?>
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

<?php require_once __DIR__ . '/layout/footer.php'; ?>
