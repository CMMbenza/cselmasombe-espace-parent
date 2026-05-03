<?php
// /parent/login.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/db.php';       // initialise $pdo
require_once __DIR__ . '/includes/helpers.php';  // fournit e(), redirect(), BASE_URL

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $menage_id = (int)($_POST['menage_id'] ?? 0);

    if ($menage_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM menage WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $menage_id]);
            $menage = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($menage) {
    $_SESSION['parent'] = $menage;

    // Si le ménage n’a pas d’email, on peut lui demander plus tard de l’ajouter
    $_SESSION['needs_email_verification'] = !empty($menage['email']); // true si email existe

    // Auto-sélection si un seul enfant
    $stmt2 = $pdo->prepare("SELECT id FROM eleve WHERE menage = :mid ORDER BY id");
    $stmt2->execute([':mid' => $menage_id]);
    $enfants = $stmt2->fetchAll(PDO::FETCH_COLUMN);

    if (count($enfants) === 1) {
        $_SESSION['eleve_id'] = (int)$enfants[0];
    }

    redirect('dashboard.php');
} else {
                $error = "Ménage introuvable.";
            }
        } catch (Throwable $e) {
            $error = "Erreur de connexion : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez entrer un ID valide.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Connexion Parent</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap (CDN) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
<!-- Bouton WhatsApp Assistance flottant -->
<style>
#whatsapp-assist {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background-color: #25d35c;
    color: white;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
    text-decoration: none;
    z-index: 1000;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

#whatsapp-assist:hover {
    transform: scale(1.15);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
}

#whatsapp-tooltip {
    position: absolute;
    bottom: 70px;
    right: 0;
    background: #333;
    color: #fff;
    padding: 6px 10px;
    border-radius: 4px;
    font-size: 13px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

#whatsapp-assist:hover #whatsapp-tooltip {
    opacity: 1;
}
</style>
</head>

<body class="bg-light">
    <div class="container my-5" style="max-width: 520px;">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h5 mb-3">CS Elma Sombe - Connexion Parent/Elève</h1>

                <?php if ($error): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <div class="mb-3">
                        <label class="form-label">ID Ménage/Code famille : </label>
                        <div class="input-group">
                            <input type="password" name="menage_id" class="form-control"
                                placeholder="Veuillez entrer code famille" required>
                            <button class="btn btn-outline-secondary" type="button" id="togglePwd"
                                aria-label="Afficher/Masquer">👁</button>
                        </div>
                        <!-- <div class="form-text">Entrez l'ID fourni par l'établissement (table <code>menage</code>).</div> -->
                    </div>
                    <button class="btn btn-primary w-100">Se connecter</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    const input = document.querySelector('input[name="menage_id"]');
    document.getElementById('togglePwd')?.addEventListener('click', () => {
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    });

    const input = document.querySelector('input[name="menage_id"]');
    document.getElementById('togglePwd')?.addEventListener('click', () => {
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
    });
    </script>

<a id="whatsapp-assist" href="https://wa.me/243980287578" target="_blank" title="Assistance enligne(Whatsapp)">
    <!-- Ic么ne assistance/service (SVG) -->
    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" viewBox="0 0 24 24">
        <path
            d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 
                 10-4.48 10-10S17.52 2 12 2zm.75 15h-1.5v-1.5h1.5V17zm1.35-5.85l-.85.85c-.2.2-.35.45-.35.75v.45h-1.5v-.5c0-.3.15-.55.35-.75l1-1c.2-.2.3-.45.3-.7 0-.55-.45-1-1-1s-1 .45-1 1H9c0-1.65 1.35-3 3-3s3 1.35 3 3c0 .7-.3 1.35-.9 1.9z" />
    </svg>
    <div id="whatsapp-tooltip">Assistance enligne(Whatsapp)</div>
</a>
</body>

</html>