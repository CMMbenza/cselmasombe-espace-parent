<?php
// /parent/eleve/bulletin_print.php
declare(strict_types=1);

// Debug (tu peux enlever ces 3 lignes après les tests si tu veux)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

if (!function_exists('e')) {
    function e($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

// Récup élève courant depuis la session parent
$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
if ($eid <= 0) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// Vérifier que l'élève appartient au ménage + récupérer la classe & cycle
$stmt = $pdo->prepare("
  SELECT 
    e.id, 
    e.nom, e.postnom, e.prenom,
    e.genre,
    e.dateDeNaissance,
    e.classe,
    c.description AS classe_desc, 
    c.cycle      AS cycle_id,
    cy.description AS cycle_desc
  FROM eleve e
  JOIN classe c ON c.id = e.classe
  LEFT JOIN cycle cy ON cy.id = c.cycle
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

// ===============================
// 1) Présence mensuelle (mois en cours)
// ===============================
$presence = [
    'total'   => 0,
    'present' => 0,
    'absent'  => 0,
    'taux'    => 0.0,
];

$currentYm = date('Y-m'); // ex: 2025-11

$pStmt = $pdo->prepare("
  SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS present,
    SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absent
  FROM appel_detail ad
  JOIN appel a ON a.id = ad.appel_id
  WHERE ad.eleve_id = :eid
    AND DATE_FORMAT(a.date_appel, '%Y-%m') = :ym
");
$pStmt->execute([':eid' => $eid, ':ym' => $currentYm]);
$presRow = $pStmt->fetch(PDO::FETCH_ASSOC);
if ($presRow) {
    $presence['total']   = (int)($presRow['total'] ?? 0);
    $presence['present'] = (int)($presRow['present'] ?? 0);
    $presence['absent']  = (int)($presRow['absent'] ?? 0);

    if ($presence['total'] > 0) {
        $presence['taux'] = round(($presence['present'] / $presence['total']) * 100, 1);
    }
}

// ===============================
// 2) Devoirs corrigés avec notes (QUIZ)
// ===============================
$params = [':cid' => (int)$el['classe']];
$sql = "
  SELECT 
    q.id, 
    q.titre, 
    q.type_quiz, 
    q.format, 
    q.date_limite, 
    q.created_at,
    q.agent_id, 
    q.classe_id,
    q.periode_id,
    a.nom, a.postnom, a.prenom
  FROM quiz q
  LEFT JOIN agent a ON a.id = q.agent_id
  WHERE q.classe_id = :cid
    AND q.statut = 'approuvé'
  ORDER BY COALESCE(q.date_limite, q.created_at) DESC, q.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$quizzes = $st->fetchAll(PDO::FETCH_ASSOC);

// Soumissions de l'élève pour ces quiz
$quizIds = array_map(fn($r) => (int)$r['id'], $quizzes);
$subsByQuiz = [];

if ($quizIds) {
    $in   = implode(',', array_fill(0, count($quizIds), '?'));
    $args = $quizIds;
    $args[] = $eid;

    $s = $pdo->prepare("
        SELECT 
          s.id, s.quiz_id, s.statut, s.note_totale, s.date_submitted
        FROM quiz_submission s
        WHERE s.quiz_id IN ($in) AND s.eleve_id = ?
        ORDER BY s.date_submitted DESC, s.id DESC
    ");
    $s->execute($args);
    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
        $qid = (int)$r['quiz_id'];
        if (!isset($subsByQuiz[$qid])) {
            $subsByQuiz[$qid] = $r;
        }
    }
}

// On ne garde que les devoirs corrigés
$devoirsCorriges = [];
foreach ($quizzes as $qz) {
    $qid = (int)$qz['id'];
    if (!isset($subsByQuiz[$qid])) continue;
    $sub = $subsByQuiz[$qid];
    if ($sub['statut'] !== 'corrige') continue;

    $note = $sub['note_totale'] !== null ? (float)$sub['note_totale'] : null;

    $devoirsCorriges[] = [
        'quiz' => $qz,
        'sub'  => $sub,
        'note' => $note,
    ];
}

// ===============================
// 3) Moyennes par matière (à partir des QUIZ corrigés)
// ===============================
$moyennes = []; // [titre_matiere] => ['sum'=>x, 'count'=>n]

foreach ($devoirsCorriges as $row) {
    $qz   = $row['quiz'];
    $note = $row['note'];

    if ($note === null) continue;

    $matiere = trim((string)($qz['titre'] ?? ''));
    if ($matiere === '') continue;

    if (!isset($moyennes[$matiere])) {
        $moyennes[$matiere] = ['sum' => 0.0, 'count' => 0];
    }
    $moyennes[$matiere]['sum']   += $note;
    $moyennes[$matiere]['count'] += 1;
}

$rowsMoyennes = [];
foreach ($moyennes as $matiere => $data) {
    if ($data['count'] <= 0) continue;
    $avg = $data['sum'] / $data['count'];
    $rowsMoyennes[] = [
        'matiere' => $matiere,
        'avg'     => $avg,
        'count'   => $data['count'],
    ];
}

// Tri des matières par ordre alphabétique
usort($rowsMoyennes, fn($a, $b) => strcasecmp($a['matiere'], $b['matiere']));

// ===============================
// 4) Cahier de cotes (évaluations de classe)
// ===============================
//
// On utilise ta vraie table `cahier_cotes` + `cours` + `periodes`
//  - cours.intitule  -> nom du cours
//  - periodes.CODE / libelle -> période
//
$cahierCotes = [];

try {
    $ccStmt = $pdo->prepare("
        SELECT 
            cc.cours_id,
            c.intitule       AS cours_intitule,
            cc.periode_id,
            cc.type_app,
            cc.points,
            cc.remarque,
            cc.created_at,
            p.libelle        AS periode_libelle,
            p.CODE           AS periode_code
        FROM cahier_cotes cc
        JOIN cours c      ON c.id = cc.cours_id
        LEFT JOIN periodes p ON p.id = cc.periode_id
        WHERE cc.eleve_id = :eid
        ORDER BY cc.created_at ASC, cc.id ASC
    ");
    $ccStmt->execute([':eid' => $eid]);
    $cahierCotes = $ccStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cahierCotes = [];
}

// ===============================
// 5) Formatage de note simple
// ===============================
function fmt_note(?float $n): string {
    if ($n === null) return '—';
    return number_format($n, 2, ',', ' ');
}
?>
<style>
/* Styles spécifiques au bulletin imprimable */
.bulletin-container {
    background: #ffffff;
    padding: 20px;
    margin-top: 10px;
    margin-bottom: 20px;
    border-radius: 6px;
    box-shadow: 0 0 0.5rem rgba(0,0,0,0.05);
}

.bulletin-header {
    border-bottom: 2px solid #000;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.bulletin-header h1 {
    font-size: 1.4rem;
    margin: 0;
}

.bulletin-header .school-name {
    font-weight: 600;
    font-size: 1rem;
    text-transform: uppercase;
}

.bulletin-meta {
    font-size: 0.85rem;
}

@media print {
    body {
        background: #ffffff !important;
    }
    .navbar, .no-print {
        display: none !important;
    }
    .bulletin-container {
        box-shadow: none;
        border-radius: 0;
        margin: 0;
        padding: 0;
    }
}
</style>

<div class="container bulletin-container">

    <!-- Barre d'actions (non imprimée) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= BASE_URL ?>/eleve/quizzes.php" class="btn btn-sm btn-outline-secondary">
            &larr; Retour à l’espace de travail
        </a>
        <button type="button" class="btn btn-sm btn-dark" onclick="window.print();">
            Imprimer / Télécharger en PDF
        </button>
    </div>

    <!-- En-tête du bulletin -->
    <div class="bulletin-header text-center">
        <div class="school-name">Complexe Scolaire ELMA SOMBE</div>
        <div class="fw-semibold">Bulletin de suivi des devoirs & présence</div>
        <div class="small">Mois : <?= e($currentYm) ?></div>
    </div>

    <!-- Infos élève -->
    <div class="row mb-3 bulletin-meta">
        <div class="col-md-6">
            <p class="mb-1">
                <strong>Élève :</strong>
                <?= e($el['nom'].' '.$el['postnom'].' '.$el['prenom']) ?>
            </p>
            <p class="mb-1">
                <strong>Sexe :</strong>
                <?= e((string)$el['genre']) ?>
            </p>
            <p class="mb-1">
                <strong>Date de naissance :</strong>
                <?= e((string)($el['dateDeNaissance'] ?? '')) ?>
            </p>
        </div>
        <div class="col-md-6">
            <p class="mb-1">
                <strong>Classe :</strong>
                <?= e($el['classe_desc']) ?>
            </p>
            <p class="mb-1">
                <strong>Cycle :</strong>
                <?= e((string)($el['cycle_desc'] ?? '')) ?>
            </p>
            <p class="mb-1">
                <strong>N° dossier :</strong> <?= (int)$el['id'] ?>
            </p>
        </div>
    </div>

    <!-- 1. Présence mensuelle -->
    <h2 class="h6 mt-3 mb-2">1. Présence (mois : <?= e($currentYm) ?>)</h2>
    <table class="table table-sm table-bordered w-auto">
        <thead class="table-light">
            <tr>
                <th>Jours total</th>
                <th>Jours présents</th>
                <th>Jours absents</th>
                <th>Taux de présence</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= (int)$presence['total'] ?></td>
                <td><?= (int)$presence['present'] ?></td>
                <td><?= (int)$presence['absent'] ?></td>
                <td><?= $presence['taux'] ?> %</td>
            </tr>
        </tbody>
    </table>

    <!-- 2. Moyenne des devoirs (QUIZ) par matière -->
    <h2 class="h6 mt-4 mb-2">2. Moyenne des devoirs (quiz) par matière</h2>
    <?php if (!$rowsMoyennes): ?>
        <p class="small text-muted">
            Aucune note de devoir (quiz) enregistrée pour le moment. Les moyennes apparaîtront ici dès que des devoirs seront corrigés.
        </p>
    <?php else: ?>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Matière (titre du devoir / cours)</th>
                    <th>Nombre de devoirs</th>
                    <th>Moyenne</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rowsMoyennes as $m): ?>
                    <tr>
                        <td><?= e($m['matiere']) ?></td>
                        <td><?= (int)$m['count'] ?></td>
                        <td><?= fmt_note($m['avg']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 3. Détails des devoirs corrigés (QUIZ) -->
    <h2 class="h6 mt-4 mb-2">3. Détails des devoirs corrigés (quiz)</h2>
    <?php if (!$devoirsCorriges): ?>
        <p class="small text-muted">
            Aucun devoir corrigé pour l’instant.
        </p>
    <?php else: ?>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Date remise</th>
                    <th>Devoir / Matière</th>
                    <th>Format</th>
                    <th>Professeur</th>
                    <th>Date limite</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devoirsCorriges as $row): ?>
                    <?php
                        $qz  = $row['quiz'];
                        $sub = $row['sub'];
                        $note = $row['note'];
                        $teacher = trim(($qz['nom'] ?? '').' '.($qz['postnom'] ?? '').' '.($qz['prenom'] ?? ''));
                    ?>
                    <tr>
                        <td><?= e((string)$sub['date_submitted']) ?></td>
                        <td><?= e((string)$qz['titre']) ?></td>
                        <td><?= e((string)$qz['format']) ?></td>
                        <td><?= $teacher !== '' ? e($teacher) : '—' ?></td>
                        <td><?= e((string)($qz['date_limite'] ?? '')) ?></td>
                        <td><?= fmt_note($note) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 4. Cahier de cotes (évaluations de classe) -->
    <h2 class="h6 mt-4 mb-2">4. Cahier de cotes (évaluations de classe)</h2>
    <?php if (!$cahierCotes): ?>
        <p class="small text-muted">
            Aucune note du cahier de cotes trouvée pour cet élève.
        </p>
    <?php else: ?>
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Période</th>
                    <th>Cours</th>
                    <th>Type d’épreuve</th>
                    <th>Remarque</th>
                    <th>Points</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cahierCotes as $cc): ?>
                    <tr>
                        <td><?= e((string)$cc['created_at']) ?></td>
                        <td>
                            <?php
                                $labelPeriode = trim(($cc['periode_code'] ?? '').' '.$cc['periode_libelle']);
                                echo $labelPeriode !== '' ? e($labelPeriode) : e((string)$cc['periode_id']);
                            ?>
                        </td>
                        <td><?= e((string)$cc['cours_intitule']) ?></td>
                        <td><?= e((string)($cc['type_app'] ?? '')) ?></td>
                        <td><?= e((string)($cc['remarque'] ?? '')) ?></td>
                        <td><?= fmt_note($cc['points'] !== null ? (float)$cc['points'] : null) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- 5. Observations -->
    <h2 class="h6 mt-4 mb-2">5. Observations</h2>
    <p class="small">
        <strong>Observation du professeur / direction :</strong>
    </p>
    <div style="border:1px solid #ccc; height:80px; margin-bottom:10px;"></div>

    <p class="small">
        <strong>Observation du parent :</strong>
    </p>
    <div style="border:1px solid #ccc; height:80px;"></div>

    <div class="d-flex justify-content-between mt-4">
        <div class="small">
            Fait à ..................................., le ........../........../<?= date('Y') ?><br>
            Signature du parent : .............................
        </div>
        <div class="small text-end">
            Signature de la direction : .............................
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
