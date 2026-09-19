<?php
// /parent/finances.php
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

global $pdo;

// --- 1. Utilisation de l'année scolaire active ---
$activeYear = $ANNEE_SCOLAIRE_EN_COURS ?? null;

if ($activeYear && preg_match('/^\d{4}$/', (string)$activeYear)) {
    $y = (int)$activeYear;
    $activeYear = $y . '-' . ($y + 1);
} elseif (!$activeYear) {
    $y = (int)date('Y');
    $activeYear = $y . '-' . ($y + 1);
}

// --- 2. Récupération des montants de référence configurés dans la table MENAGE ---
$fraisScolaireAPayer = 0.0;
$fraisConnexeAPayer  = 0.0;
try {
    $st = $pdo->prepare("
        SELECT COALESCE(montantAPayer, 0) AS scol, COALESCE(montantAPayerFC, 0) AS connexe 
        FROM menage 
        WHERE id = :mid 
        LIMIT 1
    ");
    $st->execute([':mid' => $menageId]);
    $mRow = $st->fetch(PDO::FETCH_ASSOC);
    if ($mRow) {
        $fraisScolaireAPayer = (float)$mRow['scol'];
        $fraisConnexeAPayer  = (float)$mRow['connexe'];
    }
} catch (Throwable $e) {
    $fraisScolaireAPayer = 0.0;
    $fraisConnexeAPayer  = 0.0;
}

// Total général à payer pour le ménage
$totalAnnuelAPayerCombined = $fraisScolaireAPayer + $fraisConnexeAPayer;

// --- 3. Total scolarité payée (Table: paiement) ---
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

// --- 4. Total DIVERS / connexes payé (Table: paiement_divers) ---
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

// Calculations des restes par type de frais
$resteFraisScolaire = max($fraisScolaireAPayer - $school_paid, 0.0);
$resteFraisConnexe  = max($fraisConnexeAPayer - $totalDiversPayer, 0.0);

// Total des paiements effectués et du reste général
$totalAnnuelPayeCombined  = $school_paid + $totalDiversPayer;
$totalAnnuelResteCombined = max($totalAnnuelAPayerCombined - $totalAnnuelPayeCombined, 0.0);

/* ============================================================
   HISTORIQUE 1 : PAIEMENTS FRAIS SCOLAIRES (Table `paiement`)
   ============================================================ */
$paymentsSchool = [];
try {
    $st = $pdo->prepare("
        SELECT id, montantAPayer, montantPayer, resteAPayer, observation, dateCreated
        FROM paiement
        WHERE menage = :mid AND anneeScolaire = :yr
        ORDER BY dateCreated DESC
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $paymentsSchool = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paymentsSchool = [];
}

/* ============================================================
   HISTORIQUE 2 : PAIEMENTS FRAIS CONNEXES (Table `paiement_divers`)
   ============================================================ */
$paymentsDivers = [];
try {
    $st = $pdo->prepare("
        SELECT id, type_frais, montantAPayer, montantPayer, resteAPayer, observation, dateCreated
        FROM paiement_divers
        WHERE menage = :mid AND anneeScolaire = :yr
        ORDER BY dateCreated DESC
    ");
    $st->execute([':mid' => $menageId, ':yr' => $activeYear]);
    $paymentsDivers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paymentsDivers = [];
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">

<style>
.card-stat {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important;
}

.icon-shape {
    width: 48px;
    height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
}

.table-custom thead th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 700;
    color: #6c757d;
    border-bottom-width: 1px;
}
</style>

<div class="container py-4 mb-5">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h1 class="h3 mb-1 fw-bold text-dark">💳 Situation financière du ménage</h1>
            <p class="text-muted small mb-0">Vue détaillée de la tarification et suivi complet de l'historique des
                règlements.</p>
        </div>
        <div class="mt-2 mt-md-0">
            <span class="badge bg-white text-dark border shadow-sm px-3 py-2 rounded-pill fs-6">
                <i class="bi bi-calendar-event text-primary me-1"></i> Année scolaire :
                <strong><?= e($activeYear) ?></strong>
            </span>
        </div>
    </div>

    <!-- CARDS STATISTIQUES EN HAUT -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat border-0 shadow-sm rounded-4 bg-white h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1">Total à Payer (Annuel)</span>
                        <h3 class="fw-bold text-dark mb-1"><?= fmt_money($totalAnnuelAPayerCombined) ?> $</h3>
                        <span class="badge bg-light text-secondary rounded-pill fw-normal">Scolaires + Connexes</span>
                    </div>
                    <div class="icon-shape bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-wallet2 fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat border-0 shadow-sm rounded-4 bg-white h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1">Total Payé</span>
                        <h3 class="fw-bold text-success mb-1"><?= fmt_money($totalAnnuelPayeCombined) ?> $</h3>
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill fw-normal">
                            <i class="bi bi-check-circle-fill me-1"></i>Paiements validés
                        </span>
                    </div>
                    <div class="icon-shape bg-success bg-opacity-10 text-success">
                        <i class="bi bg-opacity-10 bi-cash-stack fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat border-0 shadow-sm rounded-4 bg-white h-100">
                <div class="card-body p-3 d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase d-block mb-1">Reste à Payer</span>
                        <h3 class="fw-bold text-danger mb-1"><?= fmt_money($totalAnnuelResteCombined) ?> $</h3>
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill fw-normal">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Solde restant
                        </span>
                    </div>
                    <div class="icon-shape bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-pie-chart fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION CLARIFICATION / ÉLÉMENTS DU TOTAL À PAYER -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-header bg-white border-bottom pt-3 pb-2 px-4">
            <div class="d-flex align-items-center">
                <div class="icon-shape bg-light text-primary me-3" style="width: 38px; height: 38px;">
                    <i class="bi bi-calculator fs-5"></i>
                </div>
                <div>
                    <h5 class="h6 mb-0 fw-bold">Décomposition par Rubrique</h5>
                    <small class="text-muted">Répartition précise des frais fixés et des soldes restants.</small>
                </div>
            </div>
        </div>
        <div class="card-body p-4 bg-light bg-opacity-50">
            <div class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <div
                        class="p-3 bg-white border border-light-subtle rounded-3 h-100 border-start bodrder-4 border-primary">
                        <span class="text-muted small fw-semibold d-block mb-1">Frais Scolaires</span>
                        <div class="h5 fw-bold text-primary mb-1"><?= fmt_money($fraisScolaireAPayer) ?> $</div>
                        <small class="text-muted"><i class="bi bi-arrow-down-right-circle text-success me-1"></i>Payé :
                            <strong><?= fmt_money($school_paid) ?> $</strong></small>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div
                        class="p-3 bg-white border border-light-subtle rounded-3 h-100 border-start bodrder-4 border-danger">
                        <span class="text-muted small fw-semibold d-block mb-1">Reste Frais Scolaires</span>
                        <div class="h5 fw-bold text-danger mb-1"><?= fmt_money($resteFraisScolaire) ?> $</div>
                        <small class="text-muted">Solde scolarité</small>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div
                        class="p-3 bg-white border border-light-subtle rounded-3 h-100 border-start bodrder-4 border-info">
                        <span class="text-muted small fw-semibold d-block mb-1">Frais Connexes</span>
                        <div class="h5 fw-bold text-info mb-1"><?= fmt_money($fraisConnexeAPayer) ?> $</div>
                        <small class="text-muted"><i class="bi bi-arrow-down-right-circle text-success me-1"></i>Payé :
                            <strong><?= fmt_money($totalDiversPayer) ?> $</strong></small>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div
                        class="p-3 bg-white border border-light-subtle rounded-3 h-100 border-start bodrder-4 border-warning">
                        <span class="text-muted small fw-semibold d-block mb-1">Reste Frais Connexes</span>
                        <div class="h5 fw-bold text-warning mb-1"><?= fmt_money($resteFraisConnexe) ?> $</div>
                        <small class="text-muted">Solde connexes</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLEAU 1 : HISTORIQUE DES FRAIS SCOLAIRES (Table: paiement) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div
            class="card-header bg-white border-bottom pt-3 pb-2 px-4 d-flex justify-content-between align-items-center">
            <h5 class="h6 mb-0 fw-bold text-primary">
                <i class="bi bi-journal-check me-2"></i>Historique des Frais Scolaires
            </h5>
            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3"><?= count($paymentsSchool) ?>
                reçu(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($paymentsSchool)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-5 d-block mb-3 text-secondary opacity-50"></i>
                <p class="mb-0">Aucun paiement de frais scolaires enregistré.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-custom">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">N° Reçu</th>
                            <th>Date & Heure</th>
                            <th class="text-end">Montant À Payer</th>
                            <th class="text-end">Montant Payé</th>
                            <th class="text-end">Reste À Payer</th>
                            <th class="pe-4">Observation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentsSchool as $p): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-dark">
                                <span class="badge bg-light text-dark border">#<?= (int)$p['id'] ?></span>
                            </td>
                            <td class="text-muted small">
                                <i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($p['dateCreated'])) ?>
                            </td>
                            <td class="text-end fw-semibold text-dark"><?= fmt_money($p['montantAPayer']) ?> $</td>
                            <td class="text-end fw-bold text-success">+ <?= fmt_money($p['montantPayer']) ?> $</td>
                            <td class="text-end text-danger fw-semibold"><?= fmt_money($p['resteAPayer']) ?> $</td>
                            <td class="pe-4 text-muted small"><?= e($p['observation'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TABLEAU 2 : HISTORIQUE DES FRAIS CONNEXES (Table: paiement_divers) -->
    <div class="card border-0 shadow-sm rounded-4">
        <div
            class="card-header bg-white border-bottom pt-3 pb-2 px-4 d-flex justify-content-between align-items-center">
            <h5 class="h6 mb-0 fw-bold text-info">
                <i class="bi bi-receipt-cutoff me-2"></i>Historique des Frais Connexes / Divers
            </h5>
            <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3"><?= count($paymentsDivers) ?>
                reçu(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($paymentsDivers)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-5 d-block mb-3 text-secondary opacity-50"></i>
                <p class="mb-0">Aucun paiement de frais connexes enregistré.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-custom">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">N° Reçu</th>
                            <th>Date & Heure</th>
                            <th class="text-end">Montant À Payer</th>
                            <th class="text-end">Montant Payé</th>
                            <th class="text-end">Reste À Payer</th>
                            <th class="pe-4">Observation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentsDivers as $p): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-dark">
                                <span class="badge bg-light text-dark border">#<?= (int)$p['id'] ?></span>
                            </td>
                            <td class="text-muted small">
                                <i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($p['dateCreated'])) ?>
                            </td>
                            <td class="text-end fw-semibold text-dark"><?= fmt_money($p['montantAPayer']) ?> $</td>
                            <td class="text-end fw-bold text-success">+ <?= fmt_money($p['montantPayer']) ?> $</td>
                            <td class="text-end text-danger fw-semibold"><?= fmt_money($p['resteAPayer']) ?> $</td>
                            <td class="pe-4 text-muted small"><?= e($p['observation'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>