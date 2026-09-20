
<?php

session_start();

require_once '../config/database.php';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$error = '';
$success = false;

$token = $_POST['token'] ?? $_GET['token'] ?? '';

if (
    !is_string($token) ||
    !preg_match('/^[a-f0-9]{64}$/', $token)
) {

    $error = 'Ce lien de réinitialisation est invalide.';

    $token = '';

}

/* =========================================
   VÉRIFICATION DU LIEN
========================================= */

$reset = null;

if ($token !== '') {

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT
            pr.id,
            pr.user_id

        FROM password_resets pr

        WHERE pr.token_hash = :token_hash
          AND pr.expires_at > NOW()

        LIMIT 1
    ");

    $stmt->execute([
        'token_hash' => $tokenHash
    ]);

    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reset) {

        $error =
            'Ce lien est expiré ou a déjà été utilisé.';
    }
}

/* =========================================
   NOUVEAU MOT DE PASSE
========================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $reset
) {

    $postedToken = $_POST['csrf_token'] ?? '';

    $password = $_POST['password'] ?? '';

    $confirmation = $_POST['confirmation'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $error = 'Session expirée. Actualisez la page.';

    } elseif (
        !is_string($password) ||
        !is_string($confirmation)
    ) {

        $error = 'Données invalides.';

    } elseif (strlen($password) < 8) {

        $error =
            'Le mot de passe doit contenir au moins 8 caractères.';

    } elseif (strlen($password) > 72) {

        $error =
            'Le mot de passe ne peut pas dépasser 72 caractères.';

    } elseif ($password !== $confirmation) {

        $error =
            'Les deux mots de passe ne correspondent pas.';

    } else {

        try {

            $pdo->beginTransaction();

            /*
             * Reverrouiller le lien pour éviter
             * deux utilisations simultanées.
             */

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    user_id

                FROM password_resets

                WHERE token_hash = :token_hash
                  AND expires_at > NOW()

                LIMIT 1

                FOR UPDATE
            ");

            $stmt->execute([
                'token_hash' => $tokenHash
            ]);

            $lockedReset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedReset) {

                throw new RuntimeException(
                    'Ce lien est expiré ou déjà utilisé.'
                );
            }

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                UPDATE users

                SET password = :password

                WHERE id = :user_id
            ");

            $stmt->execute([
                'password' => $passwordHash,
                'user_id' => (int) $lockedReset['user_id']
            ]);

            /*
             * Invalider tous les liens de cet utilisateur.
             */

            $stmt = $pdo->prepare("
                DELETE FROM password_resets
                WHERE user_id = :user_id
            ");

            $stmt->execute([
                'user_id' => (int) $lockedReset['user_id']
            ]);

            $pdo->commit();

            /*
             * Déconnecter la session courante
             * après réinitialisation.
             */

            $_SESSION = [];

            session_regenerate_id(true);

            $success = true;

            $reset = null;

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'StockFlow reset-password : '
                . $e->getMessage()
            );

            $error =
                'Impossible de modifier le mot de passe.';
        }
    }
}

function resetEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Réinitialiser le mot de passe | StockFlow</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>

<body class="auth-body">

    <main class="auth-container">

        <section class="auth-card">

            <div class="auth-logo">

                <div class="logo-icon">S</div>

                <span>StockFlow</span>

            </div>

            <div class="auth-heading">

                <h1>Nouveau mot de passe</h1>

                <p>
                    Choisissez un nouveau mot de passe
                    pour votre compte.
                </p>

            </div>

            <?php if ($success): ?>

                <div
                    class="auth-message"
                    style="color:#22c55e;margin-bottom:20px;"
                >
                    Votre mot de passe a été modifié avec succès.
                </div>

                <a
                    href="login.php"
                    class="auth-button"
                    style="display:block;text-align:center;text-decoration:none;"
                >
                    Se connecter
                </a>

            <?php else: ?>

                <?php if ($error !== ''): ?>

                    <div class="auth-message error">
                        <?= resetEscape($error) ?>
                    </div>

                <?php endif; ?>

                <?php if ($reset): ?>

                    <form method="POST" action="">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= resetEscape($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="token"
                            value="<?= resetEscape($token) ?>"
                        >

                        <div class="form-group">

                            <label for="password">
                                Nouveau mot de passe
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="confirmation">
                                Confirmer le mot de passe
                            </label>

                            <input
                                type="password"
                                id="confirmation"
                                name="confirmation"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <button
                            type="submit"
                            class="auth-button"
                        >
                            Enregistrer le mot de passe
                        </button>

                    </form>

                <?php else: ?>

                    <div class="auth-footer">

                        <p>
                            <a href="forgot-password.php">
                                Demander un nouveau lien
                            </a>
                        </p>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>

    </main>

</body>
</html>
