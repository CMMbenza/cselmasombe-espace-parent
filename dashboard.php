<?php
// /parent/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_parent();

// Inclut et initialise $ANNEE_SCOLAIRE_EN_COURS
require_once __DIR__ . '/get_annee_scolaire_enours.php';

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

// --- 1. Utilisation directe de l'année scolaire active ---
$activeYear = $ANNEE_SCOLAIRE_EN_COURS ?? null;

// Normalisation au format strict YYYY-YYYY si l'année récupérée est sur 4 chiffres
if ($activeYear && preg_match('/^\d{4}$/', (string)$activeYear)) {
    $y = (int)$activeYear;
    $activeYear = $y . '-' . ($y + 1);
} elseif (!$activeYear) {
    $y = (int)date('Y');
    $activeYear = $y . '-' . ($y + 1);
}

// --- Infos ménage de base (Montant à payer = montantAPayer + montantAPayerFC) ---
$totalAnnuelAPayer = 0.0;
try {
    $st = $pdo->prepare("
        SELECT (COALESCE(montantAPayer, 0) + COALESCE(montantAPayerFC, 0)) AS total_a_payer 
        FROM menage 
        WHERE id = :mid 
        LIMIT 1
    ");
    $st->execute([':mid' => $menageId]);
    $totalAnnuelAPayer = (float)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $totalAnnuelAPayer = 0.0;
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

// --- Situation FRAIS SCOLAIRES (paiements strictes) ---
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

// --- Total DIVERS payé ---
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

// Total Payé global (Frais scolaires + Frais divers)
$totalAnnuelPaye = $school_paid + $totalDiversPayer;

// Soustraction directe pour trouver le reste
$totalAnnuelReste = max($totalAnnuelAPayer - $totalAnnuelPaye, 0.0);

/* ============================================================
   LOGIQUE "Montant par tranche / Annuel à payer"
   ============================================================ */
$diversAPayerRef          = 0.0;
$apayerByTranche          = [];
$paidByTranche            = [];
$resteByTranche           = [];
$tranchesNums             = [];
$nums                     = [];

try {
    if ($nbChildren > 0) {
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

        $trancheOneKey = 1;
        if (!empty($tranchesNums)) {
            $numsTmp = array_keys($tranchesNums);
            $numsTmp = array_map('intval', $numsTmp);
            sort($numsTmp);
            $trancheOneKey = in_array(1, $numsTmp, true) ? 1 : (int)$numsTmp[0];
        }

        if (!isset($apayerByTranche[$trancheOneKey])) {
            $apayerByTranche[$trancheOneKey] = 0.0;
        }
        $apayerByTranche[$trancheOneKey] += (float)$diversAPayerRef;
        $tranchesNums[$trancheOneKey] = true;

        $nums = array_keys($tranchesNums);
        $nums = array_map('intval', $nums);
        sort($nums);

        $pool = $totalAnnuelPaye;
        foreach ($nums as $n) {
            $due  = (float)($apayerByTranche[$n] ?? 0.0);
            $pay  = min($pool, $due);
            $paidByTranche[$n]  = $pay;
            $resteByTranche[$n] = max($due - $pay, 0.0);
            $pool -= $pay;
        }
    }
} catch (Throwable $e) { /* ignore */ }


/* ============================================================
   2. REQUÊTES PAIEMENTS SÉPARÉES & FUSION PHP
   ============================================================ */

// 1. Paiements scolaires
$lastPaymentsSchool = [];
try {
    $st = $pdo->prepare("
        SELECT montantAPayer, montantPayer, resteAPayer, observation, dateCreated
        FROM paiement
        WHERE menage = :mid AND anneeScolaire = :yr
        ORDER BY dateCreated DESC
        LIMIT 1
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $lastPaymentsSchool = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastPaymentsSchool = [];
}

// 2. Paiements divers / connexes
$lastPaymentsDivers = [];
try {
    $st = $pdo->prepare("
        SELECT 
        'Frais connexe' AS type_frais, 
        montantAPayer, 
        montantPayer, 
        resteAPayer, 
        observation, 
        dateCreated
        FROM paiement_divers
        WHERE menage = :mid AND anneeScolaire = :yr
        ORDER BY dateCreated DESC
        LIMIT 1
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $lastPaymentsDivers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $lastPaymentsDivers = [];
}

// 3. Fusion et tri côté PHP (Contourne l'erreur de collation MySQL)
$schoolFormatted = array_map(function($item) {
    $item['type_frais'] = 'Frais scolaire';
    return $item;
}, $lastPaymentsSchool);

$combinedPayments = array_merge($schoolFormatted, $lastPaymentsDivers);

usort($combinedPayments, function($a, $b) {
    return strtotime($b['dateCreated']) <=> strtotime($a['dateCreated']);
});

$allLastPayments = array_slice($combinedPayments, 0, 5);


/* ============================================================
   3. ANNONCES (3 dernières annonces)
   ============================================================ */
$annonces = [];
try {
    $st = $pdo->query("
        SELECT a.id, a.titre, a.contenu, a.visible_a, a.created_at
        FROM annonces a
        WHERE a.visible_a IN ('parents','tous')
        ORDER BY a.created_at DESC
        LIMIT 3
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
                $salutation = ($heure >= 5 && $heure < 18) ? "Bonjour" : "Bonsoir";
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

        <!-- COLONNE GAUCHE -->
        <div class="col-lg-12">

            <!-- CARDS STATS -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">👨‍👩‍👧 Enfants</div>
                            <div class="h4 fw-bold"><?= (int)$nbChildren ?></div>
                            <small>Total dans votre ménage</small>
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
                            <small>Montant déjà payé</small>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body text-center">
                            <div class="text-muted small">⛔ Reste</div>
                            <div class="h4 fw-bold text-danger"><?= fmt_money($totalAnnuelReste) ?> $</div>
                            <small>Montant restant à payer</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="col-lg-8">
            <!-- TRANCHES -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="card-header bg-white border-0 p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="small text-muted">
                            <strong>📊 Montant par tranche</strong> <br>
                            Scolarité + frais connexes intégrés dans la première tranche
                        </div>
                        <div><a href="finances.php" class="btn btn-primary btn-sm">Voir +</a></div>
                    </div>
                </div>

                <div class="card-body">
                    <?php if (empty($nums)): ?>
                    <div class="alert alert-info mb-0">Aucune tranche disponible.</div>
                    <?php else: ?>
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
                                    <td class="fw-semibold">Tranche <?= (int)$num ?></td>
                                    <td class="text-end"><?= fmt_money($apayerByTranche[$num]) ?> $</td>
                                    <td class="text-end text-success"><?= fmt_money($paidByTranche[$num]) ?> $</td>
                                    <td class="text-end text-danger"><?= fmt_money($resteByTranche[$num]) ?> $</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TABLEAU FUSIONNÉ : DERNIERS PAIEMENTS -->
            <div class="card border-0 shadow-sm rounded-4 mb-3">
                <div class="d-flex justify-content-between align-items-center card-header bg-white border-0">
                    <strong>💳 Derniers paiements</strong>
                    <a href="finances.php" class="btn btn-primary btn-sm">Voir +</a>
                </div>

                <div class="card-body p-0 small">
                    <?php if (empty($allLastPayments)): ?>
                    <div class="p-3 text-muted">Aucun paiement enregistré.</div>
                    <?php else: ?>
                    <div class="table-responsive p-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type de frais</th>
                                    <th class="text-end">A Payer</th>
                                    <th class="text-end">Payé</th>
                                    <th class="text-end">Reste</th>
                                    <th class="text-end">Obs.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allLastPayments as $p): ?>
                                <tr>
                                    <td class="text-muted"><?= e($p['dateCreated']) ?></td>
                                    <td>
                                        <span
                                            class="badge <?= $p['type_frais'] === 'Frais scolaire' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                            <?= e($p['type_frais']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-dark fw-semibold"><?= fmt_money($p['montantAPayer']) ?> $
                                    </td>
                                    <td class="text-end text-success fw-semibold"><?= fmt_money($p['montantPayer']) ?> $
                                    </td>
                                    <td class="text-end text-danger"><?= fmt_money($p['resteAPayer']) ?> $</td>
                                    <td class="text-end text-muted"><?= e($p['observation']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- COLONNE DROITE -->
        <div class="col-lg-4">

            <!-- MES ENFANTS -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0">
                    <strong>👨‍👩‍👧 Mes enfants</strong>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($children as $c): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold small"><?= e($c['nom'].' '.$c['postnom']) ?></div>
                                <div class="text-muted small"><?= e($c['classe_desc']) ?> <?= e($c['cycle_desc']) ?>
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
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="d-flex justify-content-between align-items-center card-header bg-white border-0">
                        <strong>📢 Annonces</strong>
                        <a href="annonces.php" class="btn btn-secondary btn-sm">Voir +</a>
                    </div>
                    <div class="card-body small">
                        <?php if (empty($annonces)): ?>
                        <div class="text-muted">Aucune annonce disponible.</div>
                        <?php else: ?>
                        <?php foreach ($annonces as $a): ?>
                        <div class="mb-2 pb-2 border-bottom">
                            <div class="fw-semibold"><?= e($a['titre']) ?></div>
                            <div class="text-muted small"><?= e($a['created_at']) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>