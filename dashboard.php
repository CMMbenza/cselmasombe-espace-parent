<?php
// /parent/dashboard.php
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

// --- Sécurité/session ---
$menageId = (int)($_SESSION['parent']['id'] ?? 0);
if ($menageId <= 0) {
    header('Location: ' . BASE_URL . '/login.php'); exit;
}

// --- Déterminer l'année scolaire active ---
$activeYear = null;
try {
    $q = $pdo->query("SELECT annee_scolaire FROM annee_scolaire WHERE status='encours' ORDER BY id DESC LIMIT 1");
    $activeYear = $q->fetchColumn() ?: null;
} catch (Throwable $e) { /* ignore */ }

if (!$activeYear) {
    // Fallback: année la plus récente vue dans paiements / paiements_divers / menage
    $fallback = null;
    try {
        $q = $pdo->query("
            SELECT MAX(anneeScolaire) FROM (
                SELECT anneeScolaire FROM paiement WHERE anneeScolaire IS NOT NULL
                UNION ALL
                SELECT anneeScolaire FROM paiement_divers WHERE anneeScolaire IS NOT NULL
                UNION ALL
                SELECT anneeScolaire FROM menage WHERE anneeScolaire IS NOT NULL
            ) t
        ");
        $fallback = $q->fetchColumn() ?: null;
    } catch (Throwable $e) { /* ignore */ }
    $activeYear = $fallback ?: date('Y').'-'.((int)date('Y')+1);
}

// --- Infos ménage de base (scolarité à payer au niveau ménage) ---
$school_due = 0.0;
try {
    $st = $pdo->prepare("SELECT COALESCE(montantAPayer,0) FROM menage WHERE id=:mid LIMIT 1");
    $st->execute([':mid'=>$menageId]);
    $school_due = (float)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $school_due = 0.0;
}

// --- Enfants du ménage (avec classe + cycle) ---
$children   = [];
$nbChildren = 0;
try {
    $st = $pdo->prepare("
        SELECT e.id, e.nom, e.postnom, e.prenom, e.classe,
               c.description AS classe_desc,
               cy.id AS cycle_id,
               cy.description AS cycle_desc
        FROM eleve e
        LEFT JOIN classe c ON c.id = e.classe
        LEFT JOIN cycle  cy ON cy.id = c.cycle
        WHERE e.menage = :mid
        ORDER BY e.nom, e.postnom, e.prenom
    ");
    $st->execute([':mid'=>$menageId]);
    $children   = $st->fetchAll(PDO::FETCH_ASSOC);
    $nbChildren = count($children);
} catch (Throwable $e) {
    $children   = [];
    $nbChildren = 0;
}

// --- Situation FRAIS SCOLAIRES (paiements) ---
$school_paid = 0.0;
try {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(montantPayer),0)
        FROM paiement
        WHERE menage = :mid AND anneeScolaire = :yr
    ");
    $st->execute([':mid'=>$menageId, ':yr'=>$activeYear]);
    $school_paid = (float)$st->fetchColumn();
} catch (Throwable $e) {
    $school_paid = 0.0;
}

// Reste scolarité "simple" pour les cartes de synthèse
$school_rest = max(0.0, $school_due - $school_paid);

// --- Total DIVERS payé (réel) pour cette année ---
$totalDiversPayer = 0.0;
try {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(montantPayer),0)
        FROM paiement_divers
        WHERE menage = :mid AND anneeScolaire = :yr
    ");
    $st->execute([':mid'=>$menageId, ':yr'=>$activeYear]);
    $totalDiversPayer = (float)$st->fetchColumn();
} catch (Throwable $e) {
    $totalDiversPayer = 0.0;
}

/* ============================================================
   LOGIQUE "Montant par tranche (réf. cycles des élèves)"
   — À PAYER / PAYÉ (ANNUEL) / RESTE
   (scolarité + frais connexes injectés dans tranche 1)
   ============================================================ */
$diversAPayerRef          = 0.0;
$apayerByTranche          = [];
$apayerByTrancheScolOnly  = [];
$paidByTranche            = [];
$resteByTranche           = [];
$tranchesNums             = [];
$nums                     = [];
$trancheOneKey            = 1;
$totalAPayerToutesTranches= 0.0;
$totalAnnuelAPayer        = 0.0;
$totalAnnuelPaye          = 0.0;
$totalAnnuelReste         = 0.0;
$pool                     = 0.0;

try {
    if ($nbChildren > 0) {
        // 1) Montant de référence DIVERS (depuis scolarite / description)
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(s2.montant),0) AS total_divers_tarif
            FROM eleve e
            JOIN classe cl ON e.classe = cl.id
            JOIN cycle  cy ON cl.cycle  = cy.id
            JOIN scolarite s2 ON s2.cycle = cy.id AND s2.anneeScolaire = :yr
            WHERE e.menage = :mid
              AND (LOWER(s2.description) LIKE '%diver%' OR LOWER(s2.description) LIKE '%connex%')
        ");
        $st->execute([':yr'=>$activeYear, ':mid'=>$menageId]);
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
            JOIN scolarite s ON s.cycle = cy.id AND s.anneeScolaire = :yr
            JOIN tranche   t ON t.frais_id = s.id
            WHERE e.menage = :mid
            GROUP BY t.numero_tranche
            ORDER BY t.numero_tranche
        ");
        $st->execute([':yr'=>$activeYear, ':mid'=>$menageId]);
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

        // 7) Synthèse annuelle (scol + divers)
        $totalAnnuelAPayer = $totalAPayerToutesTranches;
        $totalAnnuelPaye   = $school_paid + $totalDiversPayer;
        $totalAnnuelReste  = max($totalAnnuelAPayer - $totalAnnuelPaye, 0.0);

        // 8) Répartition du payé par tranches (cascade)
        $paidByTranche  = [];
        $resteByTranche = [];
        $pool = $totalAnnuelPaye;

        foreach ($nums as $n) {
            $due  = (float)($apayerByTranche[$n] ?? 0.0);
            $pay  = min($pool, $due);
            $paidByTranche[$n]  = $pay;
            $resteByTranche[$n] = max($due - $pay, 0.0);
            $pool -= $pay;
        }
    }
} catch (Throwable $e) {
    // On laisse simplement les valeurs à 0 en cas d'erreur
}

// --- Derniers paiements scolarité ---
$lastPayments = [];
try {
    $st = $pdo->prepare("
        SELECT id, montantAPayer, montantPayer, resteAPayer, observation, dateCreated
        FROM paiement
        WHERE menage = :mid AND anneeScolaire = :yr
        ORDER BY dateCreated DESC, id DESC
        LIMIT 5
    ");
    $st->execute([':mid'=>$menageId, ':yr'=>$activeYear]);
    $lastPayments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastPayments = [];
}

// --- Annonces parents / tous ---
$annonces = [];
try {
    $st = $pdo->query("
        SELECT a.id, a.titre, a.contenu, a.visible_a, a.created_at
        FROM annonces a
        WHERE a.visible_a IN ('parents','tous')
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $annonces = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $annonces = [];
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<div class="container mb-4">

    <!-- HEADER -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

        <div>
            <?php
                date_default_timezone_set('Africa/Kinshasa');

                $heure = (int) date('H');

                if ($heure >= 5 && $heure < 18) {
                    $salutation = "Bonjour";
                } else {
                    $salutation = "Bonsoir";
                }
            ?>
            <h1 class="h5 mb-1 fw-bold">Tableau de bord — Parent</h1>
            <h6>👋 <?= $salutation ?> — <?= e($_SESSION['parent']['noms'] ?? '') ?></h6>
            <p class="small text-muted mb-0">
                Vue d’ensemble de la situation de vos enfants et de vos paiements.
            </p>
        </div>

        <div class="small">
            Année scolaire :
            <span class="badge bg-light text-dark border">
                <?= e($activeYear) ?>
            </span>
        </div>

    </div>

    <div class="row g-3">

        <!-- ========================= -->
        <!-- COLONNE GAUCHE -->
        <!-- ========================= -->
        <div class="col-lg-8">

            <!-- ===== CARDS STATS ===== -->
            <div class="row g-3 mb-3">

                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">👨‍👩‍👧 Enfants</div>
                            <div class="h4 fw-bold"><?= (int)$nbChildren ?></div>
                            <small>Nombre total dans votre ménage</small>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">💰 À payer</div>
                            <div class="h4 fw-bold text-dark"><?= fmt_money($totalAnnuelAPayer) ?> $</div>
                            <small>Montant global (ménage)</small>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">✅ Payé</div>
                            <div class="h4 fw-bold text-success"><?= fmt_money($totalAnnuelPaye) ?> $</div>
                            <small>Montant déjà payé durant l’année</small>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">⛔ Reste</div>
                            <div class="h4 fw-bold text-danger"><?= fmt_money($totalAnnuelReste) ?> $</div>
                            <small>Montant à payer durant l’année</small>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ===== TRANCHE ===== -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">

                <div class="card-header bg-white border-0 p-3">
                    <div class="d-flex justify-content-between align-items-center card-header bg-white border-0">
                        <div class="small text-muted">
                            <strong>📊 Montant par tranche</strong> <br>
                            Scolarité + frais connexes intégrés dans la première tranche
                        </div>
                        <div class="div"><a href="finances.php" class="btn btn-primary btn-sm">Voir +</a></div>
                    </div>
                </div>

                <div class="card-body">

                    <?php if (empty($nums)): ?>

                    <div class="alert alert-info mb-0">
                        Aucune tranche disponible.
                    </div>

                    <?php else: ?>

                    <!-- SUMMARY -->
                    <div class="row g-2 mb-3">

                        <div class="col-md-4">
                            <div class="p-2 border rounded-3 bg-light text-center">
                                <div class="small text-muted">À payer</div>
                                <div class="fw-bold"><?= fmt_money($totalAnnuelAPayer) ?> $</div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="p-2 border rounded-3 bg-light text-center">
                                <div class="small text-muted">Payé</div>
                                <div class="fw-bold text-primary"><?= fmt_money($totalAnnuelPaye) ?> $</div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="p-2 border rounded-3 bg-light text-center">
                                <div class="small text-muted">Reste</div>
                                <div class="fw-bold text-danger"><?= fmt_money($totalAnnuelReste) ?> $</div>
                            </div>
                        </div>

                    </div>

                    <!-- TABLE -->
                    <div class="table-responsive">

                        <table class="table table-sm align-middle">

                            <thead class="table-light">
                                <tr>
                                    <th>Tranche</th>
                                    <th class="text-end">À payer</th>
                                    <th class="text-end">Payé</th>
                                    <th class="text-end">Reste</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($nums as $num): ?>
                                <tr>

                                    <td class="fw-semibold">
                                        Tranche <?= (int)$num ?>
                                    </td>

                                    <td class="text-end">
                                        <?= fmt_money($apayerByTranche[$num]) ?> $
                                    </td>

                                    <td class="text-end text-success">
                                        <?= fmt_money($paidByTranche[$num]) ?> $
                                    </td>

                                    <td class="text-end text-danger">
                                        <?= fmt_money($resteByTranche[$num]) ?> $
                                    </td>

                                </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>

                    </div>

                    <?php endif; ?>

                </div>

            </div>

            <!-- ===== ENFANTS / ANNONCES ===== -->
            <div class="row g-3">

                <!-- PAIMENTS -->
                <div class="col-md-12">

                    <div class="card border-0 shadow-sm rounded-4 h-100">

                        <div class="d-flex justify-content-between align-items-center card-header bg-white border-0">
                            <strong>💳 Derniers paiements</strong>
                            <a href="finances.php" class="btn btn-primary btn-sm">Voir +</a>
                        </div>

                        <div class="card-body p-0 small">

                            <?php if (!$lastPayments): ?>

                            <div class="p-3 text-muted">
                                Aucun paiement récent.
                            </div>

                            <?php else: ?>

                            <!-- TABLE -->
                            <div class="table-responsive p-3">

                                <table class="table table-sm align-middle mb-0">

                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-end">Montant</th>
                                            <th class="text-end">Reste</th>
                                            <th class="text-end">Obs.</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($lastPayments as $p): ?>

                                        <tr>

                                            <td class="text-muted">
                                                <?= e($p['dateCreated']) ?>
                                            </td>

                                            <td class="text-end text-success fw-semibold">
                                                <?= fmt_money($p['montantPayer']) ?> $
                                            </td>

                                            <td class="text-end text-danger">
                                                <?= fmt_money($p['resteAPayer']) ?> $
                                            </td>
                                            <td class="text-end text-danger">
                                                <?= e($p['observation']) ?>
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
            </div>

        </div>

        <!-- ========================= -->
        <!-- COLONNE DROITE -->
        <!-- ========================= -->
        <div class="col-lg-4">

            <div class="card border-0 shadow-sm rounded-4">

                <div class="card-header bg-white border-0">
                    <strong>👨‍👩‍👧 Mes enfants</strong>
                </div>

                <div class="card-body p-0">

                    <div class="list-group list-group-flush">

                        <?php foreach ($children as $c): ?>

                        <div class="list-group-item d-flex justify-content-between align-items-center">

                            <div>
                                <div class="fw-semibold small">
                                    <?= e($c['nom'].' '.$c['postnom']) ?>
                                </div>
                                <div class="text-muted small">
                                    <?= e($c['classe_desc']) ?> <?= e($c['cycle_desc']) ?>
                                </div>
                            </div>

                            <a class="btn btn-sm btn-primary"
                                href="<?= BASE_URL ?>/eleve/switch.php?eleve_id=<?= (int)$c['id'] ?>">
                                Se connecter
                            </a>
                        </div>

                        <?php endforeach; ?>

                    </div>
                </div>
            </div>

            <!-- ANNONCES -->
            <div class="mt-3">
                <div class="card">
                    <div class="card border-0 shadow-sm rounded-4 h-100">

                        <div class="d-flex justify-content-between align-items-center card-header bg-white border-0">
                            <strong>📢 Annonces</strong>
                            <a href="annonces.php" class="btn btn-secondary btn-sm">Voir +</a>
                        </div>

                        <div class="card-body small">
                            <?php foreach ($annonces as $a): ?>
                            <div class="mb-2 pb-2 border-bottom">
                                <div class="fw-semibold"><?= e($a['titre']) ?></div>
                                <div class="text-muted small"><?= e($a['created_at']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/layout/footer.php'; ?>