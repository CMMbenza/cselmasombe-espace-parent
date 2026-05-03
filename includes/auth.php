<?php
// /parent/includes/auth.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

// Anti-cache agressif
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';

// Helpers globaux si présents
$helpersLoaded = false;
foreach ([__DIR__ . '/../../includes/helpers.php', __DIR__ . '/../includes/helpers.php'] as $hf) {
  if (is_file($hf)) { require_once $hf; $helpersLoaded = true; break; }
}
// Fallback minimal si e()/redirect() non définis
if (!function_exists('e')) {
  function e(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('redirect')) {
  function redirect(string $path): void {
    header('Location: ' . $path); exit;
  }
}

if (!defined('BASE_URL')) {
  // BASE_URL du module parent
  define('BASE_URL', '/parent');
}

// Guards
function require_parent(): void {
  if (empty($_SESSION['parent']) || empty($_SESSION['parent']['id'])) {
    redirect(BASE_URL . '/login.php');
  }
}

// Élève courant (sélection)
function set_current_eleve(int $eleve_id): void {
  $_SESSION['parent_current_eleve'] = $eleve_id;
}
function get_current_eleve_id(): int {
  return (int)($_SESSION['parent_current_eleve'] ?? 0);
}
