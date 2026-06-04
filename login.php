<?php
// /parent/login.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$error = '';
$success = '';

$showPasswordForm = false;
$showCompleteForm = false;
$menageData = null;

// ======================================
// CONNEXION
// ======================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // ======================================
    // ETAPE 1 : VERIFICATION CODE FAMILLE
    // ======================================
    if ($action === 'check_code') {

        $menage_id = (int)($_POST['menage_id'] ?? 0);

        if ($menage_id <= 0) {
            $error = "Veuillez entrer un code famille valide.";
        } else {

            $stmt = $pdo->prepare("SELECT * FROM menage WHERE id = ?");
            $stmt->execute([$menage_id]);

            $menage = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$menage) {

                $error = "Code famille introuvable.";

            } else {

                $menageData = $menage;

                // SI PASSWORD VIDE
                if (empty($menage['password'])) {

                    $showCompleteForm = true;

                } else {

                    $showPasswordForm = true;
                }
            }
        }
    }

    // ======================================
    // ETAPE 2 : COMPLETER LE COMPTE
    // ======================================
    if ($action === 'complete_account') {

        $menage_id = (int)($_POST['menage_id'] ?? 0);
        $email = 'informatique@cselmasombe.org';
        $password = trim($_POST['password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM menage WHERE id=?");
        $stmt->execute([$menage_id]);

        $menage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$menage) {

            $error = "Compte introuvable.";

        } elseif (strlen($password) < 4) {

            $error = "Le mot de passe doit avoir au moins 4 caractères.";
            $showCompleteForm = true;
            $menageData = $menage;

        } elseif ($password !== $confirm) {

            $error = "Les mots de passe ne correspondent pas.";
            $showCompleteForm = true;
            $menageData = $menage;

        } else {

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $up = $pdo->prepare("
                UPDATE menage
                SET email = ?, password = ?
                WHERE id = ?
            ");

            $up->execute([$email, $hash, $menage_id]);

            // LOGIN DIRECT
            $_SESSION['parent'] = $menage;

            // AUTO SELECTION ENFANT
            $stmt2 = $pdo->prepare("
                SELECT id
                FROM eleve
                WHERE menage = ?
                ORDER BY id
            ");

            $stmt2->execute([$menage_id]);

            $enfants = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            if (count($enfants) === 1) {
                $_SESSION['eleve_id'] = (int)$enfants[0];
            }

            redirect('dashboard.php');
        }
    }

    // ======================================
    // ETAPE 3 : LOGIN AVEC PASSWORD
    // ======================================
    if ($action === 'login_password') {

        $menage_id = (int)($_POST['menage_id'] ?? 0);
        $password = trim($_POST['password'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM menage WHERE id=?");
        $stmt->execute([$menage_id]);

        $menage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$menage) {

            $error = "Compte introuvable.";

        } elseif (!password_verify($password, $menage['password'])) {

            $error = "Mot de passe incorrect.";
            $showPasswordForm = true;
            $menageData = $menage;

        } else {

            $_SESSION['parent'] = $menage;

            // AUTO SELECTION ENFANT
            $stmt2 = $pdo->prepare("
                SELECT id
                FROM eleve
                WHERE menage = ?
                ORDER BY id
            ");

            $stmt2->execute([$menage_id]);

            $enfants = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            if (count($enfants) === 1) {
                $_SESSION['eleve_id'] = (int)$enfants[0];
            }

            redirect('dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Connexion Parent</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
    body {
        background: #f5f7fb;
    }

    .card {
        border: none;
        border-radius: 18px;
    }

    .form-control {
        height: 50px;
        border-radius: 12px;
    }

    .btn {
        height: 50px;
        border-radius: 12px;
    }

    .logo-box {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: #0d6efd;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 30px;
        margin: auto;
    }
    </style>
</head>

<body>

    <div class="container py-5">

        <div class="mx-auto" style="max-width:500px;">

            <div class="card shadow">

                <div class="card-body p-4">

                    <div class="text-center mb-4">

                        <div class="logo-box mb-3">
                            👨‍👩‍👧
                        </div>

                        <h3 class="fw-bold text-uppercase">
                            Espace Parent/Elève
                        </h3>

                        <p class="text-muted">
                            Se conecter à votre compte
                        </p>

                    </div>

                    <?php if($error): ?>
                    <div class="alert alert-danger">
                        <?= e($error) ?>
                    </div>
                    <?php endif; ?>

                    <!-- ========================= -->
                    <!-- ETAPE 1 -->
                    <!-- ========================= -->

                    <?php if(!$showPasswordForm && !$showCompleteForm): ?>

                    <form method="POST" autocomplete="off">

                        <input type="hidden" name="action" value="check_code">

                        <div class="mb-3">

                            <label class="form-label">
                                Code Famille
                            </label>

                            <div class="input-group">

                                <input type="text" name="menage_id" class="form-control"
                                    placeholder="Entrer votre code famille" required>
                            </div>

                        </div>

                        <button class="btn btn-primary w-100">
                            Continuer
                        </button>

                    </form>

                    <?php endif; ?>


                    <!-- ========================= -->
                    <!-- PASSWORD -->
                    <!-- ========================= -->

                    <?php if($showPasswordForm && $menageData): ?>

                    <div class="alert alert-success">
                        Bienvenue
                        <strong><?= e($menageData['noms']) ?></strong>,
                        Veuillez entrer votre mot de passe
                    </div>

                    <form method="POST" autocomplete="off">

                        <input type="hidden" name="action" value="login_password">

                        <input type="hidden" name="menage_id" value="<?= (int)$menageData['id'] ?>">

                        <div class="mb-3">

                            <label class="form-label">
                                Mot de passe
                            </label>

                            <div class="input-group">

                                <input type="password" name="password" id="passwordLogin" class="form-control"
                                    placeholder="Votre mot de passe" required>

                                <!-- <button class="btn btn-outline-secondary" type="button" id="toggleLoginPwd">
                                    👁
                                </button> -->

                            </div>

                        </div>

                        <div class="form-check mb-3 mt-3">
                            <input class="form-check-input" type="checkbox" id="showAllPasswords">

                            <label class="form-check-label" for="showAllPasswords">
                                Afficher les mots de passe
                            </label>
                        </div>

                        <button class="btn btn-success w-100">
                            Se connecter
                        </button>

                    </form>

                    <?php endif; ?>


                    <!-- ========================= -->
                    <!-- COMPLETER LE COMPTE -->
                    <!-- ========================= -->

                    <?php if($showCompleteForm && $menageData): ?>

                    <div class="alert alert-info">
                        <?php
                            date_default_timezone_set('Africa/Kinshasa');

                            $heure = (int) date('H');

                            if ($heure >= 5 && $heure < 18) {
                                $salutation = "Bonjour";
                            } else {
                                $salutation = "Bonsoir";
                            }
                        ?>
                        <h4><?= $salutation ?> 👋</h4> <br>
                        <strong><?= (int)$menageData['id'] ?> - <?= e($menageData['noms']) ?>,</strong>
                        Veuillez compléter votre compte afin de le rendre plus sécurisé, afin d’éviter les fraudes de la
                        part de personnes tierces non autorisées et ne faisant pas partie de votre famille.
                    </div>

                    <form method="POST" autocomplete="off">

                        <input type="hidden" name="action" value="complete_account">

                        <input type="hidden" name="menage_id" value="<?= (int)$menageData['id'] ?>">

                        <div class="mb-3">

                            <label class="form-label">
                                Mot de passe
                            </label>

                            <input type="password" name="password" id="password" class="form-control"
                                placeholder="Créer un mot de passe" required>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Répéter mot de passe
                            </label>

                            <input type="password" name="confirm_password" id="confirm_password" class="form-control"
                                placeholder="Confirmer mot de passe" required>

                        </div>

                        <div class="form-check mt-3 mb-3">
                            <input class="form-check-input" type="checkbox" id="showAllPasswords">

                            <label class="form-check-label" for="showAllPasswords">
                                Afficher les mots de passe
                            </label>
                        </div>

                        <div class="alert alert-secondary">Le mot de passe et les autres informations sont confidentiels
                            et ne doivent être partagés qu’aux membres de la famille afin d’accéder aux informations.
                        </div>
                        <button class="btn btn-primary w-100">
                            Terminer
                        </button>

                    </form>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

    <script>
    document.getElementById('showAllPasswords').addEventListener('change', function() {

        const type = this.checked ? 'text' : 'password';

        const inputs = [
            'passwordLogin',
            'password',
            'confirm_password'
        ];

        inputs.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.type = type;
            }
        });

    });
    </script>

</body>

</html>