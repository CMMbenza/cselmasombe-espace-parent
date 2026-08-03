<?php
// /parent/eleve/quizzes_liste.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../get_annee_scolaire_enours.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$anneeActuelle = $ANNEE_SCOLAIRE_EN_COURS ?? date('Y') . '-' . (date('Y') + 1);

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
if ($eid <= 0) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// 1) Vérification de l'élève et récupération de ses infos de classe
$stmt = $pdo->prepare("
    SELECT 
        e.id, 
        e.nom, 
        e.prenom,
        e.classe, 
        CONCAT(c.description ,' - ', cy.description) AS classe_desc
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    JOIN cycle cy ON cy.id = c.cycle
    WHERE e.id = :eid AND e.menage = :mid
    LIMIT 1
");
$stmt->execute([':eid' => $eid, ':mid' => $mid]);
$el = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$el) {
    set_current_eleve(0);
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// 2) Récupération des filtres depuis l'URL
$selectedAnnee = trim((string)($_GET['annee'] ?? ''));
$searchQuery   = trim((string)($_GET['q'] ?? ''));

// 3) Liste de toutes les années scolaires disponibles pour les filtres
$stAnnees = $pdo->prepare("
    SELECT DISTINCT q.anneeScolaire 
    FROM quiz q
    JOIN quiz_classe qc ON qc.quiz_id = q.id
    WHERE qc.classe_id = :cid AND q.statut = 'approuvé' AND q.anneeScolaire IS NOT NULL
    ORDER BY q.anneeScolaire DESC
");
$stAnnees->execute([':cid' => (int)$el['classe']]);
$listeAnnees = $stAnnees->fetchAll(PDO::FETCH_COLUMN);

// 4) Requête principale pour charger TOUS les quiz de la classe
$params = [':cid' => (int)$el['classe']];
$sql = "
    SELECT 
        q.id, 
        q.titre, 
        q.type_quiz, 
        q.format, 
        q.date_limite, 
        q.created_at,
        q.anneeScolaire,
        q.periode_id,
        a.nom AS agent_nom, 
        a.prenom AS agent_prenom,
        p.libelle AS periode_libelle
    FROM quiz q
    JOIN quiz_classe qc ON qc.quiz_id = q.id
    LEFT JOIN agent a ON a.id = q.agent_id
    LEFT JOIN periodes p ON p.id = q.periode_id
    WHERE qc.classe_id = :cid
      AND q.statut = 'approuvé'
";

if ($selectedAnnee !== '') {
    $sql .= " AND q.anneeScolaire = :annee ";
    $params[':annee'] = $selectedAnnee;
}

if ($searchQuery !== '') {
    $sql .= " AND q.titre LIKE :like ";
    $params[':like'] = '%' . $searchQuery . '%';
}

$sql .= " ORDER BY q.anneeScolaire DESC, COALESCE(q.date_limite, q.created_at) DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$allQuizzes = $st->fetchAll(PDO::FETCH_ASSOC);

// 5) Charger les soumissions de l'élève pour ces quiz
$quizIds = array_map(fn($r) => (int)$r['id'], $allQuizzes);
$subsByQuiz = [];

if (!empty($quizIds)) {
    $in   = implode(',', array_fill(0, count($quizIds), '?'));
    $args = $quizIds;
    $args[] = $eid;

    $s = $pdo->prepare("
        SELECT s.quiz_id, s.statut, s.note_totale, s.date_submitted
        FROM quiz_submission s
        WHERE s.quiz_id IN ($in) AND s.eleve_id = ?
        ORDER BY s.date_submitted DESC
    ");
    $s->execute($args);
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $qid = (int)$r['quiz_id'];
        if (!isset($subsByQuiz[$qid])) {
            $subsByQuiz[$qid] = $r;
        }
    }
}

// 6) Organisation sous forme d'archives groupées par Année Scolaire
$archives = [];
foreach ($allQuizzes as $qz) {
    $anneeGroup = !empty($qz['anneeScolaire']) ? $qz['anneeScolaire'] : 'Non spécifiée';
    if (!isset($archives[$anneeGroup])) {
        $archives[$anneeGroup] = [];
    }
    
    $qid = (int)$qz['id'];
    $sub = $subsByQuiz[$qid] ?? null;
    
    // Évaluation du statut d'expiration si non remis
    $isExpired = false;
    if (!$sub && !empty($qz['date_limite'])) {
        $isExpired = strtotime($qz['date_limite'] . ' 23:59:59') < time();
    }

    $archives[$anneeGroup][] = [
        'quiz'      => $qz,
        'sub'       => $sub,
        'isExpired' => $isExpired
    ];
}
?>

<div class="container py-4">
    <!-- En-tête de la page -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-3 border-bottom">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-archive me-2"></i>Archives des Devoirs & Quiz</h1>
            <p class="text-muted mb-0">
                Élève : <strong><?= htmlspecialchars($el['prenom'] . ' ' . $el['nom'], ENT_QUOTES, 'UTF-8') ?></strong> 
                (<?= htmlspecialchars($el['classe_desc'], ENT_QUOTES, 'UTF-8') ?>)
            </p>
        </div>
        <div class="mt-3 mt-md-0">
            <a href="<?= BASE_URL ?>/eleve/quizzes.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Tableau de bord des quiz
            </a>
        </div>
    </div>

    <!-- Barre de filtrage -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Rechercher par titre de quiz..." value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="annee" class="form-select">
                        <option value="">Toutes les années scolaires</option>
                        <?php foreach ($listeAnnees as $an): ?>
                            <option value="<?= htmlspecialchars($an, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedAnnee === $an ? 'selected' : '' ?>>
                                Année <?= htmlspecialchars($an, ENT_QUOTES, 'UTF-8') ?> <?= $an === $anneeActuelle ? '(En cours)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel me-1"></i> Filtrer
                    </button>
                    <?php if ($searchQuery !== '' || $selectedAnnee !== ''): ?>
                        <a href="<?= BASE_URL ?>/eleve/quizzes_liste.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Liste d'archives groupée par Année -->
    <?php if (empty($archives)): ?>
        <div class="alert alert-info text-center py-4 shadow-sm" role="alert">
            <i class="bi bi-info-circle fs-3 d-block mb-2"></i>
            Aucun quiz n'a été trouvé pour les critères sélectionnés.
        </div>
    <?php else: ?>
        <?php foreach ($archives as $anneeGroup => $items): ?>
            <div class="mb-5">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-dark fs-6 me-2">
                        <i class="bi bi-calendar-range me-1"></i> Année <?= htmlspecialchars($anneeGroup, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ($anneeGroup === $anneeActuelle): ?>
                        <span class="badge bg-success">Année en cours</span>
                    <?php endif; ?>
                    <div class="flex-grow-1 ms-3 border-bottom"></div>
                </div>

                <div class="row g-3">
                    <?php foreach ($items as $entry): 
                        $qz        = $entry['quiz'];
                        $sub       = $entry['sub'];
                        $isExpired = $entry['isExpired'];
                        $teacher   = trim(($qz['agent_prenom'] ?? '') . ' ' . ($qz['agent_nom'] ?? ''));
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100 shadow-sm border-0 position-relative" style="background-color: #fdfdfd;">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge text-bg-light border">
                                            <?= htmlspecialchars(strtoupper($qz['type_quiz'] ?? 'QUIZ'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($sub): ?>
                                            <?php if ($sub['statut'] === 'corrige'): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle me-1"></i> Noté : <?= htmlspecialchars((string)$sub['note_totale'], ENT_QUOTES, 'UTF-8') ?> pts
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-clock-history me-1"></i> Remis
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($isExpired): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-x-circle me-1"></i> Expiré
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">
                                                <i class="bi bi-exclamation-circle me-1"></i> À faire
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h5 class="card-title h6 text-primary mb-2">
                                        <?= htmlspecialchars($qz['titre'], ENT_QUOTES, 'UTF-8') ?>
                                    </h5>

                                    <div class="small text-muted mb-3 flex-grow-1">
                                        <?php if (!empty($qz['periode_libelle'])): ?>
                                            <div><i class="bi bi-bookmark me-1"></i> Période : <?= htmlspecialchars($qz['periode_libelle'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($teacher !== ''): ?>
                                            <div><i class="bi bi-person me-1"></i> Enseignant : <?= htmlspecialchars($teacher, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <i class="bi bi-calendar-event me-1"></i> Limite : 
                                            <?= !empty($qz['date_limite']) ? date('d/m/Y', strtotime($qz['date_limite'])) : 'Aucune' ?>
                                        </div>
                                    </div>

                                    <!-- Action : Bouton de consultation -->
                                    <div class="pt-2 border-top d-flex justify-content-between align-items-center">
                                        <span class="small text-muted">
                                            <?= date('d/m/Y', strtotime($qz['created_at'])) ?>
                                        </span>
                                        <a href="<?= BASE_URL ?>/eleve/quiz_detail.php?id=<?= (int)$qz['id'] ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye me-1"></i> Voir questions
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>