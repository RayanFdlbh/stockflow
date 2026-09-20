
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
require_once '../includes/product_image.php';

$userId = (int) $_SESSION['user_id'];

/* =========================================
   POST UNIQUEMENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

/* =========================================
   VÉRIFICATION CSRF
========================================= */

$csrfToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrfToken) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    header('Location: index.php?error=csrf');
    exit;
}

/* =========================================
   RÉCUPÉRATION DE L'ID
========================================= */

$productId = filter_input(
    INPUT_POST,
    'product_id',
    FILTER_VALIDATE_INT
);

if (!$productId || $productId < 1) {
    header('Location: index.php?error=invalid');
    exit;
}

/* =========================================
   SUPPRESSION TRANSACTIONNELLE
========================================= */

$imageToDelete = null;

try {

    $pdo->beginTransaction();

    /*
     * Verrouiller le produit et vérifier
     * qu'il appartient à l'utilisateur.
     */

    $stmt = $pdo->prepare("
        SELECT id, image
        FROM products
        WHERE id = :id
          AND user_id = :user_id
        FOR UPDATE
    ");

    $stmt->execute([
        'id' => $productId,
        'user_id' => $userId
    ]);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {

        $pdo->rollBack();

        header('Location: index.php?error=notfound');
        exit;
    }

    /*
     * Vérifier si le produit possède
     * au moins une vente.
     *
     * Si oui, empêcher sa suppression
     * pour préserver l'historique et le CA.
     */

    $stmt = $pdo->prepare("
        SELECT id
        FROM sales
        WHERE product_id = :product_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        'product_id' => $productId,
        'user_id' => $userId
    ]);

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {

        $pdo->rollBack();

        header('Location: index.php?error=has_sales');
        exit;
    }

    /*
     * Le produit n'a jamais été vendu :
     * sa suppression définitive est autorisée.
     */

    $imageToDelete = $product['image'] ?: null;

    /*
     * Supprimer les mouvements de stock.
     */

    $stmt = $pdo->prepare("
        DELETE FROM stock_movements
        WHERE product_id = :product_id
          AND user_id = :user_id
    ");

    $stmt->execute([
        'product_id' => $productId,
        'user_id' => $userId
    ]);

    /*
     * Supprimer le produit.
     */

    $stmt = $pdo->prepare("
        DELETE FROM products
        WHERE id = :id
          AND user_id = :user_id
    ");

    $stmt->execute([
        'id' => $productId,
        'user_id' => $userId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'La suppression du produit a échoué.'
        );
    }

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'StockFlow delete.php : ' . $e->getMessage()
    );

    header('Location: index.php?error=delete');
    exit;
}

/* =========================================
   SUPPRESSION DU FICHIER IMAGE
========================================= */

/*
 * Le fichier n'est supprimé qu'après
 * validation de la transaction SQL.
 */

if ($imageToDelete !== null) {

    try {

        deleteProductImage($imageToDelete);

    } catch (Throwable $e) {

        error_log(
            'StockFlow nettoyage image produit '
            . $productId . ' : '
            . $e->getMessage()
        );
    }
}

/* =========================================
   REDIRECTION
========================================= */

header('Location: index.php?deleted=1');
exit;
