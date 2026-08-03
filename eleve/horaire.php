<?php
// /parent/eleve/horaire.php
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

// 1) Vérification que l'élève appartient au ménage
$stmt = $pdo->prepare("
    SELECT e.id, e.nom, e.prenom, e.classe, c.description AS classe_desc
    FROM eleve e
    JOIN classe c ON c.id = e.classe
    WHERE e.id = :eid AND e.menage = :mid
    LIMIT 1
");
$stmt->execute([':eid' => $eid, ':mid' => $mid]);
$eleve = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eleve) {
    set_current_eleve(0);
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$classeId = (int)$eleve['classe'];

// 2) Filtre par type (Enum : 'Cours', 'Interrogation', 'Examen')
$allowedTypes = ['Cours', 'Interrogation', 'Examen'];
$filterType   = trim((string)($_GET['type'] ?? ''));

if (!in_array($filterType, $allowedTypes, true)) {
    $filterType = ''; // 'Tous' par défaut
}

// 3) Construction de la requête avec filtre
$params = [':classe_id' => $classeId];
$whereSql = "WHERE h.classe_id = :classe_id AND YEAR(h.created_at) = YEAR(CURRENT_DATE())";

if ($filterType !== '') {
    $whereSql .= " AND h.type = :type";
    $params[':type'] = $filterType;
}

$sqlHoraire = "
    SELECT 
        h.id,
        h.type,
        h.jour_semaine,
        h.date_evenement,
        h.heure_debut,
        h.heure_fin,
        c.intitule AS cours_nom,
        a.nom AS prof_nom,
        a.prenom AS prof_prenom
    FROM horaire h
    JOIN cours c ON c.id = h.cours_id
    LEFT JOIN agent a ON a.id = h.prof_id
    $whereSql
    ORDER BY 
        FIELD(h.jour_semaine, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'),
        h.heure_debut ASC
";
$st = $pdo->prepare($sqlHoraire);
$st->execute($params);
$rawHoraire = $st->fetchAll(PDO::FETCH_ASSOC);

// Structurer les données
$joursOrdered = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$horaireParJour = array_fill_keys($joursOrdered, []);
$evenementsSpeciaux = [];

foreach ($rawHoraire as $item) {
    if ($item['type'] === 'Cours') {
        $horaireParJour[$item['jour_semaine']][] = $item;
    } else {
        // Interrogations et Examens
        $evenementsSpeciaux[] = $item;
    }
}
?>

<div class="container py-4">
    <!-- En-tête -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h2 class="h3 mb-1"><i class="bi bi-calendar3 text-primary me-2"></i>Horaire des cours & épreuves</h2>
            <p class="text-muted mb-0">
                Élève :
                <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom'], ENT_QUOTES, 'UTF-8') ?></strong>
                | Classe : <span
                    class="badge text-bg-info"><?= htmlspecialchars($eleve['classe_desc'], ENT_QUOTES, 'UTF-8') ?></span>
            </p>
        </div>

        <!-- Boutons de filtrage rapide (d-flex) -->
        <div class="d-flex flex-wrap gap-2">
            <a href="?type="
                class="btn btn-sm <?= $filterType === '' ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                <i class="bi bi-grid-fill me-1"></i>Tous
            </a>
            <a href="?type=Cours"
                class="btn btn-sm <?= $filterType === 'Cours' ? 'btn-primary active' : 'btn-outline-secondary' ?>">
                <i class="bi bi-journal-text me-1"></i>Cours ordinaires
            </a>
            <a href="?type=Interrogation"
                class="btn btn-sm <?= $filterType === 'Interrogation' ? 'btn-warning active' : 'btn-outline-warning text-dark' ?>">
                <i class="bi bi-pencil-square me-1"></i>Interrogations
            </a>
            <a href="?type=Examen"
                class="btn btn-sm <?= $filterType === 'Examen' ? 'btn-danger active' : 'btn-outline-danger' ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Examens
            </a>
        </div>
    </div>

    <!-- 1. EMPLOI DU TEMPS HEBDOMADAIRE (COURS ORDINAIRES EN PREMIER) -->
    <?php if ($filterType === '' || $filterType === 'Cours'): ?>
    <div class="mb-5">
        <h4 class="h5 mb-3 text-secondary d-flex align-items-center">
            <i class="bi bi-calendar-week me-2 text-primary"></i>Emploi du temps hebdomadaire (Cours)
        </h4>
        <div class="row g-3">
            <?php foreach ($joursOrdered as $jour): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 bg-light">
                    <div
                        class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-calendar-day me-2"></i><?= $jour ?></span>
                        <span class="badge bg-white text-primary rounded-pill">
                            <?= count($horaireParJour[$jour]) ?> cours
                        </span>
                    </div>
                    <div class="card-body p-2">
                        <?php if (empty($horaireParJour[$jour])): ?>
                        <p class="text-muted text-center my-3 small">Aucun cours programmé</p>
                        <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($horaireParJour[$jour] as $cours): ?>
                            <div class="list-group-item rounded mb-2 border-0 shadow-sm bg-white">
                                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-clock me-1"></i>
                                        <?= date('H:i', strtotime($cours['heure_debut'])) ?> -
                                        <?= date('H:i', strtotime($cours['heure_fin'])) ?>
                                    </span>
                                </div>
                                <h6 class="mb-1 text-primary fw-bold">
                                    <?= htmlspecialchars($cours['cours_nom'], ENT_QUOTES, 'UTF-8') ?></h6>
                                <small class="text-muted">
                                    <i class="bi bi-person me-1"></i>
                                    <?= htmlspecialchars(trim(($cours['prof_prenom'] ?? '') . ' ' . ($cours['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'Non assigné' ?>
                                </small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 2. EXAMENS & INTERROGATIONS (EN-DESSOUS) -->
    <?php if (($filterType === '' || $filterType === 'Examen' || $filterType === 'Interrogation') && !empty($evenementsSpeciaux)): ?>
    <div class="card border-warning mb-4 shadow-sm">
        <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle-fill me-2"></i>Évaluations programmées (Examens &
                Interrogations)</span>
            <span class="badge bg-dark text-white"><?= count($evenementsSpeciaux) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Date de l'épreuve</th>
                            <th>Jour</th>
                            <th>Horaire</th>
                            <th>Matière</th>
                            <th>Enseignant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($evenementsSpeciaux as $ev): ?>
                        <tr>
                            <td>
                                <span class="badge text-bg-<?= $ev['type'] === 'Examen' ? 'danger' : 'warning' ?>">
                                    <?= htmlspecialchars($ev['type'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="fw-bold">
                                <?= $ev['date_evenement'] ? date('d/m/Y', strtotime($ev['date_evenement'])) : 'À préciser' ?>
                            </td>
                            <td><?= htmlspecialchars($ev['jour_semaine'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <i class="bi bi-clock me-1"></i>
                                <?= date('H:i', strtotime($ev['heure_debut'])) ?> -
                                <?= date('H:i', strtotime($ev['heure_fin'])) ?>
                            </td>
                            <td class="fw-semibold text-primary">
                                <?= htmlspecialchars($ev['cours_nom'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="small text-muted">
                                <?= htmlspecialchars(trim(($ev['prof_prenom'] ?? '') . ' ' . ($ev['prof_nom'] ?? '')), ENT_QUOTES, 'UTF-8') ?: 'N/A' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>