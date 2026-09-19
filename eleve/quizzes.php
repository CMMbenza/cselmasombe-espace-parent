<?php
// /parent/eleve/quizzes.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_parent();
require_once __DIR__ . '/../get_annee_scolaire_enours.php';
require_once __DIR__ . '/../layout/header.php';
require_once __DIR__ . '/../layout/navbar.php';

// Récupération globale de l'année scolaire en cours (ex: "2026-2027")
$anneeScolaire = $ANNEE_SCOLAIRE_EN_COURS ?? null;

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
    c.cycle       AS cycle_id,
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

// Mois courant au format YYYY-MM
$currentYm = date('Y-m');

$pSql = "
  SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS present,
    SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absent
  FROM appel_detail ad
  JOIN appel a ON a.id = ad.appel_id
  WHERE ad.eleve_id = :eid
    AND DATE_FORMAT(a.date_appel, '%Y-%m') = :ym
";

$pParams = [':eid' => $eid, ':ym' => $currentYm];

if (!empty($anneeScolaire)) {
  $pSql .= " AND a.anneeScolaire = :annee ";
  $pParams[':annee'] = $anneeScolaire;
}

$pStmt = $pdo->prepare($pSql);
$pStmt->execute($pParams);
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
// 1.bis) Présence détaillée par mois (derniers mois de l'année scolaire)
// ===============================
$presenceByMonth = [];

$pmSql = "
  SELECT 
    DATE_FORMAT(a.date_appel, '%Y-%m') AS ym,
    COUNT(*) AS total,
    SUM(CASE WHEN ad.statut = 'present' THEN 1 ELSE 0 END) AS present,
    SUM(CASE WHEN ad.statut = 'absent'  THEN 1 ELSE 0 END) AS absent
  FROM appel_detail ad
  JOIN appel a ON a.id = ad.appel_id
  WHERE ad.eleve_id = :eid
";

$pmParams = [':eid' => $eid];

if (!empty($anneeScolaire)) {
  $pmSql .= " AND a.anneeScolaire = :annee ";
  $pmParams[':annee'] = $anneeScolaire;
}

$pmSql .= " GROUP BY ym ORDER BY ym DESC LIMIT 6";

$pmStmt = $pdo->prepare($pmSql);
$pmStmt->execute($pmParams);

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
// 2) Chargement des quiz de la classe pour l'année en cours
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

if (!empty($anneeScolaire)) {
  $sql .= " AND q.anneeScolaire = :annee ";
  $params[':annee'] = $anneeScolaire;
}

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

// ===============================
// 4) Split : à faire / faits / expirés
// ===============================
$toDo = [];
$done = [];
$expired = [];

foreach ($quizzes as $qz) {
  $qid = (int)$qz['id'];

  if (isset($subsByQuiz[$qid])) {
    $done[] = [
      'quiz' => $qz,
      'sub'  => $subsByQuiz[$qid]
    ];
    continue;
  }

  $isExpired = false;
  if (!empty($qz['date_limite'])) {
    $isExpired = strtotime($qz['date_limite'].' 23:59:59') < time();
  }

  if ($isExpired) {
    $expired[] = $qz;
  } else {
    $toDo[] = $qz;
  }
}

// ===============================
// 5) Stats devoirs
// ===============================
$totalDevoirs = count($quizzes);
$totalAFaire   = count($toDo);
$totalRemis    = count($done);
$totalExpired = count($expired);

// ===============================
// PAGINATION
// ===============================
$perPage = 5;

$pageTodo = max(1, (int)($_GET['page_todo'] ?? 1));
$totalTodoPages = max(1, (int)ceil(count($toDo) / $perPage));
$offsetTodo = ($pageTodo - 1) * $perPage;
$toDoPaginated = array_slice($toDo, $offsetTodo, $perPage);

$pageDone = max(1, (int)($_GET['page_done'] ?? 1));
$totalDonePages = max(1, (int)ceil(count($done) / $perPage));
$offsetDone = ($pageDone - 1) * $perPage;
$donePaginated = array_slice($done, $offsetDone, $perPage);

$pageExpired = max(1, (int)($_GET['page_expired'] ?? 1));
$totalExpiredPages = max(1, (int)ceil(count($expired) / $perPage));
$offsetExpired = ($pageExpired - 1) * $perPage;
$expiredPaginated = array_slice($expired, $offsetExpired, $perPage);

// ===============================
// 6) Historique des notes par cours + Forces
// ===============================
$notesByCourse = [];
$forces        = [];

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
$notesByPeriod = [];

$cycleId = (int)($el['cycle_id'] ?? 0);
if ($cycleId > 0) {
  $pSql2 = "
    SELECT id, CODE, libelle
    FROM periodes
    WHERE cycle_id = :cid AND actif = 1
  ";
  $pParams2 = [':cid' => $cycleId];

  if (!empty($anneeScolaire)) {
    $pSql2 .= " AND anneeScolaire = :annee ";
    $pParams2[':annee'] = $anneeScolaire;
  }

  $pSql2 .= " ORDER BY ordre";

  $pStmt2 = $pdo->prepare($pSql2);
  $pStmt2->execute($pParams2);
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
                <a class="btn btn-dark btn-sm" href="<?= BASE_URL ?>/dashboard.php">&larr; Retour tableau de bord</a>
                <!-- <a href="../eleve/classe.php" class="btn btn-outline-primary btn-sm">Ma classe & professeurs</a> -->
                <a href="../eleve/journal.php" class="btn btn-outline-primary btn-sm">Journal de classe</a>
                <a href="../eleve/horaire.php" class="btn btn-outline-primary btn-sm">Horaire de classe</a>
                <a href="../eleve/cours.php" class="btn btn-outline-primary btn-sm">Cours/Resumé</a>
                <!-- <a href="../eleve/quizzes.php" class="btn btn-primary btn-sm">Travail à faire</a> -->
                <!-- <a href="../eleve/my_submissions.php" class="btn btn-outline-secondary btn-sm">Mes travaux remis</a> -->
                <a href="../eleve/quizzes_liste.php" class="btn btn-outline-secondary btn-sm">Mes travaux</a>
            </div>
        </div>
    </div>

    <!-- ========================= -->
    <!-- STATISTIQUES -->
    <!-- ========================= -->

    <div class="row g-4 mb-4">

        <!-- PRESENCE -->
        <div class="col-lg-4 col-md-6">

            <div class="card border-0 shadow-sm rounded-4 h-100">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div>
                            <div class="text-uppercase small fw-semibold text-muted">
                                📅 Présence mensuelle
                            </div>

                            <div class="small text-muted">
                                Mois : <?= e($currentYm) ?>
                            </div>
                        </div>

                        <span class="badge bg-light text-dark border">
                            <?= (int)$presence['total'] ?> jours
                        </span>

                    </div>

                    <div class="display-6 fw-bold text-success mb-1">
                        <?= $presence['taux'] ?>%
                    </div>

                    <div class="small text-muted mb-3">
                        Taux de présence ce mois-ci
                    </div>

                    <div class="d-flex justify-content-between small mb-2">

                        <span>
                            ✅ Présent :
                            <strong><?= (int)$presence['present'] ?></strong>
                        </span>

                        <span>
                            ❌ Absent :
                            <strong><?= (int)$presence['absent'] ?></strong>
                        </span>

                    </div>

                    <div class="progress rounded-pill" style="height:8px;">

                        <div class="progress-bar bg-success" role="progressbar"
                            style="width: <?= max(0,min(100,(float)$presence['taux'])) ?>%;">

                        </div>

                    </div>

                    <div class="text-center mt-3">
                        <a href="presences.php" class="btn btn-primary w-100">Mes présences</a>
                    </div>

                </div>

            </div>

        </div>

        <!-- DEVOIRS -->
        <div class="col-lg-4 col-md-6">

            <div class="card border-0 shadow-sm rounded-4 h-100">

                <div class="card-body p-4">

                    <div class="text-uppercase small fw-semibold text-muted mb-2">
                        📝 Devoirs
                    </div>

                    <div class="display-6 fw-bold text-primary mb-1">
                        <?= (int)$totalDevoirs ?>
                    </div>

                    <div class="small text-muted mb-3">
                        devoir(s) publié(s)
                    </div>

                    <div class="d-flex flex-column gap-2 small">

                        <div class="d-flex justify-content-between align-items-center">
                            <span>📌 À faire</span>
                            <span class="badge bg-primary rounded-pill">
                                <?= (int)$totalAFaire ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <span>✅ Déjà remis</span>
                            <span class="badge bg-success rounded-pill">
                                <?= (int)$totalRemis ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <span>⛔ Expirés</span>
                            <span class="badge bg-danger rounded-pill">
                                <?= count($expired) ?>
                            </span>
                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- FORCES -->
        <div class="col-lg-4 col-md-12">

            <div class="card border-0 shadow-sm rounded-4 h-100">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between align-items-center mb-3">

                        <div class="text-uppercase small fw-semibold text-muted">
                            💪 Forces actuelles
                        </div>

                        <?php if (count($topForces) > 2): ?>

                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                            id="toggleForcesBtn">

                            Voir plus

                        </button>

                        <?php endif; ?>

                    </div>

                    <?php if ($topForces): ?>

                    <div class="small text-muted mb-3">
                        Matières où tu sembles le plus à l’aise :
                    </div>

                    <div class="d-flex flex-column gap-2">

                        <?php foreach ($topForces as $index => $f): ?>

                        <div class="border rounded-3 p-2 bg-light <?= $index >= 2 ? 'extra-force d-none' : '' ?>">

                            <div class="fw-semibold text-dark">
                                <?= e($f['titre']) ?>
                            </div>

                            <div class="small text-muted">
                                Basé sur <?= (int)$f['count'] ?> devoir(s) corrigé(s)
                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                    <?php if (count($topForces) > 2): ?>

                    <!-- <button type="button" class="btn btn-sm btn-outline-primary mt-3" id="toggleForcesBtn">

                        Voir plus

                    </button> -->

                    <?php endif; ?>

                    <?php else: ?>

                    <div class="text-muted small">
                        📊 Dès que certains devoirs seront corrigés,
                        tu verras ici les matières où tu es le plus fort.
                    </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <script>
        const toggleBtn = document.getElementById('toggleForcesBtn');

        if (toggleBtn) {

            toggleBtn.addEventListener('click', function() {

                const hiddenItems = document.querySelectorAll('.extra-force');

                const isHidden = hiddenItems[0].classList.contains('d-none');

                hiddenItems.forEach(item => {
                    item.classList.toggle('d-none');
                });

                this.textContent = isHidden ?
                    'Voir moins' :
                    'Voir plus';

            });

        }
        </script>

    </div>

    <!-- Deux colonnes : A faire / Déjà remis -->
    <div class="row g-3">
        <!-- Colonne A FAIRE -->
        <div class="col-lg-4 col-md-12">
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
                        <?php foreach ($toDoPaginated as $qz): ?>
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
                        <div class="list-group-item border-0 shadow-sm rounded-4 p-3 mb-3">
                            <!-- TITRE -->
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <h6 class="mb-0 fw-bold text-dark">
                                    <?= e($qz['titre']) ?>
                                </h6>

                                <?php if ($isExpired): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                    ⛔ Date dépassée
                                </span>
                                <?php endif; ?>

                            </div>
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">

                                <!-- INFOS -->
                                <div class="flex-grow-1">
                                    <!-- DETAILS -->
                                    <div class="small text-muted d-flex flex-column gap-1">

                                        <div class="d-flex flex-wrap align-items-center gap-2">

                                            <span>
                                                📚 <?= e($qz['type_quiz']) ?>
                                            </span>

                                            <span class="badge bg-secondary-subtle text-secondary">
                                                <?= e($qz['format']) ?>
                                            </span>

                                        </div>

                                        <?php if ($teacher !== ''): ?>
                                        <div>
                                            👨‍🏫 Prof :
                                            <span class="fw-medium text-dark">
                                                <?= e($teacher) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($limit): ?>
                                        <div class="text-danger">
                                            ⏰ Limite :
                                            <strong><?= e($limit) ?></strong>
                                        </div>
                                        <?php endif; ?>

                                    </div>

                                </div>

                                <!-- ACTION -->
                                <div class="d-flex align-items-center">

                                    <?php if ($isExpired): ?>

                                    <a class="btn btn-sm btn-outline-danger rounded-3 px-3"
                                        href="<?= BASE_URL ?>/eleve/quiz_view.php?id=<?= (int)$qz['id'] ?>">

                                        Voir le quiz

                                    </a>

                                    <?php else: ?>

                                    <a class="btn btn-sm btn-primary rounded-3 px-3"
                                        href="<?= BASE_URL ?>/eleve/quiz_submit.php?id=<?= (int)$qz['id'] ?>">

                                        Travailler / Remettre

                                    </a>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($totalTodoPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center flex-wrap">

                            <?php for($i=1; $i<=$totalTodoPages; $i++): ?>

                            <li class="page-item <?= $i == $pageTodo ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="?page_todo=<?= $i ?>&page_done=<?= $pageDone ?>&page_expired=<?= $pageExpired ?>&q=<?= urlencode($q) ?>">
                                    <?= $i ?>
                                </a>
                            </li>

                            <?php endfor; ?>

                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Colonne FAITS -->
        <div class="col-lg-4 col-md-12">
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
                        <?php foreach ($donePaginated as $row): ?>
                        <?php
                          $qz   = $row['quiz'];
                          $sub  = $row['sub'];
                          $limit   = $qz['date_limite'] ? (string)$qz['date_limite'] : null;
                          $teacher = trim(($qz['nom'] ?? '').' '.($qz['postnom'] ?? '').' '.($qz['prenom'] ?? ''));
                          $note    = $sub['note_totale'] !== null
                                    ? number_format((float)$sub['note_totale'], 2, ',', ' ')
                                    : '—';
                        ?>
                        <div class="list-group-item border-0 shadow-sm rounded-4 p-3 mb-3">
                            <!-- TITRE -->
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">

                                <h6 class="mb-0 fw-bold text-dark">
                                    <?= e($qz['titre']) ?>
                                </h6>

                                <?= badge_statut((string)$sub['statut']) ?>

                                <?php if ($sub['statut'] === 'corrige' && $sub['note_totale'] !== null): ?>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                    📝 Note : <?= $note ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">

                                <!-- INFOS -->
                                <div class="flex-grow-1">

                                    <!-- META -->
                                    <div class="small text-muted d-flex flex-column gap-1">

                                        <div class="d-flex flex-wrap align-items-center gap-2">

                                            <span>
                                                📚 <?= e($qz['type_quiz']) ?>
                                            </span>

                                            <span class="badge bg-secondary-subtle text-secondary">
                                                <?= e($qz['format']) ?>
                                            </span>

                                        </div>

                                        <?php if ($teacher !== ''): ?>
                                        <div>
                                            👨‍🏫 Prof :
                                            <span class="fw-medium text-dark">
                                                <?= e($teacher) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($limit): ?>
                                        <div class="text-danger">
                                            ⏰ Limite :
                                            <strong><?= e($limit) ?></strong>
                                        </div>
                                        <?php endif; ?>

                                        <div class="text-primary">
                                            📅 Remis le :
                                            <?= e($sub['date_submitted']) ?>
                                        </div>

                                    </div>

                                </div>

                                <!-- ACTIONS -->
                                <div class="d-flex flex-column gap-2 justify-content-center">

                                    <a class="btn btn-sm btn-primary rounded-3 px-3"
                                        href="<?= BASE_URL ?>/eleve/submission_view.php?id=<?= (int)$sub['id'] ?>">

                                        Voir ma soumission

                                    </a>

                                    <?php if ($qz['format'] === 'QCM' && $sub['statut'] === 'corrige'): ?>

                                    <a class="btn btn-sm btn-outline-secondary rounded-3 px-3"
                                        href="<?= BASE_URL ?>/eleve/quiz_view.php?id=<?= (int)$qz['id'] ?>">

                                        Voir correction

                                    </a>

                                    <?php endif; ?>

                                </div>

                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($totalDonePages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center flex-wrap">

                            <?php for($i=1; $i<=$totalDonePages; $i++): ?>

                            <li class="page-item <?= $i == $pageDone ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="?page_todo=<?= $pageTodo ?>&page_done=<?= $i ?>&page_expired=<?= $pageExpired ?>&q=<?= urlencode($q) ?>">
                                    <?= $i ?>
                                </a>
                            </li>

                            <?php endfor; ?>

                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 col-md-12">
            <div class="card shadow-sm border-danger h-100">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <strong>Devoirs expirés</strong>
                    <span class="badge text-bg-light text-danger">
                        <?= count($expired) ?>
                    </span>
                </div>

                <div class="card-body">

                    <?php if (!$expired): ?>

                    <div class="text-muted">
                        Aucun devoir expiré.
                    </div>

                    <?php else: ?>

                    <div class="list-group list-group-flush">

                        <?php foreach ($expiredPaginated as $qz): ?>

                        <?php
                    $limit = $qz['date_limite']
                        ? (string)$qz['date_limite']
                        : null;

                    $teacher = trim(
                        ($qz['nom'] ?? '').' '.
                        ($qz['postnom'] ?? '').' '.
                        ($qz['prenom'] ?? '')
                    );
                ?>

                        <div class="list-group-item border-0 shadow-sm rounded-4 p-3 mb-3">
                            <!-- TITRE -->
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">

                                <h6 class="mb-0 fw-bold text-danger">
                                    <?= e($qz['titre']) ?>
                                </h6>

                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                                    ⛔ Expiré
                                </span>

                            </div>
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">

                                <!-- INFOS -->
                                <div class="flex-grow-1">

                                    <!-- DETAILS -->
                                    <div class="small text-muted d-flex flex-column gap-1">

                                        <div class="d-flex flex-wrap align-items-center gap-2">

                                            <span>
                                                📚 <?= e($qz['type_quiz']) ?>
                                            </span>

                                            <span class="badge bg-secondary-subtle text-secondary">
                                                <?= e($qz['format']) ?>
                                            </span>

                                        </div>

                                        <?php if ($teacher !== ''): ?>
                                        <div>
                                            👨‍🏫 Prof :
                                            <span class="fw-medium text-dark">
                                                <?= e($teacher) ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($limit): ?>
                                        <div class="text-danger">
                                            ⏰ Expiré depuis :
                                            <strong><?= e($limit) ?></strong>
                                        </div>
                                        <?php endif; ?>

                                    </div>

                                </div>

                                <!-- ACTION -->
                                <div class="d-flex align-items-center">
                                    <a class="btn btn-sm btn-danger rounded-3 px-3"
                                        href="<?= BASE_URL ?>/eleve/quiz_view.php?id=<?= (int)$qz['id'] ?>">
                                        Voir
                                    </a>

                                </div>

                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                    <?php endif; ?>
                    <?php if ($totalExpiredPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm justify-content-center flex-wrap">

                            <?php for($i=1; $i<=$totalExpiredPages; $i++): ?>

                            <li class="page-item <?= $i == $pageExpired ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="?page_todo=<?= $pageTodo ?>&page_done=<?= $pageDone ?>&page_expired=<?= $i ?>&q=<?= urlencode($q) ?>">
                                    <?= $i ?>
                                </a>
                            </li>

                            <?php endfor; ?>

                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3 g-3">
        <!-- Colonne EXPIRES -->
    </div>

    <!-- SECTION ANALYSES DETAILLEES -->
    <div class="row g-3 mt-4">
        <!-- Présence détaillée par mois -->
        <div class="col-lg-6">

            <div class="card border-0 shadow-sm rounded-4 h-100">

                <div class="card-header bg-white border-0 p-4">

                    <strong class="text-dark">
                        📅 Présence détaillée par mois
                    </strong>

                </div>

                <div class="card-body p-4">

                    <?php if (!$presenceByMonth): ?>

                    <div class="text-muted small">
                        Les présences par mois apparaîtront ici au fur et à mesure des appels.
                    </div>

                    <?php else: ?>

                    <div class="d-flex flex-column gap-3">

                        <?php foreach ($presenceByMonth as $index => $m): ?>

                        <div
                            class="border rounded-4 p-3 bg-light presence-item <?= $index >= 3 ? 'extra-month d-none' : '' ?>">

                            <!-- HEADER -->
                            <div class="d-flex justify-content-between align-items-center mb-2">

                                <div class="fw-semibold">
                                    📆 <?= e($m['ym']) ?>
                                </div>

                                <span class="badge bg-primary-subtle text-primary border">
                                    <?= $m['taux'] ?>%
                                </span>

                            </div>

                            <!-- DETAILS -->
                            <div class="d-flex justify-content-between small text-muted mb-2">

                                <span>✅ Présent : <strong><?= (int)$m['present'] ?></strong></span>

                                <span>❌ Absent : <strong><?= (int)$m['absent'] ?></strong></span>

                                <span>📊 Total : <strong><?= (int)$m['total'] ?></strong></span>

                            </div>

                            <!-- BAR -->
                            <div class="progress rounded-pill" style="height:6px;">

                                <div class="progress-bar bg-success"
                                    style="width: <?= max(0, min(100, (float)$m['taux'])) ?>%;">

                                </div>

                            </div>

                        </div>

                        <?php endforeach; ?>

                    </div>

                    <?php if (count($presenceByMonth) > 3): ?>

                    <div class="text-center mt-3">

                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                            id="togglePresenceBtn">

                            Voir plus

                        </button>

                    </div>

                    <?php endif; ?>

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

                        <?php $idx = 0; foreach ($notesByCourse as $titre => $items): $idx++; ?>

                        <div class="accordion-item course-item <?= $idx > 3 ? 'extra-course d-none' : '' ?>">

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

                                                <td class="small text-muted">
                                                    <?= e($it['date']) ?>
                                                </td>

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

                    <?php if (count($notesByCourse) > 3): ?>

                    <div class="text-center mt-3">

                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                            id="toggleCoursesBtn">

                            Voir plus

                        </button>

                    </div>

                    <?php endif; ?>

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

    <div class="mt-5 mb-5">
        <a class="btn btn-dark btn-sm" href="<?= BASE_URL ?>/dashboard.php">&larr; Retour tableau de bord</a>
    </div>
</div>

<script>
const btnPresence = document.getElementById('togglePresenceBtn');

if (btnPresence) {

    btnPresence.addEventListener('click', function() {

        const items = document.querySelectorAll('.extra-month');

        const isHidden = items[0].classList.contains('d-none');

        items.forEach(el => {
            el.classList.toggle('d-none');
        });

        this.textContent = isHidden ? 'Voir moins' : 'Voir plus';

    });

}

const btn = document.getElementById('toggleCoursesBtn');

if (btn) {

    btn.addEventListener('click', function() {

        const hidden = document.querySelectorAll('.extra-course');

        const isHidden = hidden[0].classList.contains('d-none');

        hidden.forEach(el => {
            el.classList.toggle('d-none');
        });

        this.textContent = isHidden ? 'Voir moins' : 'Voir plus';

    });

}
</script>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>