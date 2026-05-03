<?php
// /parent/layout/navbar.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../includes/auth.php';

$uriPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
function is_active(array|string $needles, string $haystack): string {
  $needles = is_array($needles) ? $needles : [$needles];
  foreach ($needles as $n) if ($n !== '' && strpos($haystack, $n) !== false) return 'active';
  return '';
}
function aria_current(string $class): string { return $class==='active' ? 'aria-current="page"' : ''; }

$childSelected = get_current_eleve_id() > 0;
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom mb-3">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/dashboard.php">Parent</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pnav"><span class="navbar-toggler-icon"></span></button>

    <div id="pnav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <?php $a = is_active(['/dashboard.php'], $uriPath); ?>
        <li class="nav-item">
          <a class="nav-link <?= $a ?>" <?= aria_current($a) ?> href="<?= BASE_URL ?>/dashboard.php">Dashboard</a>
        </li>

        <?php $a = is_active(['/enfants.php'], $uriPath); ?>
        <li class="nav-item">
          <a class="nav-link <?= $a ?>" <?= aria_current($a) ?> href="<?= BASE_URL ?>/enfants.php">Mes enfants</a>
        </li>

        <?php $a = is_active(['/finances.php'], $uriPath); ?>
        <li class="nav-item">
          <a class="nav-link <?= $a ?>" <?= aria_current($a) ?> href="<?= BASE_URL ?>/finances.php">Situation financière</a>
        </li>

        <?php $a = is_active(['/annonces.php'], $uriPath); ?>
        <li class="nav-item">
          <a class="nav-link <?= $a ?>" <?= aria_current($a) ?> href="<?= BASE_URL ?>/annonces.php">Annonces</a>
        </li>

        <!--<?php if ($childSelected): ?>-->
        <!--  <?php $a = is_active(['/eleve/'], $uriPath); ?>-->
        <!--  <li class="nav-item dropdown">-->
        <!--    <a class="nav-link dropdown-toggle <?= $a ?>" href="#" data-bs-toggle="dropdown">Espace élève</a>-->
        <!--    <ul class="dropdown-menu">-->
        <!--      <li><a class="dropdown-item" href="<?= BASE_URL ?>/eleve/classe.php">Ma classe & profs</a></li>-->
        <!--      <li><a class="dropdown-item" href="<?= BASE_URL ?>/eleve/quizzes.php">Quiz approuvés</a></li>-->
        <!--      <li><a class="dropdown-item" href="<?= BASE_URL ?>/eleve/my_submissions.php">Mes Quiz</a></li>-->
        <!--    </ul>-->
        <!--  </li>-->
        <!--<?php endif; ?>-->

      </ul>

      <div class="d-flex align-items-center gap-2">
        <div class="text-end me-2">
          <div class="fw-semibold small"><?= e($_SESSION['parent']['noms'] ?? '') ?></div>
          <div class="d-none text-muted small">Ménage #<?= e((string)($_SESSION['parent']['id'] ?? '')) ?></div>
        </div>
        <a class="btn btn-outline-danger btn-sm" href="<?= BASE_URL ?>/logout.php">Déconnexion</a>
      </div>
    </div>
  </div>
</nav>
