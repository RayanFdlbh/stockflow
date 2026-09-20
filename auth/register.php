<?php

require_once '../config/database.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        empty($username) ||
        empty($email) ||
        empty($password) ||
        empty($confirmPassword)
    ) {
        $message = 'Veuillez remplir tous les champs.';
        $messageType = 'error';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Veuillez saisir une adresse email valide.';
        $messageType = 'error';

    } elseif (strlen($username) < 3) {
        $message = 'Le nom d’utilisateur doit contenir au moins 3 caractères.';
        $messageType = 'error';

    } elseif (strlen($password) < 8) {
        $message = 'Le mot de passe doit contenir au moins 8 caractères.';
        $messageType = 'error';

    } elseif ($password !== $confirmPassword) {
        $message = 'Les mots de passe ne correspondent pas.';
        $messageType = 'error';

    } else {

        $stmt = $pdo->prepare(
            "SELECT id
             FROM users
             WHERE username = :username OR email = :email"
        );

        $stmt->execute([
            'username' => $username,
            'email' => $email
        ]);

        $existingUser = $stmt->fetch();

        if ($existingUser) {

            $message = 'Ce nom d’utilisateur ou cet email est déjà utilisé.';
            $messageType = 'error';

        } else {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare(
                "INSERT INTO users (username, email, password)
                 VALUES (:username, :email, :password)"
            );

            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password' => $hashedPassword
            ]);

            $message = 'Votre compte a été créé avec succès !';
            $messageType = 'success';
        }
    }
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

    <title>Créer un compte | StockFlow</title>

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
                <h1>Créer un compte</h1>
                <p>
                    Commencez à gérer votre stock simplement.
                </p>
            </div>

            <?php if (!empty($message)): ?>

                <div class="auth-message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>

            <?php endif; ?>

            <form method="POST" action="">

                <div class="form-group">

                    <label for="username">
                        Nom d'utilisateur
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Votre nom d'utilisateur"
                        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                        autocomplete="username"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="email">
                        Adresse email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="nom@exemple.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        autocomplete="email"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="password">
                        Mot de passe
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Minimum 8 caractères"
                        autocomplete="new-password"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="confirm_password">
                        Confirmer le mot de passe
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Répétez votre mot de passe"
                        autocomplete="new-password"
                        required
                    >

                </div>

                <button
                    type="submit"
                    class="auth-button"
                >
                    Créer mon compte
                </button>

            </form>

            <div class="auth-footer">

                <p>
                    Vous avez déjà un compte ?
                    <a href="login.php">
                        Se connecter
                    </a>
                </p>

            </div>

        </section>

    </main>

</body>
</html>