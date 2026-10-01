<?php
// /parent/layout/footer.php
declare(strict_types=1);

$unreadNotifCount = 0;
$recentPreviewItems = [];

if (isset($_SESSION['menage_id']) || isset($_SESSION['parent']['id'])) {
    $menageId = (int)($_SESSION['menage_id'] ?? ($_SESSION['parent']['id'] ?? 0));

    if ($menageId > 0 && (isset($pdo) || isset($con))) {
        $usePdo = isset($pdo);

        // 1) Élèves et leurs classes
        $enfantIds = [];
        $classeIds = [];

        try {
            $sqlEleves = "SELECT id, classe FROM eleve WHERE menage = ? AND STATUS = 'actif'";
            if ($usePdo) {
                $stmt = $pdo->prepare($sqlEleves);
                $stmt->execute([$menageId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $enfantIds[] = (int)$row['id'];
                    if (!empty($row['classe'])) $classeIds[] = (int)$row['classe'];
                }
            } else {
                $stmt = $con->prepare($sqlEleves);
                $stmt->bind_param('i', $menageId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $enfantIds[] = (int)$row['id'];
                    if (!empty($row['classe'])) $classeIds[] = (int)$row['classe'];
                }
                $stmt->close();
            }
        } catch (Throwable $e) {}

        $classeIds = array_filter(array_unique($classeIds));

        $allNotifs = [];
        $todayDate = date('Y-m-d'); // Date d'aujourd'hui uniquement

        // 2) Annonces du jour
        $whereAnnonces = ["a.dest_type = 'tous'", "a.dest_type = 'eleves'"];
        if (!empty($enfantIds)) {
            $whereAnnonces[] = "(a.dest_type = 'user' AND a.dest_id IN (" . implode(',', array_map('intval', $enfantIds)) . "))";
        }
        $sqlAnnonces = "SELECT a.titre AS label, '📢 Annonce' AS type_notif, a.created_at AS date_item FROM annonces a WHERE (" . implode(' OR ', $whereAnnonces) . ") AND DATE(a.created_at) = CURDATE() ORDER BY a.created_at DESC";

        try {
            if ($usePdo) {
                $annonces = $pdo->query($sqlAnnonces)->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $resA = $con->query($sqlAnnonces);
                $annonces = [];
                if ($resA) { while ($r = $resA->fetch_assoc()) $annonces[] = $r; }
            }
            foreach ($annonces as $a) {
                $allNotifs[] = $a;
            }
        } catch (Throwable $e) {}

        // 3) Quiz du jour
        if (!empty($classeIds)) {
            $inClasses = implode(',', array_map('intval', $classeIds));
            $sqlQuiz = "
                SELECT DISTINCT q.titre AS label, '📝 Quiz' AS type_notif, q.created_at AS date_item
                FROM quiz q
                INNER JOIN cours cr ON q.cours_id = cr.id
                INNER JOIN classe cl ON cr.classe_id = cl.id
                WHERE cl.id IN ($inClasses)
                  AND LOWER(q.statut) IN ('approuvé', 'approuve', 'valider', 'validé')
                  AND DATE(q.created_at) = CURDATE()
                ORDER BY q.id DESC
            ";
            try {
                if ($usePdo) {
                    $quizList = $pdo->query($sqlQuiz)->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $resQ = $con->query($sqlQuiz);
                    $quizList = [];
                    if ($resQ) { while ($r = $resQ->fetch_assoc()) $quizList[] = $r; }
                }
                foreach ($quizList as $q) {
                    $allNotifs[] = $q;
                }
            } catch (Throwable $e) {}
        }

        // 4) Journal de classe du jour
        if (!empty($classeIds)) {
            $inClasses = implode(',', array_map('intval', $classeIds));
            $sqlJournal = "
                SELECT j.matieres AS label, '📖 Journal' AS type_notif, COALESCE(j.jour_date, j.created_at) AS date_item
                FROM journal_classe j
                INNER JOIN classe cl ON j.classe_id = cl.id
                WHERE j.classe_id IN ($inClasses)
                  AND LOWER(j.statut) IN ('valider', 'validé', 'approuvé', 'approuve')
                  AND (DATE(j.jour_date) = CURDATE() OR DATE(j.created_at) = CURDATE())
                ORDER BY j.jour_date DESC, j.id DESC
            ";
            try {
                if ($usePdo) {
                    $journalList = $pdo->query($sqlJournal)->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $resJ = $con->query($sqlJournal);
                    $journalList = [];
                    if ($resJ) { while ($r = $resJ->fetch_assoc()) $journalList[] = $r; }
                }
                foreach ($journalList as $j) {
                    $allNotifs[] = $j;
                }
            } catch (Throwable $e) {}
        }

        // Trier les résultats du jour par heure
        usort($allNotifs, fn($a, $b) => strcmp((string)$b['date_item'], (string)$a['date_item']));

        $unreadNotifCount = count($allNotifs);
        $recentPreviewItems = array_slice($allNotifs, 0, 3);
    }
}
?>

<style>
/* Container principal */
#notif-container {
    position: fixed;
    bottom: 85px;
    right: 20px;
    z-index: 1050;
    padding-top: 10px;
}

/* Style des boutons flottants */
#whatsapp-assist, #notif-floating-btn {
    position: fixed;
    right: 20px;
    width: 55px;
    height: 55px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
    text-decoration: none;
    z-index: 1050;
    transition: transform 0.2s ease;
}

#whatsapp-assist {
    bottom: 20px;
    background-color: #25d35c;
    color: white;
}

#notif-floating-btn {
    position: relative;
    background-color: #0d6efd;
    color: white;
}

#whatsapp-assist:hover, #notif-floating-btn:hover {
    transform: scale(1.1);
    color: white;
}

/* Badge absolu */
#notif-floating-btn .badge-count {
    position: absolute;
    top: -2px;
    right: -2px;
    background-color: #dc3545;
    color: white;
    font-size: 11px;
    font-weight: bold;
    border-radius: 50%;
    min-width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #ffffff;
}

/* Fenêtre d'Aperçu Rapide (Popup) */
.notif-preview-box {
    position: absolute;
    bottom: 65px;
    right: 0;
    width: 290px;
    background: #ffffff;
    color: #333333;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    border: 1px solid #e3e6f0;
    padding: 12px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(10px);
    transition: opacity 0.25s ease, transform 0.25s ease, visibility 0.25s;
    z-index: 1060;
    text-align: left;
    pointer-events: auto;
}

/* Affichage au survol */
#notif-container:hover .notif-preview-box,
.notif-preview-box:hover {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.notif-preview-item {
    padding: 6px 0;
    border-bottom: 1px solid #f1f3f5;
    font-size: 12px;
}
.notif-preview-item:last-child {
    border-bottom: none;
}
</style>

<!-- Container Notification Flottant avec Aperçu -->
<div id="notif-container">
    
    <!-- Fenêtre d'aperçu au survol -->
    <div class="notif-preview-box">
        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
            <strong class="small text-primary">🔔 Aujourd'hui</strong>
            <span class="badge bg-danger rounded-pill"><?= $unreadNotifCount ?></span>
        </div>
        
        <?php if (!empty($recentPreviewItems)): ?>
            <?php foreach ($recentPreviewItems as $item): ?>
                <div class="notif-preview-item">
                    <div class="fw-bold text-dark mb-1">
                        <?= e((string)$item['type_notif']) ?>
                    </div>
                    <div class="text-truncate text-muted">
                        <?= e((string)$item['label']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="text-center mt-2 pt-2 border-top">
                <a href="<?= BASE_URL ?>/notification.php" class="btn btn-sm btn-outline-primary w-100 fw-bold">
                    Voir les autres jours →
                </a>
            </div>
        <?php else: ?>
            <div class="text-muted small text-center py-2">
                Aucune nouvelle publication aujourd'hui.
            </div>
            <div class="text-center mt-1 border-top pt-2">
                <a href="<?= BASE_URL ?>/notification.php" class="btn btn-sm btn-light w-100 fw-bold text-primary">
                    Consulter l'historique →
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Icône / Bouton Flottant -->
    <a id="notif-floating-btn" href="<?= BASE_URL ?>/notification.php" title="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" class="bi bi-bell-fill" viewBox="0 0 16 16">
            <path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2zm.995-14.901a1 1 0 1 0-1.99 0A5.002 5.002 0 0 0 3 6c0 1.098-.5 6-2 7h14c-1.5-1-2-5.902-2-7 0-2.42-1.72-4.44-4.005-4.901z"/>
        </svg>
        <?php if ($unreadNotifCount > 0): ?>
            <span class="badge-count"><?= $unreadNotifCount > 99 ? '99+' : $unreadNotifCount ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Bouton WhatsApp Assistance flottant -->
<a id="whatsapp-assist" href="https://wa.me/243980287578" target="_blank" title="Assistance en ligne (WhatsApp)">
    <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm.75 15h-1.5v-1.5h1.5V17zm1.35-5.85l-.85.85c-.2.2-.35.45-.35.75v.45h-1.5v-.5c0-.3.15-.55.35-.75l1-1c.2-.2.3-.45.3-.7 0-.55-.45-1-1-1s-1 .45-1 1H9c0-1.65 1.35-3 3-3s3 1.35 3 3c0 .7-.3 1.35-.9 1.9z" />
    </svg>
</a>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>