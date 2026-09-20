
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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';
    $email = $_POST['email'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $error = 'Session expirée. Actualisez la page.';

    } elseif (
        !is_string($email) ||
        !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
    ) {

        $error = 'Veuillez saisir une adresse e-mail valide.';

    } else {

        $email = trim($email);

        /*
         * Message identique, que l'adresse existe
         * ou non, pour éviter de révéler les comptes.
         */

        $message =
            'Si cette adresse correspond à un compte, '
            . 'un lien de réinitialisation a été généré.';

        try {

            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                LIMIT 1
            ");

            $stmt->execute([
                'email' => $email
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {

                $userId = (int) $user['id'];

                $token = bin2hex(random_bytes(32));

                $tokenHash = hash('sha256', $token);

                $expiresAt = (new DateTimeImmutable())
                    ->modify('+30 minutes')
                    ->format('Y-m-d H:i:s');

                $pdo->beginTransaction();

                /*
                 * Un seul lien actif par utilisateur.
                 */

                $stmt = $pdo->prepare("
                    DELETE FROM password_resets
                    WHERE user_id = :user_id
                ");

                $stmt->execute([
                    'user_id' => $userId
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO password_resets (
                        user_id,
                        token_hash,
                        expires_at
                    )
                    VALUES (
                        :user_id,
                        :token_hash,
                        :expires_at
                    )
                ");

                $stmt->execute([
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'expires_at' => $expiresAt
                ]);

                $pdo->commit();

                /*
                 * MODE LOCAL XAMPP :
                 * écriture du lien dans un fichier
                 * hors du dossier public htdocs.
                 *
                 * Ne pas utiliser ce mécanisme
                 * sur un serveur de production.
                 */

                $resetUrl =
                    'http://localhost/stockflow/auth/reset-password.php'
                    . '?token='
                    . urlencode($token);

                $logDirectory = dirname(
                    __DIR__,
                    2
                ) . DIRECTORY_SEPARATOR . 'stockflow-private';

                if (
                    !is_dir($logDirectory) &&
                    !mkdir($logDirectory, 0700, true) &&
                    !is_dir($logDirectory)
                ) {
                    throw new RuntimeException(
                        'Impossible de créer le dossier privé.'
                    );
                }

                $logFile = $logDirectory
                    . DIRECTORY_SEPARATOR
                    . 'password-reset-links.txt';

                $logEntry =
                    date('Y-m-d H:i:s')
                    . ' | '
                    . $email
                    . ' | '
                    . $resetUrl
                    . PHP_EOL;

                if (
                    file_put_contents(
                        $logFile,
                        $logEntry,
                        FILE_APPEND | LOCK_EX
                    ) === false
                ) {
                    throw new RuntimeException(
                        'Impossible d’enregistrer le lien de test.'
                    );
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'StockFlow forgot-password : '
                . $e->getMessage()
            );

            $message = '';

            $error =
                'Impossible de traiter la demande pour le moment.';
        }
    }
}

function forgotEscape(mixed $value): string
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

    <title>Mot de passe oublié | StockFlow</title>

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

                <h1>Mot de passe oublié ?</h1>

                <p>
                    Saisissez l'adresse e-mail associée
                    à votre compte.
                </p>

            </div>

            <?php if ($error !== ''): ?>

                <div class="auth-message error">
                    <?= forgotEscape($error) ?>
                </div>

            <?php endif; ?>

            <?php if ($message !== ''): ?>

                <div
                    class="auth-message"
                    style="color:#22c55e;margin-bottom:20px;"
                >
                    <?= forgotEscape($message) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= forgotEscape($csrfToken) ?>"
                >

                <div class="form-group">

                    <label for="email">
                        Adresse e-mail
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="votre@email.com"
                        autocomplete="email"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="auth-button"
                >
                    Générer le lien
                </button>

            </form>

            <div class="auth-footer">

                <p>
                    <a href="login.php">
                        Retour à la connexion
                    </a>
                </p>

            </div>

        </section>

    </main>

</body>
</html>
