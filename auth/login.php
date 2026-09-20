<?php

session_start();

require_once '../config/database.php';

// Si l'utilisateur est déjà connecté,
// inutile de lui montrer la page de connexion.
if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($login) || empty($password)) {

        $message = 'Veuillez remplir tous les champs.';

    } else {

        $stmt = $pdo->prepare(
            "SELECT id, username, email, password, role
             FROM users
             WHERE email = :login OR username = :login
             LIMIT 1"
        );

        $stmt->execute([
            'login' => $login
        ]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            header('Location: ../dashboard.php');
            exit;

        } else {

            $message = 'Identifiants incorrects.';
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

    <title>Connexion | StockFlow</title>

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

                <div class="logo-icon">
                    S
                </div>

                <span>StockFlow</span>

            </div>


            <div class="auth-heading">

                <h1>Bon retour 👋</h1>

                <p>
                    Connectez-vous pour accéder à votre stock.
                </p>

            </div>


            <?php if (!empty($message)): ?>

                <div class="auth-message error">

                    <?= htmlspecialchars($message) ?>

                </div>

            <?php endif; ?>


            <form method="POST" action="">

                <div class="form-group">

                    <label for="login">
                        Email ou nom d'utilisateur
                    </label>

                    <input
                        type="text"
                        id="login"
                        name="login"
                        placeholder="Email ou nom d'utilisateur"
                        value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                        autocomplete="username"
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
                        placeholder="Votre mot de passe"
                        autocomplete="current-password"
                        required
                    >

                </div>


                <div class="login-options">

                    <a href="forgot-password.php">
                        Mot de passe oublié ?
                    </a>

                </div>


                <button
                    type="submit"
                    class="auth-button"
                >
                    Se connecter
                </button>

            </form>


            <div class="auth-footer">

                <p>
                    Vous n'avez pas encore de compte ?

                    <a href="register.php">
                        Créer un compte
                    </a>

                </p>

            </div>

        </section>

    </main>

</body>

</html>