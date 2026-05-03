<?php
// /parent/annonces.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/includes/auth.php';
require_parent();

require_once __DIR__ . '/includes/helpers.php'; // e()
require_once __DIR__ . '/layout/header.php';
require_once __DIR__ . '/layout/navbar.php';

$flashOk = '';
$flashErr = '';

if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['_csrf'];

// ✅ Compatible avec ton login actuel
$menageId = (int)($_SESSION['menage_id'] ?? ($_SESSION['parent']['id'] ?? 0));

if ($menageId <= 0) {
  $flashErr = "Session parent invalide (menage_id manquant).";
}

// -------------------------------------------------
// Trouver le user directeur
// -------------------------------------------------
$directeurUserId = 0;
try {
  $directeurUserId = (int)$pdo->query("
    SELECT id
    FROM users
    WHERE LOWER(role) IN ('directeur','dir','direction')
    ORDER BY id ASC
    LIMIT 1
  ")->fetchColumn();
} catch (Throwable $e) {
  $directeurUserId = 0;
}

// -------------------------------------------------
// Enfants du ménage
// -------------------------------------------------
$enfants = [];
$enfantIds = [];

if ($menageId > 0) {
  $stKids = $pdo->prepare("
    SELECT id, nom, postnom, prenom, classe, anneeScolaire
    FROM eleve
    WHERE menage = ?
    ORDER BY id DESC
  ");
  $stKids->execute([$menageId]);
  $enfants = $stKids->fetchAll(PDO::FETCH_ASSOC);
  foreach ($enfants as $k) $enfantIds[] = (int)$k['id'];
}

// -------------------------------------------------
// POST: Parent -> Directeur (via table annonces)
// sender_role='eleve' et sender_id = eleve sélectionné
// dest_type='user' et dest_id = users.id du directeur
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['_csrf'] ?? '');
  if (!hash_equals($csrf, $token)) {
    $flashErr = "Jeton CSRF invalide.";
  } elseif ($menageId <= 0) {
    $flashErr = "Session parent invalide.";
  } elseif ($directeurUserId <= 0) {
    $flashErr = "Compte Directeur introuvable (table users.role).";
  } else {
    $eleveId = (int)($_POST['eleve_id'] ?? 0);
    $titre   = trim((string)($_POST['titre'] ?? ''));
    $contenu = trim((string)($_POST['contenu'] ?? ''));

    $okChild = in_array($eleveId, $enfantIds, true);

    if (!$okChild) {
      $flashErr = "Veuillez sélectionner un enfant valide.";
    } elseif (mb_strlen($titre) < 3) {
      $flashErr = "Titre trop court (min 3 caractères).";
    } elseif (mb_strlen($contenu) < 5) {
      $flashErr = "Message trop court (min 5 caractères).";
    } else {
      try {
        $st = $pdo->prepare("
          INSERT INTO annonces (titre, contenu, sender_role, sender_id, dest_type, dest_id)
          VALUES (:titre, :contenu, 'eleve', :sender_id, 'user', :dest_id)
        ");
        $st->execute([
          ':titre' => $titre,
          ':contenu' => $contenu,
          ':sender_id' => $eleveId,
          ':dest_id' => $directeurUserId,
        ]);
        $flashOk = "Message envoyé au Directeur.";
      } catch (Throwable $e) {
        $flashErr = "Erreur lors de l’envoi du message.";
      }
    }
  }
}

// -------------------------------------------------
// Charger annonces visibles parent
// -------------------------------------------------
$rows = [];
try {
  $where = [];
  $params = [];

  $where[] = "a.dest_type = 'tous'";
  $where[] = "a.dest_type = 'eleves'";

  if (!empty($enfantIds)) {
    $in = implode(',', array_fill(0, count($enfantIds), '?'));
    $where[] = "(a.dest_type = 'user' AND a.dest_id IN ($in))";
    $params = array_merge($params, $enfantIds);
  }

  $sql = "
    SELECT a.id, a.titre, a.contenu, a.sender_role, a.sender_id, a.dest_type, a.dest_id, a.created_at
    FROM annonces a
    WHERE (" . implode(' OR ', $where) . ")
    ORDER BY a.created_at DESC
    LIMIT 100
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $rows = [];
}
?>

<div class="container py-3">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h5 mb-0">Annonces</h1>
    <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#boxMsgDir">
      Écrire au Directeur
    </button>
  </div>

  <?php if ($flashOk): ?>
    <div class="alert alert-success"><?= e($flashOk) ?></div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="alert alert-danger"><?= e($flashErr) ?></div>
  <?php endif; ?>

  <div class="collapse mb-3" id="boxMsgDir">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-2">Message au Directeur</div>

        <?php if (!$enfants): ?>
          <div class="alert alert-warning mb-0">
            Aucun enfant lié à ce ménage.
          </div>
        <?php elseif ($directeurUserId <= 0): ?>
          <div class="alert alert-warning mb-0">
            Aucun Directeur trouvé dans <code>users.role</code>.
          </div>
        <?php else: ?>
          <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="col-12 col-md-4">
              <label class="form-label small text-muted">Enfant concerné</label>
              <select name="eleve_id" class="form-select" required>
                <option value="" disabled selected>— Choisir —</option>
                <?php foreach ($enfants as $k): ?>
                  <option value="<?= (int)$k['id'] ?>">
                    <?= e($k['nom'].' '.$k['postnom'].' '.$k['prenom']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-8">
              <label class="form-label small text-muted">Titre</label>
              <input type="text" name="titre" class="form-control" maxlength="150" required>
            </div>

            <div class="col-12">
              <label class="form-label small text-muted">Message</label>
              <textarea name="contenu" class="form-control" rows="4" required></textarea>
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end">
              <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#boxMsgDir">Fermer</button>
              <button type="submit" class="btn btn-primary">Envoyer</button>
            </div>
          </form>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info">Aucune annonce disponible.</div>
  <?php else: ?>
    <div class="list-group shadow-sm">
      <?php foreach ($rows as $a): ?>
        <?php
          $dest = (string)($a['dest_type'] ?? '');
          $destLabel = match ($dest) {
            'tous' => 'Public',
            'eleves' => 'Élèves (général)',
            'user' => 'Ciblé',
            default => $dest
          };

          $senderRole = (string)($a['sender_role'] ?? '');
          $senderLabel = match ($senderRole) {
            'directeur' => 'Direction',
            'prof' => 'Professeur',
            'eleve' => 'Élève',
            default => $senderRole
          };
        ?>
        <div class="list-group-item">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="me-2">
              <div class="fw-semibold"><?= e($a['titre']) ?></div>
              <div class="small text-muted">
                <span class="badge bg-light text-dark border"><?= e($destLabel) ?></span>
                <span class="ms-1">• <?= e($senderLabel) ?></span>
              </div>
            </div>
            <small class="text-muted text-nowrap"><?= e((string)$a['created_at']) ?></small>
          </div>
          <div class="mt-2"><?= nl2br(e($a['contenu'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/layout/footer.php'; ?>
