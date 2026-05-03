<?php
// /parent/includes/helpers.php
declare(strict_types=1);

/**
 * Echappe du texte pour l'affichage HTML.
 */
function e(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirection simple puis exit.
 */
function redirect(string $path): void {
  header('Location: ' . $path);
  exit;
}

/**
 * BASE_URL du module parent (si besoin dans tes vues).
 * Tu peux adapter la valeur si ton dossier parent n'est pas à la racine.
 */
if (!defined('BASE_URL')) {
  define('BASE_URL', '/parent');
}
