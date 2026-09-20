
<?php

session_start();

/* =========================================
   PROTECTION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

/* =========================================
   FONCTIONS
========================================= */

function movementEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function movementDate($value): string
{
    if (empty($value)) {
        return '—';
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false
        ? '—'
        : date('d/m/Y à H:i', $timestamp);
}

function movementTypeLabel(string $type): string
{
    return match ($type) {
        'in' => 'Entrée',
        'out' => 'Sortie',
        'adjustment' => 'Ajustement',
        default => 'Mouvement'
    };
}

function movementTypeIcon(string $type): string
{
    return match ($type) {
        'in' => 'bi-arrow-down-left',
        'out' => 'bi-arrow-up-right',
        'adjustment' => 'bi-arrow-repeat',
        default => 'bi-box'
    };
}

function movementPageUrl(int $page): string
{
    $query = [
        'page' => $page
    ];

    $type = $_GET['type'] ?? 'all';
    $search = $_GET['search'] ?? '';

    if (is_string($type) && $type !== 'all') {
        $query['type'] = $type;
    }

    if (is_string($search) && $search !== '') {
        $query['search'] = $search;
    }

    return 'index.php?' . http_build_query($query);
}

/* =========================================
   CSRF
========================================= */

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================
   VALEURS DU FORMULAIRE
========================================= */

$formProductId = '';
$formType = 'in';
$formQuantity = '';
$formReason = '';

$errorMessage = '';

/* =========================================
   ENREGISTREMENT D'UN MOUVEMENT
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $errorMessage = 'Session expirée. Actualisez la page et réessayez.';
    } else {

        $formProductId = $_POST['product_id'] ?? '';
        $formType = $_POST['type'] ?? '';
        $formQuantity = $_POST['quantity'] ?? '';
        $formReason = $_POST['reason'] ?? '';

        if (!is_string($formProductId)) {
            $formProductId = '';
        }

        if (!is_string($formType)) {
            $formType = '';
        }

        if (!is_string($formQuantity)) {
            $formQuantity = '';
        }

        if (!is_string($formReason)) {
            $formReason = '';
        }

        $formReason = trim($formReason);

        $productId = filter_var(
            $formProductId,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        $quantity = filter_var(
            $formQuantity,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($productId === false) {

            $errorMessage = 'Sélectionnez un produit valide.';

        } elseif (
            !in_array(
                $formType,
                ['in', 'out', 'adjustment'],
                true
            )
        ) {

            $errorMessage = 'Sélectionnez un type de mouvement valide.';

        } elseif (
            $quantity === false ||
            $quantity > 4294967295
        ) {

            $errorMessage = 'Saisissez une quantité entière supérieure à zéro.';

        } elseif (
            mb_strlen($formReason, 'UTF-8') > 255
        ) {

            $errorMessage = 'Le motif ne peut pas dépasser 255 caractères.';

        } else {

            try {

                $pdo->beginTransaction();

                /*
                 * Verrouiller le produit pour éviter
                 * les modifications simultanées du stock.
                 */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        name,
                        quantity

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

                    throw new RuntimeException(
                        'Ce produit est introuvable.'
                    );
                }

                $currentStock = (int) $product['quantity'];

                /*
                 * Pour un ajustement, la quantité
                 * saisie représente le nouveau stock.
                 *
                 * Pour une entrée ou une sortie,
                 * elle représente la variation.
                 */

                if ($formType === 'in') {

                    $newStock = $currentStock + $quantity;

                    if ($newStock > 4294967295) {

                        throw new RuntimeException(
                            'Cette entrée dépasse la capacité maximale du stock.'
                        );
                    }

                    $movementQuantity = $quantity;

                    $defaultReason = 'Entrée manuelle';

                } elseif ($formType === 'out') {

                    if ($quantity > $currentStock) {

                        throw new RuntimeException(
                            'Stock insuffisant : '
                            . $currentStock
                            . ' unité(s) disponible(s).'
                        );
                    }

                    $newStock = $currentStock - $quantity;

                    $movementQuantity = $quantity;

                    $defaultReason = 'Sortie manuelle';

                } else {

                    /*
                     * Ajustement :
                     * la valeur saisie devient
                     * le nouveau stock exact.
                     */

                    $newStock = $quantity;

                    $movementQuantity = abs(
                        $newStock - $currentStock
                    );

                    $defaultReason = 'Inventaire : '
                        . $currentStock
                        . ' → '
                        . $newStock;

                    if ($movementQuantity === 0) {

                        throw new RuntimeException(
                            'Le nouveau stock est identique au stock actuel.'
                        );
                    }
                }

                /*
                 * Mettre à jour le stock.
                 */

                $stmt = $pdo->prepare("
                    UPDATE products

                    SET quantity = :quantity

                    WHERE id = :id
                      AND user_id = :user_id
                ");

                $stmt->execute([
                    'quantity' => $newStock,
                    'id' => $productId,
                    'user_id' => $userId
                ]);

                if ($stmt->rowCount() !== 1) {

                    throw new RuntimeException(
                        'La mise à jour du stock a échoué.'
                    );
                }

                /*
                 * Enregistrer le mouvement.
                 */

                $reason = $formReason !== ''
                    ? $formReason
                    : $defaultReason;

                if ($formType === 'adjustment') {

                    $reason = $reason
                        . ' ('
                        . $currentStock
                        . ' → '
                        . $newStock
                        . ')';
                }

                if (mb_strlen($reason, 'UTF-8') > 255) {

                    throw new RuntimeException(
                        'Le motif du mouvement est trop long.'
                    );
                }

                $stmt = $pdo->prepare("
                    INSERT INTO stock_movements (
                        product_id,
                        user_id,
                        type,
                        quantity,
                        reason
                    )

                    VALUES (
                        :product_id,
                        :user_id,
                        :type,
                        :quantity,
                        :reason
                    )
                ");

                $stmt->execute([
                    'product_id' => $productId,
                    'user_id' => $userId,
                    'type' => $formType,
                    'quantity' => $movementQuantity,
                    'reason' => $reason
                ]);

                $pdo->commit();

                header(
                    'Location: index.php?created=1'
                );

                exit;

            } catch (RuntimeException $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errorMessage = $e->getMessage();

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'StockFlow movements/index.php : '
                    . $e->getMessage()
                );

                $errorMessage =
                    'Une erreur est survenue lors de l’enregistrement.';
            }
        }
    }
}

/* =========================================
   LISTE DES PRODUITS
========================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        quantity

    FROM products

    WHERE user_id = :user_id

    ORDER BY name ASC
");

$stmt->execute([
    'user_id' => $userId
]);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   FILTRES DE L'HISTORIQUE
========================================= */

$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? 'all';
$pageInput = $_GET['page'] ?? '1';

if (!is_string($search)) {
    $search = '';
}

if (!is_string($typeFilter)) {
    $typeFilter = 'all';
}

if (!is_string($pageInput)) {
    $pageInput = '1';
}

$search = trim($search);

if (mb_strlen($search, 'UTF-8') > 150) {

    $search = mb_substr(
        $search,
        0,
        150,
        'UTF-8'
    );
}

if (
    !in_array(
        $typeFilter,
        ['all', 'in', 'out', 'adjustment'],
        true
    )
) {
    $typeFilter = 'all';
}

$page = filter_var(
    $pageInput,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'default' => 1,
            'min_range' => 1
        ]
    ]
);

$perPage = 10;

/* =========================================
   STATISTIQUES GLOBALES
========================================= */

$stmt = $pdo->prepare("
    SELECT

        COUNT(*) AS total_movements,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'in'
                    THEN quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_entries,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'out'
                    THEN quantity
                    ELSE 0
                END
            ),
            0
        ) AS total_exits,

        COALESCE(
            SUM(
                CASE
                    WHEN type = 'adjustment'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS total_adjustments

    FROM stock_movements

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   CONSTRUCTION DES FILTRES SQL
========================================= */

$where = [
    'sm.user_id = :user_id'
];

$params = [
    'user_id' => $userId
];

if ($typeFilter !== 'all') {

    $where[] = 'sm.type = :type';

    $params['type'] = $typeFilter;
}

if ($search !== '') {

    $where[] = 'p.name LIKE :search';

    $params['search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

/* =========================================
   NOMBRE DE RÉSULTATS
========================================= */

$stmt = $pdo->prepare("
    SELECT COUNT(*)

    FROM stock_movements sm

    INNER JOIN products p
        ON p.id = sm.product_id
       AND p.user_id = sm.user_id

    WHERE $whereSql
");

$stmt->execute($params);

$totalResults = (int) $stmt->fetchColumn();

/* =========================================
   PAGINATION
========================================= */

$totalPages = max(
    1,
    (int) ceil($totalResults / $perPage)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

/* =========================================
   HISTORIQUE DES MOUVEMENTS
========================================= */

$stmt = $pdo->prepare("
    SELECT
        sm.id,
        sm.type,
        sm.quantity,
        sm.reason,
        sm.created_at,

        p.name AS product_name,
        p.quantity AS current_stock

    FROM stock_movements sm

    INNER JOIN products p
        ON p.id = sm.product_id
       AND p.user_id = sm.user_id

    WHERE $whereSql

    ORDER BY
        sm.created_at DESC,
        sm.id DESC

    LIMIT :limit
    OFFSET :offset
");

foreach ($params as $key => $value) {

    $stmt->bindValue(
        ':' . $key,
        $value,
        $key === 'user_id'
            ? PDO::PARAM_INT
            : PDO::PARAM_STR
    );
}

$stmt->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);

$stmt->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);

$stmt->execute();

$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   MESSAGE DE SUCCÈS
========================================= */

$successMessage = '';

if (($_GET['created'] ?? '') === '1') {

    $successMessage =
        'Le mouvement a été enregistré et le stock a été mis à jour.';
}

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Mouvements de stock';

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

                <h1>Mouvements de stock</h1>

                <p>
                    Gérez les entrées, sorties et ajustements.
                </p>

            </div>

        </div>


        <div class="topbar-actions">

            <div class="user-profile">

                <div class="user-avatar">

                    <?= movementEscape(
                        strtoupper(
                            substr(
                                $_SESSION['username'] ?? 'U',
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>

                <div class="user-information">

                    <strong>

                        <?= movementEscape(
                            $_SESSION['username'] ?? 'Utilisateur'
                        ) ?>

                    </strong>

                    <span>

                        <?= ($_SESSION['role'] ?? 'user') === 'admin'
                            ? 'Administrateur'
                            : 'Utilisateur'
                        ?>

                    </span>

                </div>

            </div>

        </div>

    </header>


    <!-- =====================================
         CONTENU
    ====================================== -->

    <main class="main-content">

        <!-- EN-TÊTE -->

        <div class="page-heading">

            <div>

                <h2>Gestion des mouvements</h2>

                <p>
                    Consultez l'historique et mettez votre stock à jour.
                </p>

            </div>

            <a
                href="../products/index.php"
                class="secondary-action"
            >

                <i class="bi bi-box-seam"></i>

                Voir les produits

            </a>

        </div>


        <!-- =================================
             MESSAGES
        ================================== -->

        <?php if ($successMessage !== ''): ?>

            <div class="movement-message movement-success">

                <i class="bi bi-check-circle"></i>

                <?= movementEscape($successMessage) ?>

            </div>

        <?php endif; ?>


        <?php if ($errorMessage !== ''): ?>

            <div class="movement-message movement-error">

                <i class="bi bi-exclamation-triangle"></i>

                <?= movementEscape($errorMessage) ?>

            </div>

        <?php endif; ?>


        <!-- =================================
             STATISTIQUES
        ================================== -->

        <section class="stats-grid movement-stats-grid">

            <!-- TOTAL -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon products-icon">
                        <i class="bi bi-arrow-left-right"></i>
                    </div>

                    <span class="stat-label">
                        Mouvements
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        (int) $stats['total_movements'],
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Opérations enregistrées
                </span>

            </article>


            <!-- ENTRÉES -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon profit-icon">
                        <i class="bi bi-arrow-down-left"></i>
                    </div>

                    <span class="stat-label">
                        Entrées
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        (int) $stats['total_entries'],
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Unités ajoutées au stock
                </span>

            </article>


            <!-- SORTIES -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon alert-icon">
                        <i class="bi bi-arrow-up-right"></i>
                    </div>

                    <span class="stat-label">
                        Sorties
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        (int) $stats['total_exits'],
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Unités sorties, ventes comprises
                </span>

            </article>


            <!-- AJUSTEMENTS -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon value-icon">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>

                    <span class="stat-label">
                        Ajustements
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        (int) $stats['total_adjustments'],
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Corrections d'inventaire
                </span>

            </article>

        </section>


        <!-- =================================
             FORMULAIRE
        ================================== -->

        <section class="form-card movement-form-card">

            <div class="form-card-heading">

                <div class="form-card-icon">

                    <i class="bi bi-plus-circle"></i>

                </div>

                <div>

                    <h3>Enregistrer un mouvement</h3>

                    <p>
                        Le stock sera mis à jour automatiquement.
                    </p>

                </div>

            </div>


            <?php if (empty($products)): ?>

                <div class="empty-state compact">

                    <i class="bi bi-box-seam"></i>

                    <strong>
                        Aucun produit disponible
                    </strong>

                    <span>
                        Ajoutez un produit avant d'enregistrer
                        un mouvement de stock.
                    </span>

                    <a
                        href="../products/add.php"
                        class="primary-action"
                    >

                        <i class="bi bi-plus-lg"></i>

                        Ajouter un produit

                    </a>

                </div>

            <?php else: ?>

                <form
                    method="POST"
                    action="index.php"
                    id="movementForm"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= movementEscape($csrfToken) ?>"
                    >


                    <div class="form-grid">

                        <!-- PRODUIT -->

                        <div class="app-form-group">

                            <label for="product_id">
                                Produit *
                            </label>

                            <select
                                id="product_id"
                                name="product_id"
                                required
                            >

                                <option value="">
                                    Sélectionner un produit
                                </option>

                                <?php foreach ($products as $product): ?>

                                    <option
                                        value="<?= (int) $product['id'] ?>"
                                        data-stock="<?= (int) $product['quantity'] ?>"
                                        <?= (string) $formProductId === (string) $product['id']
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        <?= movementEscape(
                                            $product['name']
                                        ) ?>

                                        — Stock :

                                        <?= (int) $product['quantity'] ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <small id="currentStockInfo">
                                Sélectionnez un produit pour afficher son stock.
                            </small>

                        </div>


                        <!-- TYPE -->

                        <div class="app-form-group">

                            <label for="type">
                                Type de mouvement *
                            </label>

                            <select
                                id="type"
                                name="type"
                                required
                            >

                                <option
                                    value="in"
                                    <?= $formType === 'in' ? 'selected' : '' ?>
                                >
                                    Entrée de stock
                                </option>

                                <option
                                    value="out"
                                    <?= $formType === 'out' ? 'selected' : '' ?>
                                >
                                    Sortie de stock
                                </option>

                                <option
                                    value="adjustment"
                                    <?= $formType === 'adjustment' ? 'selected' : '' ?>
                                >
                                    Ajustement d'inventaire
                                </option>

                            </select>

                        </div>


                        <!-- QUANTITÉ -->

                        <div class="app-form-group">

                            <label for="quantity" id="quantityLabel">
                                Quantité *
                            </label>

                            <input
                                type="number"
                                id="quantity"
                                name="quantity"
                                min="1"
                                max="4294967295"
                                step="1"
                                required
                                placeholder="Ex. : 5"
                                value="<?= movementEscape($formQuantity) ?>"
                            >

                            <small id="quantityHelp">
                                Indiquez le nombre d'unités à ajouter.
                            </small>

                        </div>


                        <!-- MOTIF -->

                        <div class="app-form-group">

                            <label for="reason">
                                Motif
                            </label>

                            <input
                                type="text"
                                id="reason"
                                name="reason"
                                maxlength="255"
                                placeholder="Ex. : Réapprovisionnement"
                                value="<?= movementEscape($formReason) ?>"
                            >

                            <small>
                                Facultatif. Un motif sera généré si ce champ est vide.
                            </small>

                        </div>


                        <!-- APERÇU -->

                        <div class="app-form-group full-width">

                            <div class="movement-preview">

                                <div>

                                    <span>Stock actuel</span>

                                    <strong id="previewCurrent">
                                        —
                                    </strong>

                                </div>

                                <i class="bi bi-arrow-right"></i>

                                <div>

                                    <span>Stock après mouvement</span>

                                    <strong id="previewNew">
                                        —
                                    </strong>

                                </div>

                            </div>

                            <p
                                id="movementWarning"
                                class="movement-warning"
                                hidden
                            ></p>

                        </div>


                        <!-- ACTION -->

                        <div class="app-form-group full-width">

                            <div class="movement-form-actions">

                                <button
                                    type="submit"
                                    id="submitMovement"
                                    class="primary-action movement-submit"
                                >

                                    <i class="bi bi-check-lg"></i>

                                    Enregistrer le mouvement

                                </button>

                                <a
                                    href="../products/index.php"
                                    class="secondary-action"
                                >

                                    Annuler

                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            <?php endif; ?>

        </section>


        <!-- =================================
             HISTORIQUE
        ================================== -->

        <section class="form-card movement-history-card">

            <div class="form-card-heading">

                <div class="form-card-icon">

                    <i class="bi bi-clock-history"></i>

                </div>

                <div>

                    <h3>Historique des mouvements</h3>

                    <p>
                        Retrouvez les opérations enregistrées,
                        y compris les sorties liées aux ventes.
                    </p>

                </div>

            </div>


            <!-- FILTRES -->

            <form
                method="GET"
                action="index.php"
                class="movement-filters"
            >

                <div class="app-form-group">

                    <label for="search">
                        Rechercher un produit
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        maxlength="150"
                        placeholder="Nom du produit..."
                        value="<?= movementEscape($search) ?>"
                    >

                </div>


                <div class="app-form-group">

                    <label for="typeFilter">
                        Type
                    </label>

                    <select
                        id="typeFilter"
                        name="type"
                    >

                        <option
                            value="all"
                            <?= $typeFilter === 'all' ? 'selected' : '' ?>
                        >
                            Tous les mouvements
                        </option>

                        <option
                            value="in"
                            <?= $typeFilter === 'in' ? 'selected' : '' ?>
                        >
                            Entrées
                        </option>

                        <option
                            value="out"
                            <?= $typeFilter === 'out' ? 'selected' : '' ?>
                        >
                            Sorties
                        </option>

                        <option
                            value="adjustment"
                            <?= $typeFilter === 'adjustment' ? 'selected' : '' ?>
                        >
                            Ajustements
                        </option>

                    </select>

                </div>


                <div class="movement-filter-actions">

                    <button
                        type="submit"
                        class="primary-action movement-submit"
                    >

                        <i class="bi bi-search"></i>

                        Rechercher

                    </button>

                    <a
                        href="index.php"
                        class="secondary-action"
                    >

                        Réinitialiser

                    </a>

                </div>

            </form>


            <div class="movement-results-count">

                <?= number_format(
                    $totalResults,
                    0,
                    ',',
                    ' '
                ) ?>

                mouvement(s) trouvé(s)

            </div>


            <?php if (empty($movements)): ?>

                <!-- AUCUN RÉSULTAT -->

                <div class="empty-state">

                    <i class="bi bi-clock-history"></i>

                    <strong>
                        Aucun mouvement trouvé
                    </strong>

                    <span>
                        Les opérations de stock apparaîtront ici.
                    </span>

                </div>

            <?php else: ?>

                <!-- TABLEAU -->

                <div class="movement-table-wrapper">

                    <table class="movement-table">

                        <thead>

                            <tr>

                                <th>Produit</th>

                                <th>Type</th>

                                <th>Quantité</th>

                                <th>Motif</th>

                                <th>Date</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($movements as $movement): ?>

                                <tr>

                                    <!-- PRODUIT -->

                                    <td>

                                        <strong>

                                            <?= movementEscape(
                                                $movement['product_name']
                                            ) ?>

                                        </strong>

                                    </td>


                                    <!-- TYPE -->

                                    <td>

                                        <span
                                            class="movement-type-badge movement-type-<?= movementEscape(
                                                $movement['type']
                                            ) ?>"
                                        >

                                            <i class="bi <?= movementEscape(
                                                movementTypeIcon(
                                                    $movement['type']
                                                )
                                            ) ?>"></i>

                                            <?= movementEscape(
                                                movementTypeLabel(
                                                    $movement['type']
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- QUANTITÉ -->

                                    <td>

                                        <strong>

                                            <?= $movement['type'] === 'in'
                                                ? '+'
                                                : (
                                                    $movement['type'] === 'out'
                                                    ? '−'
                                                    : ''
                                                )
                                            ?>

                                            <?= number_format(
                                                (int) $movement['quantity'],
                                                0,
                                                ',',
                                                ' '
                                            ) ?>

                                        </strong>

                                    </td>


                                    <!-- MOTIF -->

                                    <td>

                                        <?= movementEscape(
                                            $movement['reason'] ?: '—'
                                        ) ?>

                                    </td>


                                    <!-- DATE -->

                                    <td>

                                        <?= movementEscape(
                                            movementDate(
                                                $movement['created_at']
                                            )
                                        ) ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- PAGINATION -->

                <?php if ($totalPages > 1): ?>

                    <div class="movement-pagination">

                        <span>

                            Page <?= $page ?>
                            sur <?= $totalPages ?>

                        </span>


                        <div class="movement-pagination-actions">

                            <?php if ($page > 1): ?>

                                <a
                                    href="<?= movementEscape(
                                        movementPageUrl($page - 1)
                                    ) ?>"
                                    class="secondary-action"
                                >

                                    <i class="bi bi-chevron-left"></i>

                                    Précédent

                                </a>

                            <?php endif; ?>


                            <?php if ($page < $totalPages): ?>

                                <a
                                    href="<?= movementEscape(
                                        movementPageUrl($page + 1)
                                    ) ?>"
                                    class="secondary-action"
                                >

                                    Suivant

                                    <i class="bi bi-chevron-right"></i>

                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>

    </main>

</div>


<!-- =========================================
     STYLE DE LA PAGE
========================================= -->

<style>

.movement-message {

    display: flex;
    align-items: center;
    gap: 10px;

    padding: 15px 18px;
    margin-bottom: 22px;

    border-radius: 12px;

    font-size: 14px;

}

.movement-success {

    background: rgba(34, 197, 94, 0.12);
    color: #22c55e;

    border: 1px solid rgba(34, 197, 94, 0.25);

}

.movement-error {

    background: rgba(239, 68, 68, 0.12);
    color: #ef4444;

    border: 1px solid rgba(239, 68, 68, 0.25);

}

.movement-stats-grid {

    margin-bottom: 28px;

}

.movement-form-card {

    margin-bottom: 28px;

}

.movement-preview {

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;

    padding: 22px;

    border-radius: 14px;

    background: rgba(99, 102, 241, 0.06);
    border: 1px solid rgba(99, 102, 241, 0.15);

}

.movement-preview > div {

    display: flex;
    flex-direction: column;
    gap: 8px;

}

.movement-preview span {

    font-size: 13px;
    opacity: 0.7;

}

.movement-preview strong {

    font-size: 24px;

}

.movement-preview > i {

    font-size: 22px;
    color: #818cf8;

}

.movement-warning {

    margin: 10px 0 0;

    color: #ef4444;

    font-size: 13px;

}

.movement-form-actions {

    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;

}

.movement-submit {

    border: none;
    cursor: pointer;
    font: inherit;

}

.movement-submit:disabled {

    opacity: 0.5;
    cursor: not-allowed;

}

.movement-filters {

    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto;

    gap: 16px;

    align-items: end;

    margin-bottom: 24px;

}

.movement-filter-actions {

    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

}

.movement-results-count {

    margin-bottom: 16px;

    font-size: 13px;
    opacity: 0.7;

}

.movement-table-wrapper {

    width: 100%;
    overflow-x: auto;

}

.movement-table {

    width: 100%;
    min-width: 750px;

    border-collapse: collapse;

}

.movement-table th {

    text-align: left;

    padding: 15px 12px;

    font-size: 12px;
    font-weight: 600;

    opacity: 0.7;

    border-bottom: 1px solid rgba(148, 163, 184, 0.2);

}

.movement-table td {

    padding: 16px 12px;

    font-size: 14px;

    border-bottom: 1px solid rgba(148, 163, 184, 0.12);

}

.movement-table tbody tr:last-child td {

    border-bottom: none;

}

.movement-type-badge {

    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 7px 10px;

    border-radius: 8px;

    font-size: 12px;
    font-weight: 600;

    white-space: nowrap;

}

.movement-type-in {

    color: #22c55e;
    background: rgba(34, 197, 94, 0.12);

}

.movement-type-out {

    color: #ef4444;
    background: rgba(239, 68, 68, 0.12);

}

.movement-type-adjustment {

    color: #818cf8;
    background: rgba(99, 102, 241, 0.12);

}

.movement-pagination {

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;

    flex-wrap: wrap;

    margin-top: 24px;

}

.movement-pagination-actions {

    display: flex;
    align-items: center;
    gap: 10px;

}

@media (max-width: 900px) {

    .movement-filters {

        grid-template-columns: 1fr;

    }

}

@media (max-width: 650px) {

    .movement-preview {

        padding: 16px;
        gap: 12px;

    }

    .movement-preview strong {

        font-size: 19px;

    }

    .movement-form-actions {

        align-items: stretch;
        flex-direction: column;

    }

    .movement-form-actions > * {

        justify-content: center;

    }

}

</style>


<!-- =========================================
     APERÇU DU STOCK EN TEMPS RÉEL
========================================= -->

<script>

const movementProductSelect =
    document.getElementById('product_id');

const movementTypeSelect =
    document.getElementById('type');

const movementQuantityInput =
    document.getElementById('quantity');

const movementCurrentStockInfo =
    document.getElementById('currentStockInfo');

const movementQuantityLabel =
    document.getElementById('quantityLabel');

const movementQuantityHelp =
    document.getElementById('quantityHelp');

const movementPreviewCurrent =
    document.getElementById('previewCurrent');

const movementPreviewNew =
    document.getElementById('previewNew');

const movementWarning =
    document.getElementById('movementWarning');

const movementSubmit =
    document.getElementById('submitMovement');


function updateMovementPreview() {

    if (
        !movementProductSelect ||
        !movementTypeSelect ||
        !movementQuantityInput
    ) {
        return;
    }

    const selectedOption =
        movementProductSelect.options[
            movementProductSelect.selectedIndex
        ];

    const hasProduct =
        movementProductSelect.value !== '';

    const currentStock = hasProduct
        ? Number(selectedOption.dataset.stock)
        : null;

    const type = movementTypeSelect.value;

    const rawQuantity = movementQuantityInput.value.trim();

    const quantity = Number(rawQuantity);

    const validQuantity =
        rawQuantity !== '' &&
        Number.isSafeInteger(quantity) &&
        quantity >= 1 &&
        quantity <= 4294967295;

    let newStock = null;

    let warning = '';

    movementQuantityLabel.textContent =
        type === 'adjustment'
            ? 'Nouveau stock *'
            : 'Quantité *';

    movementQuantityHelp.textContent =
        type === 'in'
            ? "Indiquez le nombre d'unités à ajouter."
            : (
                type === 'out'
                    ? "Indiquez le nombre d'unités à retirer."
                    : "Indiquez la quantité exacte qui doit rester en stock."
            );

    movementCurrentStockInfo.textContent =
        hasProduct
            ? 'Stock actuel : ' + currentStock + ' unité(s)'
            : 'Sélectionnez un produit pour afficher son stock.';

    movementPreviewCurrent.textContent =
        hasProduct
            ? currentStock.toLocaleString('fr-FR')
            : '—';

    if (hasProduct && validQuantity) {

        if (type === 'in') {

            newStock = currentStock + quantity;

            if (newStock > 4294967295) {

                warning =
                    'Cette entrée dépasse la capacité maximale du stock.';
            }

        } else if (type === 'out') {

            newStock = currentStock - quantity;

            if (newStock < 0) {

                warning =
                    'Stock insuffisant : seulement '
                    + currentStock
                    + ' unité(s) disponible(s).';
            }

        } else if (type === 'adjustment') {

            newStock = quantity;

            if (newStock === currentStock) {

                warning =
                    'Le nouveau stock est identique au stock actuel.';
            }

        }

    }

    movementPreviewNew.textContent =
        newStock !== null && warning === ''
            ? newStock.toLocaleString('fr-FR')
            : '—';

    movementWarning.textContent = warning;

    movementWarning.hidden = warning === '';

    movementSubmit.disabled =
        !hasProduct ||
        !validQuantity ||
        warning !== '';

}


if (
    movementProductSelect &&
    movementTypeSelect &&
    movementQuantityInput
) {

    movementProductSelect.addEventListener(
        'change',
        updateMovementPreview
    );

    movementTypeSelect.addEventListener(
        'change',
        updateMovementPreview
    );

    movementQuantityInput.addEventListener(
        'input',
        updateMovementPreview
    );

    updateMovementPreview();

}

</script>


<?php require_once '../includes/footer.php'; ?>
