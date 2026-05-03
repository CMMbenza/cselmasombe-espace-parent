<?php
// /parent/eleve/quizzes.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

$mid = (int)($_SESSION['parent']['id'] ?? 0);
$eid = (int)get_current_eleve_id();
if ($eid <= 0) {
  header('Location: ' . BASE_URL . '/dashboard.php');
  exit;
}

// Vérifier que l'élève appartient au ménage + récupérer la classe & cycle
$el = null;
$stmt = $pdo->prepare("
  SELECT 
    e.id, 
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
// 1) Statistiques de présence (MENSUELLES)
// ===============================
$presence = [
  'total'   => 0,
  'present' => 0,
  'absent'  => 0,
  'taux'    => 0.0,
];

// Mois courant au format YYYY-MM (ex. 2025-11)
$currentYm = date('Y-m');

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
// 1.bis) Présence détaillée par mois (derniers mois)
// ===============================
$presenceByMonth = []; // [ '2025-01' => [total, present, absent, taux] ]

// Ici on suppose que la colonne s'appelle `date_appel` dans `appel`
$pmStmt = $pdo->prepare("
  SELECT 
    DATE_FORMAT(a.date_appel, '%Y-%m') AS ym,
    COUNT(*) AS total,
    SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS present,
    SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absent
  FROM appel_detail ad
  JOIN appel a ON a.id = ad.appel_id
  WHERE ad.eleve_id = :eid
  GROUP BY ym
  ORDER BY ym DESC
  LIMIT 6
");
$pmStmt->execute([':eid' => $eid]);
while ($row = $pmStmt->fetch(PDO::FETCH_ASSOC)) {
  $total   = (int)($row['total'] ?? 0);
  $present = (int)($row['present'] ?? 0);
  $absent  = (int)($row['absent'] ?? 0);
  $taux    = $total > 0 ? round(($present / $total) * 100, 1) : 0.0;
  $presenceByMonth[] = [
    'ym'      => (string)$row['ym'],
    'total'   => $total,
    'present' => $present,
    'absent'  => $absent,
    'taux'    => $taux,
  ];
}

// ===============================
// 2) Chargement des quiz de la classe
// ===============================
$q = trim((string)($_GET['q'] ?? ''));

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
    qc.classe_id,
    q.periode_id,
    a.nom, a.postnom, a.prenom
  FROM quiz q
  JOIN quiz_classe qc ON qc.quiz_id = q.id
  LEFT JOIN agent a ON a.id = q.agent_id
  WHERE qc.classe_id = :cid
    AND q.statut = 'approuvé'
";

if ($q !== '') {
  $sql .= " AND q.titre LIKE :like ";
  $params[':like'] = '%'.$q.'%';
}
$sql .= " ORDER BY COALESCE(q.date_limite, q.created_at) DESC, q.id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$quizzes = $st->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// 3) Soumissions de l'élève
// ===============================
$quizIds = array_map(fn($r) => (int)$r['id'], $quizzes);
$subsByQuiz = []; // quiz_id => submission row

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

// ===============================
// 4) Split : à faire / faits
// ===============================
$toDo = [];
$done = [];

foreach ($quizzes as $qz) {
  $qid = (int)$qz['id'];
  if (isset($subsByQuiz[$qid])) {
    $done[] = ['quiz' => $qz, 'sub' => $subsByQuiz[$qid]];
  } else {
    $toDo[] = $qz;
  }
}

// ===============================
// 5) Stats devoirs
// ===============================
$totalDevoirs = count($quizzes);
$totalAFaire  = count($toDo);
$totalRemis   = count($done);

// ===============================
// 6) Historique des notes par cours + Forces
// ===============================
$notesByCourse = []; // [titre_cours] => [ [date, note, statut], ... ]
$forces        = []; // [titre_cours] => ['sum'=>x, 'count'=>n]

foreach ($done as $row) {
  $qz  = $row['quiz'];
  $sub = $row['sub'];

  $titreCours = trim((string)($qz['titre'] ?? ''));
  if ($titreCours === '') continue;

  if (!isset($notesByCourse[$titreCours])) {
    $notesByCourse[$titreCours] = [];
  }

  $notesByCourse[$titreCours][] = [
    'date'   => (string)($sub['date_submitted'] ?? ''),
    'note'   => $sub['note_totale'] !== null ? (float)$sub['note_totale'] : null,
    'statut' => (string)$sub['statut'],
  ];

  if ($sub['statut'] === 'corrige' && $sub['note_totale'] !== null) {
    if (!isset($forces[$titreCours])) {
      $forces[$titreCours] = ['sum' => 0.0, 'count' => 0];
    }
    $forces[$titreCours]['sum']   += (float)$sub['note_totale'];
    $forces[$titreCours]['count'] += 1;
  }
}

// Top 3 forces
$topForces = [];
if ($forces) {
  foreach ($forces as $titre => $data) {
    if ($data['count'] > 0) {
      $avg = $data['sum'] / $data['count'];
      $topForces[] = [
        'titre' => $titre,
        'avg'   => $avg,
        'count' => $data['count'],
      ];
    }
  }
  usort($topForces, function ($a, $b) {
    if ($a['avg'] === $b['avg']) return 0;
    return ($a['avg'] > $b['avg']) ? -1 : 1;
  });
  $topForces = array_slice($topForces, 0, 3);
}

// ===============================
// 7) Périodes + progression par période
// ===============================
$periodes = [];
$notesByPeriod = []; // [periode_id] => ['sum'=>x,'count'=>n]

$cycleId = (int)($el['cycle_id'] ?? 0);
if ($cycleId > 0) {
  $pStmt2 = $pdo->prepare("
    SELECT id, CODE, libelle
    FROM periodes
    WHERE cycle_id = :cid AND actif = 1
    ORDER BY ordre
  ");
  $pStmt2->execute([':cid' => $cycleId]);
  $periodes = $pStmt2->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($done as $row) {
  $qz  = $row['quiz'];
  $sub = $row['sub'];

  $pid = isset($qz['periode_id']) ? (int)$qz['periode_id'] : 0;
  if ($pid <= 0) continue;
  if ($sub['statut'] !== 'corrige' || $sub['note_totale'] === null) continue;

  if (!isset($notesByPeriod[$pid])) {
    $notesByPeriod[$pid] = ['sum' => 0.0, 'count' => 0];
  }
  $notesByPeriod[$pid]['sum']   += (float)$sub['note_totale'];
  $notesByPeriod[$pid]['count'] += 1;
}

$periodStats = [];
$maxAvg = 0.0;
foreach ($periodes as $p) {
  $pid   = (int)$p['id'];
  $sum   = $notesByPeriod[$pid]['sum']   ?? 0.0;
  $count = $notesByPeriod[$pid]['count'] ?? 0;
  $avg   = $count > 0 ? ($sum / $count) : null;
  if ($avg !== null && $avg > $maxAvg) {
    $maxAvg = $avg;
  }
}

foreach ($periodes as $p) {
  $pid   = (int)$p['id'];
  $sum   = $notesByPeriod[$pid]['sum']   ?? 0.0;
  $count = $notesByPeriod[$pid]['count'] ?? 0;
  $avg   = $count > 0 ? ($sum / $count) : null;
  $width = 0;
  if ($avg !== null && $maxAvg > 0) {
    $width = (int)round(($avg / $maxAvg) * 100);
  }
  $periodStats[] = [
    'p'     => $p,
    'avg'   => $avg,
    'count' => $count,
    'width' => $width,
  ];
}

function badge_statut(string $statut): string {
  $map = ['corrige' => 'success', 'remis' => 'warning'];
  $cls = $map[$statut] ?? 'secondary';
  return '<span class="badge text-bg-'.$cls.'">'.htmlspecialchars($statut, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
}
?>
<div class="container">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h5 mb-0">
                Mon espace travail —
                <?= e($el['classe_desc']) ?><?= $el['cycle_desc'] ? ' — '.e($el['cycle_desc']) : '' ?>
            </h1>
            <div class="text-muted small">
                Vue élève : présence (mois en cours), devoirs (à faire / remis) et aperçu de ses forces.
            </div>
        </div>

        <form method="get" class="d-flex gap-2 mt-2 mt-sm-0">
            <input type="search" name="q" class="form-control form-control-sm"
                placeholder="Rechercher un devoir (titre)…" value="<?= e($q) ?>">
            <button class="btn btn-sm btn-outline-secondary">Rechercher</button>
            <?php if ($q !== ''): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/eleve/quizzes.php">Réinitialiser</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="d-flex flex-wrap gap-2">
                <a href="../eleve/classe.php" class="btn btn-outline-primary btn-sm">Ma classe & professeurs</a>
                <a href="../eleve/quizzes.php" class="btn btn-primary btn-sm">Travail à faire</a>
                <a href="../eleve/my_submissions.php" class="btn btn-outline-secondary btn-sm">Mes travaux remis</a>
            </div>
        </div>
    </div>

    <!-- Cards statistiques -->
    <div class="row g-3 mb-4">
        <!-- Présence mensuelle -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="small text-muted text-uppercase">
                            Présence (mois : <?= e($currentYm) ?>)
                        </div>
                        <span class="badge text-bg-light">Jours : <?= (int)$presence['total'] ?></span>
                    </div>
                    <div class="h5 mb-1">
                        <?= $presence['taux'] ?> %
                        <span class="small text-muted">de présence</span>
                    </div>
                    <div class="small text-muted mb-2">
                        Présent : <strong><?= (int)$presence['present'] ?></strong> —
                        Absent : <strong><?= (int)$presence['absent'] ?></strong>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar bg-success" role="progressbar"
                            style="width: <?= max(0,min(100,(float)$presence['taux'])) ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Devoirs -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted text-uppercase mb-1">Devoirs</div>
                    <div class="h5 mb-1">
                        <?= (int)$totalDevoirs ?>
                        <span class="small text-muted">devoir(s) publié(s)</span>
                    </div>
                    <div class="small">
                        À faire : <span class="badge text-bg-primary"><?= (int)$totalAFaire ?></span><br>
                        Déjà remis : <span class="badge text-bg-success"><?= (int)$totalRemis ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Forces actuelles -->
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted text-uppercase mb-1">Forces actuelles</div>
                    <?php if ($topForces): ?>
                    <div class="small text-muted mb-2">
                        Matières où tu sembles le plus à l’aise (d’après les devoirs déjà corrigés) :
                    </div>
                    <ul class="mb-0 small">
                        <?php foreach ($topForces as $f): ?>
                        <li>
                            <strong><?= e($f['titre']) ?></strong>
                            <span class="text-muted">
                                — basé sur <?= (int)$f['count'] ?> devoir(s) corrigé(s)
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="text-muted small">
                        Dès que certains devoirs seront corrigés, tu verras ici les matières où tu es le plus fort.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Deux colonnes : A faire / Déjà remis -->
    <div class="row g-3">
        <!-- Colonne A FAIRE -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Devoirs reçus (à faire)</strong>
                    <span class="badge text-bg-primary"><?= count($toDo) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$toDo): ?>
                    <div class="text-muted">Aucun devoir à faire pour le moment.</div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($toDo as $qz): ?>
                        <?php
                          $limit = $qz['date_limite'] ? (string)$qz['date_limite'] : null;
                          $isExpired = false;

                          if ($limit) {
                              $isExpired = strtotime($limit) < time();
                          }
                          ?>

                        <?php
                          $limit   = $qz['date_limite'] ? (string)$qz['date_limite'] : null;
                          $teacher = trim(($qz['nom'] ?? '').' '.($qz['postnom'] ?? '').' '.($qz['prenom'] ?? ''));
                        ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= e($qz['titre']) ?></div>
                                    <div class="small text-muted">
                                        • <?= e($qz['type_quiz']) ?> /
                                        <span class="badge text-bg-secondary"><?= e($qz['format']) ?></span>
                                        <?php if ($teacher !== ''): ?> • Prof : <?= e($teacher) ?><?php endif; ?>
                                        <?php if ($limit): ?> <br> <span class="text-danger"> • Limite :
                                            <strong><?= e($limit) ?></strong></span><?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($isExpired): ?>

                                <span class="badge text-bg-danger">
                                    ⛔ Date dépassée
                                    <a class="btn btn-sm text-dark" style="background-color:#ffffff"
                                        href="<?= BASE_URL ?>/eleve/quiz_view.php?id=<?= (int)$qz['id'] ?>">
                                        Voir
                                    </a>
                                </span>

                                <?php else: ?>

                                <a class="btn btn-sm btn-primary"
                                    href="<?= BASE_URL ?>/eleve/quiz_submit.php?id=<?= (int)$qz['id'] ?>">
                                    Travailler / Remettre
                                </a>

                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Colonne FAITS -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Devoirs faits (déjà remis)</strong>
                    <span class="badge text-bg-success"><?= count($done) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$done): ?>
                    <div class="text-muted">Vous n’avez pas encore remis de devoir.</div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($done as $row): ?>
                        <?php
                          $qz   = $row['quiz'];
                          $sub  = $row['sub'];
                          $limit   = $qz['date_limite'] ? (string)$qz['date_limite'] : null;
                          $teacher = trim(($qz['nom'] ?? '').' '.($qz['postnom'] ?? '').' '.($qz['prenom'] ?? ''));
                          $note    = $sub['note_totale'] !== null
                                    ? number_format((float)$sub['note_totale'], 2, ',', ' ')
                                    : '—';
                        ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold">
                                        <?= e($qz['titre']) ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <?= badge_statut((string)$sub['statut']) ?>
                                            <?php if ($sub['statut'] === 'corrige' && $sub['note_totale'] !== null): ?>
                                            <span class="badge text-bg-primary">Note : <?= $note ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="small text-muted">
                                        •<?= e($qz['type_quiz']) ?> /
                                        <span class="badge text-bg-secondary"><?= e($qz['format']) ?></span>
                                        <br><?php if ($teacher !== ''): ?> • Prof : <?= e($teacher) ?><?php endif; ?>
                                        <div class="d-none"><?php if ($limit): ?> <br> <span class="text-danger"> •
                                                Limite :
                                                <strong><?= e($limit) ?></strong> </span><?php endif; ?></div>
                                        <br><span class="text-primary">• Remis le :
                                            <?= e($sub['date_submitted']) ?></span>
                                    </div>
                                </div>
                                <div class="d-flex flex-column flex-sm-row gap-2">
                                    <a class="btn btn-sm btn-outline-primary"
                                        href="<?= BASE_URL ?>/eleve/submission_view.php?id=<?= (int)$sub['id'] ?>">
                                        Voir ma soumission
                                    </a>
                                    <?php if ($qz['format'] === 'QCM' && $sub['statut'] === 'corrige'): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                        href="<?= BASE_URL ?>/eleve/quiz_view.php?id=<?= (int)$qz['id'] ?>">
                                        Détails correction
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION ANALYSES DETAILLEES -->
    <div class="row g-3 mt-4">
        <!-- Présence détaillée par mois -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong>Présence détaillée par mois</strong>
                </div>
                <div class="card-body">
                    <?php if (!$presenceByMonth): ?>
                    <div class="text-muted small">
                        Les présences par mois apparaîtront ici au fur et à mesure des appels.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mois</th>
                                    <th>Total</th>
                                    <th>Présent</th>
                                    <th>Absent</th>
                                    <th>Taux</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($presenceByMonth as $m): ?>
                                <tr>
                                    <td><?= e($m['ym']) ?></td>
                                    <td><?= (int)$m['total'] ?></td>
                                    <td><?= (int)$m['present'] ?></td>
                                    <td><?= (int)$m['absent'] ?></td>
                                    <td><?= $m['taux'] ?> %</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Historique des notes par cours -->
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <strong>Historique des notes par cours</strong>
                </div>
                <div class="card-body">
                    <?php if (!$notesByCourse): ?>
                    <div class="text-muted small">
                        Dès que des notes seront enregistrées, tu verras ici l’historique par matière.
                    </div>
                    <?php else: ?>
                    <div class="small text-muted mb-2">
                        Pour chaque cours, on affiche les dernières copies avec leur note.
                    </div>
                    <div class="accordion" id="histNotes">
                        <?php $idx=0; foreach ($notesByCourse as $titre=>$items): $idx++; ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="h<?= $idx ?>">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#c<?= $idx ?>">
                                    <?= e($titre) ?>
                                </button>
                            </h2>
                            <div id="c<?= $idx ?>" class="accordion-collapse collapse" data-bs-parent="#histNotes">
                                <div class="accordion-body p-2">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Statut</th>
                                                <th>Note</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($items as $it): ?>
                                            <tr>
                                                <td class="small text-muted"><?= e($it['date']) ?></td>
                                                <td class="small">
                                                    <?= badge_statut((string)$it['statut']) ?>
                                                </td>
                                                <td>
                                                    <?php if ($it['note'] === null): ?>
                                                    <span class="text-muted small">—</span>
                                                    <?php else: ?>
                                                    <?= number_format((float)$it['note'], 2, ',', ' ') ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphique de progression par période (optionnel / masqué) -->
    <div class="d-none row g-3 mt-3">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <strong>Progression par période (P1, P2, P3…)</strong>
                </div>
                <div class="card-body">
                    <?php if (!$periodStats): ?>
                    <div class="text-muted small">
                        Les périodes actives et les notes par période apparaîtront ici dès qu’il y aura des évaluations
                        corrigées.
                    </div>
                    <?php else: ?>
                    <div class="small text-muted mb-2">
                        Chaque barre représente la force de tes résultats pendant une période, par rapport aux autres
                        périodes.
                    </div>
                    <?php foreach ($periodStats as $ps):
              $p    = $ps['p'];
              $avg  = $ps['avg'];
              $cnt  = $ps['count'];
              $w    = $ps['width'];
            ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small">
                            <div>
                                <strong><?= e($p['CODE']) ?></strong>
                                <span class="text-muted">— <?= e($p['libelle']) ?></span>
                            </div>
                            <div class="text-muted">
                                <?= $cnt ?> devoir(s) corrigé(s)
                                <?php if ($avg !== null): ?>
                                • moyenne interne : <?= number_format($avg, 2, ',', ' ') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar" role="progressbar" style="width: <?= $w ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a class="btn btn-dark btn-sm" href="<?= BASE_URL ?>/dashboard.php">&larr; Retour tableau de bord</a>
    </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>