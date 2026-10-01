<?php
// /parent/notification.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/auth.php';
require_parent();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$usePdo = isset($pdo) && !isset($con);

// Récupération de l'ID ménage du parent
$menageId = (int)($_SESSION['menage_id'] ?? ($_SESSION['parent']['id'] ?? 0));

if ($menageId <= 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// -------------------------------------------------------------------------
// 1) RÉCUPÉRATION DES ÉLÈVES ET DE LEURS CLASSES
// -------------------------------------------------------------------------
$enfantIds = [];
$classeIds = [];

try {
    $sqlEleves = "SELECT id, classe FROM eleve WHERE menage = ? AND STATUS = 'actif'";
    if ($usePdo) {
        $stmt = $pdo->prepare($sqlEleves);
        $stmt->execute([$menageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $enfantIds[] = (int)$row['id'];
            if (!empty($row['classe'])) $classeIds[] = (int)$row['classe'];
        }
    } else {
        $stmt = $con->prepare($sqlEleves);
        $stmt->bind_param('i', $menageId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $enfantIds[] = (int)$row['id'];
            if (!empty($row['classe'])) $classeIds[] = (int)$row['classe'];
        }
        $stmt->close();
    }
} catch (Throwable $e) {}

$classeIds = array_filter(array_unique($classeIds));

$listAnnonces = [];
$listQuiz = [];
$listJournal = [];

// -------------------------------------------------------------------------
// 2) RÉCUPÉRATION DES ANNONCES
// -------------------------------------------------------------------------
$whereAnnonces = ["a.dest_type = 'tous'", "a.dest_type = 'eleves'"];
if (!empty($enfantIds)) {
    $whereAnnonces[] = "(a.dest_type = 'user' AND a.dest_id IN (" . implode(',', $enfantIds) . "))";
}

$sqlAnnonces = "
    SELECT 
        a.id,
        a.titre,
        a.contenu AS description,
        a.created_at,
        'Toutes' AS classe_nom
    FROM annonces a
    WHERE (" . implode(' OR ', $whereAnnonces) . ")
    ORDER BY a.created_at DESC
    LIMIT 30
";

try {
    if ($usePdo) {
        $listAnnonces = $pdo->query($sqlAnnonces)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $resAnnonces = $con->query($sqlAnnonces);
        if ($resAnnonces) {
            while ($row = $resAnnonces->fetch_assoc()) $listAnnonces[] = $row;
        }
    }
} catch (Throwable $e) {}

// -------------------------------------------------------------------------
// 3) RÉCUPÉRATION DES QUIZ
// -------------------------------------------------------------------------
if (!empty($classeIds)) {
    $inClasses = implode(',', array_map('intval', $classeIds));

    $sqlQuiz = "
        SELECT DISTINCT
            q.id,
            q.titre,
            q.description,
            q.type_quiz,
            q.date_limite,
            q.created_at,
            COALESCE(cr.intitule, 'Cours non spécifié') AS cours_nom,
            CONCAT_WS(' ', cl.description, cy.description) AS classe_nom
        FROM quiz q
        INNER JOIN cours cr ON q.cours_id = cr.id
        INNER JOIN classe cl ON cr.classe_id = cl.id
         INNER JOIN cycle cy ON cy.id = cl.cycle
        WHERE cl.id IN ($inClasses)
          AND LOWER(q.statut) IN ('approuvé', 'approuve', 'valider', 'validé')
        ORDER BY q.id DESC
        LIMIT 30
    ";

    try {
        if ($usePdo) {
            $listQuiz = $pdo->query($sqlQuiz)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $resQuiz = $con->query($sqlQuiz);
            if ($resQuiz) {
                while ($row = $resQuiz->fetch_assoc()) $listQuiz[] = $row;
            }
        }
    } catch (Throwable $e) {}
}

// -------------------------------------------------------------------------
// 4) RÉCUPÉRATION DU JOURNAL DE CLASSE
// -------------------------------------------------------------------------
if (!empty($classeIds)) {
    $inClasses = implode(',', array_map('intval', $classeIds));

    $sqlJournal = "
        SELECT 
            j.id,
            j.jour_date,
            j.matieres,
            j.note,
            j.created_at,
            COALESCE(cr.intitule, 'Général / Multi-cours') AS cours_nom,
            CONCAT_WS(' ', cl.description, cy.description) AS classe_nom
        FROM journal_classe j
        LEFT JOIN cours cr ON j.cours_id = cr.id
        INNER JOIN classe cl ON j.classe_id = cl.id
        INNER JOIN cycle cy ON cy.id = cl.cycle
        WHERE j.classe_id IN ($inClasses)
          AND LOWER(j.statut) IN ('valider', 'validé', 'approuvé', 'approuve')
        ORDER BY j.jour_date DESC, j.id DESC
        LIMIT 30
    ";

    try {
        if ($usePdo) {
            $listJournal = $pdo->query($sqlJournal)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $resJournal = $con->query($sqlJournal);
            if ($resJournal) {
                while ($row = $resJournal->fetch_assoc()) $listJournal[] = $row;
            }
        }
    } catch (Throwable $e) {}
}

$totalNotifs = count($listAnnonces) + count($listQuiz) + count($listJournal);
?>

<div class="container-fluid px-4 py-3">
    <!-- ENTÊTE -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">🔔 Notifications récentes</h4>
            <small class="text-muted">Annonces, Quiz et Journal de classe de la famille.</small>
        </div>
        <div>
            <span class="badge bg-primary fs-6 px-3 py-2 rounded-pill">
                <?= $totalNotifs ?> notification(s)
            </span>
        </div>
    </div>

    <!-- ONGLETS DE NAVIGATION -->
    <ul class="nav nav-pills mb-4 gap-2" id="notificationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-item nav-link active rounded-pill px-4 fw-bold" id="annonces-tab" data-bs-toggle="tab"
                data-bs-target="#annonces" type="button" role="tab">
                📢 Annonces / Communiqués
                <span class="badge bg-danger ms-1"><?= count($listAnnonces) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-item nav-link rounded-pill px-4 fw-bold" id="quiz-tab" data-bs-toggle="tab"
                data-bs-target="#quiz" type="button" role="tab">
                📝 Quiz
                <span class="badge bg-secondary ms-1"><?= count($listQuiz) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-item nav-link rounded-pill px-4 fw-bold" id="journal-tab" data-bs-toggle="tab"
                data-bs-target="#journal" type="button" role="tab">
                📖 Journal de classe
                <span class="badge bg-secondary ms-1"><?= count($listJournal) ?></span>
            </button>
        </li>
    </ul>

    <!-- CONTENU DES ONGLETS -->
    <div class="tab-content" id="notificationTabsContent">

        <!-- SECTION 1 : ANNONCES -->
        <div class="tab-pane fade show active" id="annonces" role="tabpanel">
            <?php renderAnnoncesTable($listAnnonces); ?>
        </div>

        <!-- SECTION 2 : QUIZ -->
        <div class="tab-pane fade" id="quiz" role="tabpanel">
            <?php renderQuizTable($listQuiz); ?>
        </div>

        <!-- SECTION 3 : JOURNAL DE CLASSE -->
        <div class="tab-pane fade" id="journal" role="tabpanel">
            <?php renderJournalTable($listJournal); ?>
        </div>

    </div>
</div>

<?php
// -------------------------------------------------------------------------
// TABLEAU DES ANNONCES
// -------------------------------------------------------------------------
function renderAnnoncesTable(array $items): void {
    if (empty($items)): ?>
<div class="card border-0 shadow-sm rounded-4 p-5 text-center">
    <div class="text-muted">
        <i class="bi bi-clock-history display-4 d-block mb-3"></i>
        <h5 class="fw-bold">Aucune annonce</h5>
        <p class="mb-0 small">Aucun communiqué disponible pour le moment.</p>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;">Classe</th>
                        <th style="width: 25%;">Titre</th>
                        <th style="width: 50%;">Détails</th>
                        <th style="width: 10%;" class="text-end">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <span class="badge bg-light text-dark border">
                                🏫 <?= e((string)($item['classe_nom'] ?? 'Toutes')) ?>
                            </span>
                        </td>
                        <td class="fw-bold text-dark">
                            <?= e((string)$item['titre']) ?>
                        </td>
                        <td class="text-secondary small">
                            <?= nl2br(e((string)($item['description'] ?: '—'))) ?>
                        </td>
                        <td class="text-end text-muted small">
                            <?= e(date('d/m/Y H:i', strtotime((string)$item['created_at']))) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif;
}

// -------------------------------------------------------------------------
// TABLEAU DES QUIZ (Classe | Cours | Consigne devoir | Date)
// -------------------------------------------------------------------------
function renderQuizTable(array $items): void {
    if (empty($items)): ?>
<div class="card border-0 shadow-sm rounded-4 p-5 text-center">
    <div class="text-muted">
        <i class="bi bi-journal-x display-4 d-block mb-3"></i>
        <h5 class="fw-bold">Aucun quiz disponible</h5>
        <p class="mb-0 small">Aucun quiz approuvé n'a été trouvé pour la classe de vos enfants.</p>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;">Classe</th>
                        <th style="width: 25%;">Cours</th>
                        <th style="width: 45%;">Consigne devoir</th>
                        <th style="width: 15%;" class="text-end">Date limite</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <span class="badge bg-light text-dark border">
                                🏫 <?= e((string)($item['classe_nom'] ?? 'N/A')) ?>
                            </span>
                        </td>
                        <td class="fw-bold text-primary">
                            📘 <?= e((string)$item['cours_nom']) ?>
                        </td>
                        <td>
                            <div class="fw-bold text-dark mb-1">
                                <?= e((string)$item['titre']) ?>
                                <?php if (!empty($item['type_quiz'])): ?>
                                <span class="badge bg-info text-dark ms-1"><?= e((string)$item['type_quiz']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-secondary small">
                                <?= nl2br(e((string)($item['description'] ?: 'Aucune consigne spécifique.'))) ?>
                            </div>
                        </td>
                        <td class="text-end text-muted small">
                            <?php if (!empty($item['date_limite'])): ?>
                            <span class="badge bg-warning text-dark">
                                ⏳ <?= e(date('d/m/Y', strtotime((string)$item['date_limite']))) ?>
                            </span>
                            <?php else: ?>
                            <?= e(date('d/m/Y', strtotime((string)$item['created_at']))) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif;
}

// -------------------------------------------------------------------------
// TABLEAU DU JOURNAL DE CLASSE (Classe | Cours | Leçons | Date du cours)
// -------------------------------------------------------------------------
function renderJournalTable(array $items): void {
    if (empty($items)): ?>
<div class="card border-0 shadow-sm rounded-4 p-5 text-center">
    <div class="text-muted">
        <i class="bi bi-book display-4 d-block mb-3"></i>
        <h5 class="fw-bold">Aucune leçon enregistrée</h5>
        <p class="mb-0 small">Aucune fiche de journal de classe validée n'a été trouvée.</p>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;">Classe</th>
                        <th style="width: 25%;">Cours</th>
                        <th style="width: 45%;">Leçons</th>
                        <th style="width: 15%;" class="text-end">Date du cours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <span class="badge bg-light text-dark border">
                                🏫 <?= e((string)($item['classe_nom'] ?? 'N/A')) ?>
                            </span>
                        </td>
                        <td class="fw-bold text-success">
                            📖 <?= e((string)$item['cours_nom']) ?>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark"><?= nl2br(e((string)$item['matieres'])) ?></div>
                            <?php if (!empty($item['note'])): ?>
                            <small class="text-muted d-block mt-1">
                                💡 <i>Observation : <?= e((string)$item['note']) ?></i>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-muted small fw-bold">
                            📅 <?= e(date('d/m/Y', strtotime((string)$item['jour_date']))) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif;
}

require_once __DIR__ . '/layout/footer.php';
?>