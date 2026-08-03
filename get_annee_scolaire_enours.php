<?php
// /get_annee_scolaire_encours.php
declare(strict_types=1);

// Inclusion du fichier de connexion
require_once __DIR__ . '/includes/db.php';

/**
 * Récupère l'année scolaire active en cours depuis la base de données.
 *
 * @param PDO $pdo
 * @return array|null
 */
function getAnneeScolaireEnCours(PDO $pdo): ?array {
    try {
        // Requête ajustée à votre colonne `status` = 'encours'
        $sql = "SELECT * 
                FROM annee_scolaire 
                WHERE status = 'encours' 
                ORDER BY id DESC 
                LIMIT 1";

        $stmt = $pdo->query($sql);
        $annee = $stmt->fetch();

        return $annee ?: null;
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de l'année scolaire : " . $e->getMessage());
        return null;
    }
}

// Exécution de la fonction
$anneeScolaireData = getAnneeScolaireEnCours($pdo);

// Extraction exacte selon votre colonne `annee_scolaire`
$ANNEE_SCOLAIRE_EN_COURS = $anneeScolaireData['annee_scolaire'] ?? null;
$ANNEE_SCOLAIRE_ID       = $anneeScolaireData['id'] ?? null;

// -------------------------------------------------------------
// AFFICHAGE & SORTIE
// -------------------------------------------------------------

// 1. Si le fichier est appelé DIRECTEMENT via son URL (AJAX / Fetch / Navigateur)
if (basename($_SERVER['SCRIPT_FILENAME']) === 'get_annee_scolaire_encours.php') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $ANNEE_SCOLAIRE_EN_COURS !== null,
        'id'      => $ANNEE_SCOLAIRE_ID,
        'annee'   => $ANNEE_SCOLAIRE_EN_COURS,
        'data'    => $anneeScolaireData
    ]);
    exit;
}

// 2. Si le fichier est INCLUS dans une autre page PHP (`require_once`)
// if ($ANNEE_SCOLAIRE_EN_COURS !== null) {
//     echo $ANNEE_SCOLAIRE_EN_COURS;
// }