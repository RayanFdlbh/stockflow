
<?php

session_start();

/* =========================================
   PROTECTION DE LA PAGE
========================================= */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

/* =========================================
   FONCTION D'ÉCHAPPEMENT HTML
========================================= */

function settingsEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================
   PROTECTION CSRF
========================================= */

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];

$errorMessage = '';
$successMessage = '';

/* =========================================
   RÉCUPÉRATION DE L'UTILISATEUR
========================================= */

try {

    $stmt = $pdo->prepare("
        SELECT
            id,
            username,
            email,
            password,
            role

        FROM users

        WHERE id = :id

        LIMIT 1
    ");

    $stmt->execute([
        'id' => $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'StockFlow settings - chargement utilisateur : '
        . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Impossible de charger les paramètres du compte.'
    );
}

/* =========================================
   UTILISATEUR INTROUVABLE
========================================= */

if (!$user) {

    $_SESSION = [];

    session_destroy();

    header('Location: ../auth/login.php');
    exit;
}

/* =========================================
   TRAITEMENT DES FORMULAIRES
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $errorMessage =
            'Session expirée. Actualisez la page et réessayez.';

    } else {

        $action = $_POST['action'] ?? '';

        /* =================================
           MODIFIER LE NOM D'UTILISATEUR
        ================================== */

        if ($action === 'username') {

            $newUsername = $_POST['username'] ?? '';

            if (!is_string($newUsername)) {
                $newUsername = '';
            }

            $newUsername = trim($newUsername);

            if (
                $newUsername === '' ||
                strlen($newUsername) > 150
            ) {

                $errorMessage =
                    'Le nom d’utilisateur doit contenir entre 1 et 150 caractères.';

            } elseif (
                $newUsername === $user['username']
            ) {

                $errorMessage =
                    'Ce nom d’utilisateur est déjà le vôtre.';

            } else {

                try {

                    /*
                     * Vérifier si le nom est déjà utilisé.
                     */

                    $stmt = $pdo->prepare("
                        SELECT id

                        FROM users

                        WHERE username = :username
                          AND id <> :id

                        LIMIT 1
                    ");

                    $stmt->execute([
                        'username' => $newUsername,
                        'id' => $userId
                    ]);

                    if ($stmt->fetch(PDO::FETCH_ASSOC)) {

                        $errorMessage =
                            'Ce nom d’utilisateur est déjà utilisé.';

                    } else {

                        /*
                         * Enregistrer le nouveau nom.
                         */

                        $stmt = $pdo->prepare("
                            UPDATE users

                            SET username = :username

                            WHERE id = :id
                        ");

                        $stmt->execute([
                            'username' => $newUsername,
                            'id' => $userId
                        ]);

                        /*
                         * Actualiser la session.
                         */

                        $_SESSION['username'] = $newUsername;

                        $user['username'] = $newUsername;

                        $successMessage =
                            'Votre nom d’utilisateur a été modifié avec succès.';
                    }

                } catch (PDOException $e) {

                    error_log(
                        'StockFlow settings - username : '
                        . $e->getMessage()
                    );

                    $errorMessage =
                        'Impossible de modifier le nom d’utilisateur.';
                }
            }

        /* =================================
           MODIFIER LE MOT DE PASSE
        ================================== */

        } elseif ($action === 'password') {

            $currentPassword =
                $_POST['current_password'] ?? '';

            $newPassword =
                $_POST['new_password'] ?? '';

            $confirmPassword =
                $_POST['confirm_password'] ?? '';

            if (
                !is_string($currentPassword) ||
                !is_string($newPassword) ||
                !is_string($confirmPassword)
            ) {

                $errorMessage =
                    'Les données envoyées sont invalides.';

            } elseif (
                $currentPassword === '' ||
                $newPassword === '' ||
                $confirmPassword === ''
            ) {

                $errorMessage =
                    'Veuillez remplir tous les champs du mot de passe.';

            } elseif (
                !password_verify(
                    $currentPassword,
                    $user['password']
                )
            ) {

                $errorMessage =
                    'Le mot de passe actuel est incorrect.';

            } elseif (
                strlen($newPassword) < 8
            ) {

                $errorMessage =
                    'Le nouveau mot de passe doit contenir au moins 8 caractères.';

            } elseif (
                strlen($newPassword) > 72
            ) {

                $errorMessage =
                    'Le nouveau mot de passe ne peut pas dépasser 72 caractères.';

            } elseif (
                $newPassword !== $confirmPassword
            ) {

                $errorMessage =
                    'La confirmation du mot de passe ne correspond pas.';

            } elseif (
                password_verify(
                    $newPassword,
                    $user['password']
                )
            ) {

                $errorMessage =
                    'Le nouveau mot de passe doit être différent de l’ancien.';

            } else {

                try {

                    /*
                     * Générer un nouveau hachage sécurisé.
                     */

                    $passwordHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    /*
                     * Mettre à jour le mot de passe.
                     */

                    $stmt = $pdo->prepare("
                        UPDATE users

                        SET password = :password

                        WHERE id = :id
                    ");

                    $stmt->execute([
                        'password' => $passwordHash,
                        'id' => $userId
                    ]);

                    /*
                     * Invalider les éventuels liens
                     * de réinitialisation encore actifs.
                     */

                    $stmt = $pdo->prepare("
                        DELETE FROM password_resets

                        WHERE user_id = :user_id
                    ");

                    $stmt->execute([
                        'user_id' => $userId
                    ]);

                    /*
                     * Renouveler l'identifiant
                     * de session.
                     */

                    session_regenerate_id(true);

                    $user['password'] = $passwordHash;

                    $successMessage =
                        'Votre mot de passe a été modifié avec succès.';

                } catch (PDOException $e) {

                    error_log(
                        'StockFlow settings - password : '
                        . $e->getMessage()
                    );

                    $errorMessage =
                        'Impossible de modifier le mot de passe.';
                }
            }

        } else {

            $errorMessage =
                'Action inconnue. Veuillez réessayer.';
        }
    }
}

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Paramètres';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';

?>

<div class="main-wrapper">

    <!-- =====================================
         TOPBAR
    ====================================== -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                class="mobile-menu-button"
                id="mobileMenuButton"
                type="button"
                aria-label="Ouvrir le menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1>Paramètres</h1>

                <p>
                    Gérez votre profil et la sécurité de votre compte.
                </p>

            </div>

        </div>

        <div class="topbar-actions">

            <div class="user-profile">

                <div class="user-avatar">

                    <?= settingsEscape(
                        strtoupper(
                            substr(
                                (string) $user['username'],
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>

                <div class="user-information">

                    <strong>
                        <?= settingsEscape($user['username']) ?>
                    </strong>

                    <span>

                        <?= $user['role'] === 'admin'
                            ? 'Administrateur'
                            : 'Utilisateur'
                        ?>

                    </span>

                </div>

            </div>

        </div>

    </header>


    <!-- =====================================
         CONTENU PRINCIPAL
    ====================================== -->

    <main class="main-content">

        <div class="page-heading">

            <div>

                <h2>Mon compte</h2>

                <p>
                    Personnalisez vos informations de connexion.
                </p>

            </div>

            <a
                href="../dashboard.php"
                class="secondary-action"
            >

                <i class="bi bi-arrow-left"></i>

                Dashboard

            </a>

        </div>


        <!-- =================================
             MESSAGES
        ================================== -->

        <?php if ($successMessage !== ''): ?>

            <div
                class="settings-message settings-success"
                role="status"
            >

                <i class="bi bi-check-circle"></i>

                <?= settingsEscape($successMessage) ?>

            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ''): ?>

            <div
                class="settings-message settings-error"
                role="alert"
            >

                <i class="bi bi-exclamation-triangle"></i>

                <?= settingsEscape($errorMessage) ?>

            </div>

        <?php endif; ?>


        <!-- =================================
             INFORMATIONS DU PROFIL
        ================================== -->

        <section class="form-card settings-card">

            <div class="form-card-heading">

                <div class="form-card-icon">

                    <i class="bi bi-person-circle"></i>

                </div>

                <div>

                    <h3>Informations du profil</h3>

                    <p>
                        Modifiez votre nom d'utilisateur.
                    </p>

                </div>

            </div>


            <form method="POST" action="index.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= settingsEscape($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="username"
                >


                <div class="settings-form-group">

                    <label for="username">
                        Nom d'utilisateur
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        maxlength="150"
                        autocomplete="username"
                        required
                        value="<?= settingsEscape($user['username']) ?>"
                    >

                </div>


                <div class="settings-form-group">

                    <label for="email">
                        Adresse e-mail
                    </label>

                    <input
                        type="email"
                        id="email"
                        value="<?= settingsEscape($user['email']) ?>"
                        readonly
                    >

                    <small>
                        Cette adresse est associée à votre compte.
                    </small>

                </div>


                <button
                    type="submit"
                    class="primary-action settings-submit"
                >

                    <i class="bi bi-check-lg"></i>

                    Enregistrer les modifications

                </button>

            </form>

        </section>


        <!-- =================================
             SÉCURITÉ DU COMPTE
        ================================== -->

        <section class="form-card settings-card">

            <div class="form-card-heading">

                <div class="form-card-icon">

                    <i class="bi bi-shield-lock"></i>

                </div>

                <div>

                    <h3>Sécurité du compte</h3>

                    <p>
                        Modifiez votre mot de passe.
                    </p>

                </div>

            </div>


            <form method="POST" action="index.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= settingsEscape($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="password"
                >


                <div class="settings-form-group">

                    <label for="current_password">
                        Mot de passe actuel
                    </label>

                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >

                </div>


                <div class="settings-form-group">

                    <label for="new_password">
                        Nouveau mot de passe
                    </label>

                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        autocomplete="new-password"
                        minlength="8"
                        maxlength="72"
                        required
                    >

                    <small>
                        Minimum 8 caractères.
                    </small>

                </div>


                <div class="settings-form-group">

                    <label for="confirm_password">
                        Confirmer le nouveau mot de passe
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        autocomplete="new-password"
                        minlength="8"
                        maxlength="72"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="primary-action settings-submit"
                >

                    <i class="bi bi-shield-check"></i>

                    Modifier le mot de passe

                </button>

            </form>


            <!-- MOT DE PASSE OUBLIÉ -->

            <div class="settings-password-help">

                <span>
                    Vous ne connaissez plus votre mot de passe actuel ?
                </span>

                <a href="../auth/forgot-password.php">

                    Mot de passe oublié ?

                    <i class="bi bi-arrow-right"></i>

                </a>

            </div>

        </section>

    </main>

</div>


<!-- =========================================
     STYLES COMPLÉMENTAIRES
========================================= -->

<style>

.settings-card {
    margin-bottom: 26px;
}

.settings-form-group {
    display: flex;
    flex-direction: column;
    gap: 9px;

    max-width: 550px;
    margin-bottom: 22px;
}

.settings-form-group label {
    font-size: 14px;
    font-weight: 600;
}

.settings-form-group input {
    width: 100%;
    box-sizing: border-box;

    padding: 13px 15px;

    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 10px;

    background: transparent;
    color: inherit;

    font: inherit;
}

.settings-form-group input:focus {
    outline: none;
    border-color: #818cf8;
}

.settings-form-group input[readonly] {
    opacity: 0.65;
    cursor: not-allowed;
}

.settings-form-group small {
    font-size: 12px;
    opacity: 0.7;
}

.settings-submit {
    border: none;
    cursor: pointer;
    font: inherit;
}

.settings-message {
    display: flex;
    align-items: center;
    gap: 10px;

    padding: 15px 18px;
    margin-bottom: 22px;

    border-radius: 12px;
}

.settings-success {
    color: #22c55e;

    background: rgba(34, 197, 94, 0.12);

    border: 1px solid rgba(34, 197, 94, 0.25);
}

.settings-error {
    color: #ef4444;

    background: rgba(239, 68, 68, 0.12);

    border: 1px solid rgba(239, 68, 68, 0.25);
}

.settings-password-help {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;

    flex-wrap: wrap;

    max-width: 550px;

    margin-top: 25px;
    padding-top: 20px;

    border-top: 1px solid rgba(148, 163, 184, 0.2);

    font-size: 13px;
}

.settings-password-help span {
    opacity: 0.75;
}

.settings-password-help a {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    color: #818cf8;
    font-weight: 600;
    text-decoration: none;
}

.settings-password-help a:hover {
    text-decoration: underline;
}

</style>

<?php require_once '../includes/footer.php'; ?>
