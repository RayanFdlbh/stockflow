<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = $_SESSION['user_id'];

/* =========================================
   TOKEN CSRF
========================================= */

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}


/* =========================================
   FILTRES
========================================= */

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$stock = trim($_GET['stock'] ?? '');
$sort = $_GET['sort'] ?? 'newest';

$allowedSorts = [
    'newest',
    'oldest',
    'name_asc',
    'name_desc',
    'stock_asc',
    'stock_desc',
    'price_asc',
    'price_desc',
    'margin_desc'
];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}


/* =========================================
   RÉCUPÉRER LES CATÉGORIES
========================================= */

$stmt = $pdo->prepare("
    SELECT DISTINCT category
    FROM products
    WHERE user_id = :user_id
    ORDER BY category ASC
");

$stmt->execute([
    'user_id' => $userId
]);

$categories = $stmt->fetchAll();


/* =========================================
   CONSTRUIRE LA REQUÊTE PRODUITS
========================================= */

$sql = "
    SELECT *
    FROM products
    WHERE user_id = :user_id
";

$params = [
    'user_id' => $userId
];


if ($search !== '') {

    $sql .= "
        AND (
            name LIKE :search
            OR category LIKE :search
        )
    ";

    $params['search'] = '%' . $search . '%';
}


if ($category !== '') {

    $sql .= " AND category = :category";

    $params['category'] = $category;
}


if ($stock === 'healthy') {

    $sql .= "
        AND quantity > low_stock_threshold
    ";

} elseif ($stock === 'low') {

    $sql .= "
        AND quantity > 0
        AND quantity <= low_stock_threshold
    ";

} elseif ($stock === 'out') {

    $sql .= "
        AND quantity = 0
    ";
}

switch ($sort) {

    case 'oldest':
        $sql .= " ORDER BY created_at ASC";
        break;

    case 'name_asc':
        $sql .= " ORDER BY name ASC";
        break;

    case 'name_desc':
        $sql .= " ORDER BY name DESC";
        break;

    case 'stock_asc':
        $sql .= " ORDER BY quantity ASC";
        break;

    case 'stock_desc':
        $sql .= " ORDER BY quantity DESC";
        break;

    case 'price_asc':
        $sql .= " ORDER BY selling_price ASC";
        break;

    case 'price_desc':
        $sql .= " ORDER BY selling_price DESC";
        break;

    case 'margin_desc':
        $sql .= "
            ORDER BY
                (selling_price - purchase_price) DESC
        ";
        break;

    case 'newest':
    default:
        $sql .= " ORDER BY created_at DESC";
        break;
}


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$products = $stmt->fetchAll();


$pageTitle = 'Produits';

require_once '../includes/header.php';
require_once '../includes/sidebar.php';

?>

<div class="main-wrapper">

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

                <h1>Produits</h1>

                <p>
                    Gérez l'ensemble de votre inventaire.
                </p>

            </div>

        </div>


        <div class="topbar-actions">

            <div class="user-profile">

                <div class="user-avatar">

                    <?= strtoupper(
                        htmlspecialchars(
                            substr($_SESSION['username'], 0, 1)
                        )
                    ) ?>

                </div>

                <div class="user-information">

                    <strong>
                        <?= htmlspecialchars($_SESSION['username']) ?>
                    </strong>

                    <span>

                        <?= $_SESSION['role'] === 'admin'
                            ? 'Administrateur'
                            : 'Utilisateur'
                        ?>

                    </span>

                </div>

            </div>

        </div>

    </header>


    <main class="main-content">

        <div class="page-heading">

            <div>

                <h2>Mes produits</h2>

                <p>

                    <?= count($products) ?>

                    produit<?= count($products) > 1 ? 's' : '' ?>

                    affiché<?= count($products) > 1 ? 's' : '' ?>

                </p>

            </div>


            <a
                href="add.php"
                class="primary-action"
            >

                <i class="bi bi-plus-lg"></i>

                Ajouter un produit

            </a>

        </div>


        <?php if (isset($_GET['created'])): ?>

            <div class="app-message success">
                <i class="bi bi-check-circle"></i>
                Produit ajouté avec succès.
            </div>

        <?php endif; ?>


        <?php if (isset($_GET['updated'])): ?>

            <div class="app-message success">
                <i class="bi bi-check-circle"></i>
                Produit modifié avec succès.
            </div>

        <?php endif; ?>


        <?php if (isset($_GET['deleted'])): ?>

            <div class="app-message success">
                <i class="bi bi-check-circle"></i>
                Produit supprimé avec succès.
            </div>

        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>

    <div class="app-message error">

        <i class="bi bi-exclamation-circle"></i>

        Impossible d'effectuer cette action.

    </div>

<?php endif; ?>


        <!-- FILTRES -->

        <section class="products-toolbar">

            <form
                method="GET"
                action=""
                class="products-filters"
            >

                <div class="product-search">

                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="search"
                        placeholder="Rechercher un produit..."
                        value="<?= htmlspecialchars($search) ?>"
                    >

                </div>


                <select name="category">

                    <option value="">
                        Toutes les catégories
                    </option>

                    <?php foreach ($categories as $item): ?>

                        <option
                            value="<?= htmlspecialchars($item['category']) ?>"
                            <?= $category === $item['category']
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?= htmlspecialchars($item['category']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <select name="stock">

                    <option value="">
                        Tous les stocks
                    </option>

                    <option
                        value="healthy"
                        <?= $stock === 'healthy'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        En stock
                    </option>

                    <option
                        value="low"
                        <?= $stock === 'low'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Stock faible
                    </option>

                    <option
                        value="out"
                        <?= $stock === 'out'
                            ? 'selected'
                            : ''
                        ?>
                    >
                        Rupture
                    </option>

                </select>

                <select
    name="sort"
    aria-label="Trier les produits"
>

    <option
        value="newest"
        <?= $sort === 'newest' ? 'selected' : '' ?>
    >
        Plus récents
    </option>

    <option
        value="oldest"
        <?= $sort === 'oldest' ? 'selected' : '' ?>
    >
        Plus anciens
    </option>

    <option
        value="name_asc"
        <?= $sort === 'name_asc' ? 'selected' : '' ?>
    >
        Nom : A → Z
    </option>

    <option
        value="name_desc"
        <?= $sort === 'name_desc' ? 'selected' : '' ?>
    >
        Nom : Z → A
    </option>

    <option
        value="stock_asc"
        <?= $sort === 'stock_asc' ? 'selected' : '' ?>
    >
        Stock : croissant
    </option>

    <option
        value="stock_desc"
        <?= $sort === 'stock_desc' ? 'selected' : '' ?>
    >
        Stock : décroissant
    </option>

    <option
        value="price_asc"
        <?= $sort === 'price_asc' ? 'selected' : '' ?>
    >
        Prix : croissant
    </option>

    <option
        value="price_desc"
        <?= $sort === 'price_desc' ? 'selected' : '' ?>
    >
        Prix : décroissant
    </option>

    <option
        value="margin_desc"
        <?= $sort === 'margin_desc' ? 'selected' : '' ?>
    >
        Marge la plus élevée
    </option>

</select>


                <button
                    type="submit"
                    class="filter-button"
                >

                    <i class="bi bi-funnel"></i>

                    Filtrer

                </button>


                <?php if (
    $search !== ''
    || $category !== ''
    || $stock !== ''
    || $sort !== 'newest'
): ?>

                    <a
                        href="index.php"
                        class="reset-filter"
                        title="Réinitialiser les filtres"
                    >

                        <i class="bi bi-x-lg"></i>

                    </a>

                <?php endif; ?>

            </form>

        </section>


        <!-- TABLEAU -->

        <section class="products-card">

            <?php if (!empty($products)): ?>

                <div class="products-table-wrapper">

                    <table class="products-table">

                        <thead>

                            <tr>

                                <th>Produit</th>

                                <th>Catégorie</th>

                                <th>Stock</th>

                                <th>Prix d'achat</th>

                                <th>Prix de vente</th>

                                <th>Marge</th>

                                <th></th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($products as $product): ?>

                            <?php

                                $purchasePrice =
                                    (float) $product['purchase_price'];

                                $sellingPrice =
                                    (float) $product['selling_price'];

                                $margin =
                                    $sellingPrice - $purchasePrice;

                            ?>


                            <tr>

                                <td>

                                    <div class="product-cell">

                                        <div class="product-table-image">

                                            <?php if (!empty($product['image'])): ?>

                                                <img
                                                    src="/stockflow/uploads/products/<?= htmlspecialchars($product['image']) ?>"
                                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                                >

                                            <?php else: ?>

                                                <i class="bi bi-box-seam"></i>

                                            <?php endif; ?>

                                        </div>


                                        <div>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $product['name']
                                                ) ?>

                                            </strong>

                                            <span>

                                                Ajouté le

                                                <?= date(
                                                    'd/m/Y',
                                                    strtotime(
                                                        $product['created_at']
                                                    )
                                                ) ?>

                                            </span>

                                        </div>

                                    </div>

                                </td>


                                <td>

                                    <span class="category-badge">

                                        <?= htmlspecialchars(
                                            $product['category']
                                        ) ?>

                                    </span>

                                </td>


                                <td>

                                    <?php if ((int) $product['quantity'] === 0): ?>

                                        <div class="table-stock">

                                            <strong>
                                                0
                                            </strong>

                                            <span class="stock-status out">
                                                Rupture
                                            </span>

                                        </div>


                                    <?php elseif (
                                        (int) $product['quantity']
                                        <=
                                        (int) $product['low_stock_threshold']
                                    ): ?>

                                        <div class="table-stock">

                                            <strong>

                                                <?= (int) $product['quantity'] ?>

                                            </strong>

                                            <span class="stock-status low">
                                                Stock faible
                                            </span>

                                        </div>


                                    <?php else: ?>

                                        <div class="table-stock">

                                            <strong>

                                                <?= (int) $product['quantity'] ?>

                                            </strong>

                                            <span class="stock-status healthy">
                                                En stock
                                            </span>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= number_format(
                                        $purchasePrice,
                                        2,
                                        ',',
                                        ' '
                                    ) ?> €

                                </td>


                                <td>

                                    <?= number_format(
                                        $sellingPrice,
                                        2,
                                        ',',
                                        ' '
                                    ) ?> €

                                </td>


                                <td>

                                    <strong
                                        class="margin-value <?= $margin < 0
                                            ? 'negative'
                                            : ''
                                        ?>"
                                    >

                                        <?= $margin >= 0 ? '+' : '' ?>

                                        <?= number_format(
                                            $margin,
                                            2,
                                            ',',
                                            ' '
                                        ) ?> €

                                    </strong>

                                </td>


                                <td>

                                    <div class="product-actions">

                                        <a
                                            href="edit.php?id=<?= (int) $product['id'] ?>"
                                            class="table-action"
                                            title="Modifier"
                                        >

                                            <i class="bi bi-pencil"></i>

                                        </a>


                                        <button
                                            type="button"
                                            class="table-action danger delete-product-button"
                                            data-product-id="<?= (int) $product['id'] ?>"
                                            data-product-name="<?= htmlspecialchars(
                                                $product['name'],
                                                ENT_QUOTES
                                            ) ?>"
                                            title="Supprimer"
                                        >

                                            <i class="bi bi-trash3"></i>

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


            <?php else: ?>

                <div class="products-empty-state">

                    <div class="products-empty-icon">

                        <i class="bi bi-box-seam"></i>

                    </div>

                    <h3>
                        Aucun produit trouvé
                    </h3>

                    <p>

                        <?php if (
    $search !== ''
    || $category !== ''
    || $stock !== ''
    || $sort !== 'newest'
): ?>

    Aucun produit ne correspond à vos filtres.

<?php else: ?>

    Commencez par ajouter votre premier
    produit à StockFlow.

<?php endif; ?>

                    </p>


                    <?php if (
                        $search !== ''
                        || $category !== ''
                        || $stock !== ''
                        || $sort !== 'newest'
                    ): ?>

                        <a
                            href="index.php"
                            class="secondary-action"
                        >
                            Réinitialiser les filtres
                        </a>

                    <?php else: ?>

                        <a
                            href="add.php"
                            class="primary-action"
                            
                        >

                            <i class="bi bi-plus-lg"></i>

                            Ajouter un produit

                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>
        <!-- =========================================
     MODAL SUPPRESSION
========================================= -->

<div
    class="delete-modal"
    id="deleteModal"
    aria-hidden="true"
>

    <div
        class="delete-modal-overlay"
        id="deleteModalOverlay"
    ></div>


    <div
        class="delete-modal-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="deleteModalTitle"
    >

        <button
            type="button"
            class="delete-modal-close"
            id="deleteModalClose"
            aria-label="Fermer"
        >

            <i class="bi bi-x-lg"></i>

        </button>


        <div class="delete-modal-icon">

            <i class="bi bi-trash3"></i>

        </div>


        <h3 id="deleteModalTitle">
            Supprimer ce produit ?
        </h3>


        <p>

            Vous êtes sur le point de supprimer

            <strong id="deleteProductName">
                ce produit
            </strong>.

        </p>


        <div class="delete-warning">

            <i class="bi bi-exclamation-triangle"></i>

            <span>
                Cette action supprimera également
                les mouvements et ventes associés
                à ce produit.
            </span>

        </div>


        <form
            action="delete.php"
            method="POST"
            id="deleteProductForm"
        >

            <input
                type="hidden"
                name="product_id"
                id="deleteProductId"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >


            <div class="delete-modal-actions">

                <button
                    type="button"
                    class="secondary-action"
                    id="deleteModalCancel"
                >
                    Annuler
                </button>


                <button
                    type="submit"
                    class="delete-confirm-button"
                >

                    <i class="bi bi-trash3"></i>

                    Supprimer définitivement

                </button>

            </div>

        </form>

    </div>

</div>

<script>

const deleteModal =
    document.getElementById('deleteModal');

const deleteModalOverlay =
    document.getElementById('deleteModalOverlay');

const deleteModalClose =
    document.getElementById('deleteModalClose');

const deleteModalCancel =
    document.getElementById('deleteModalCancel');

const deleteProductId =
    document.getElementById('deleteProductId');

const deleteProductName =
    document.getElementById('deleteProductName');

const deleteButtons =
    document.querySelectorAll(
        '.delete-product-button'
    );


function openDeleteModal(productId, productName) {

    deleteProductId.value =
        productId;

    deleteProductName.textContent =
        productName;

    deleteModal.classList.add('active');

    deleteModal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'modal-open'
    );
}


function closeDeleteModal() {

    deleteModal.classList.remove('active');

    deleteModal.setAttribute(
        'aria-hidden',
        'true'
    );

    document.body.classList.remove(
        'modal-open'
    );

    deleteProductId.value = '';
}


deleteButtons.forEach(button => {

    button.addEventListener(
        'click',
        function () {

            const productId =
                this.dataset.productId;

            const productName =
                this.dataset.productName;

            openDeleteModal(
                productId,
                productName
            );
        }
    );

});


deleteModalClose.addEventListener(
    'click',
    closeDeleteModal
);


deleteModalCancel.addEventListener(
    'click',
    closeDeleteModal
);


deleteModalOverlay.addEventListener(
    'click',
    closeDeleteModal
);


document.addEventListener(
    'keydown',
    function (event) {

        if (
            event.key === 'Escape' &&
            deleteModal.classList.contains('active')
        ) {

            closeDeleteModal();
        }
    }
);

</script>

<script>

const filterSelects =
    document.querySelectorAll(
        '.products-filters select'
    );

filterSelects.forEach(select => {

    select.addEventListener(
        'change',
        function () {

            this.form.submit();

        }
    );

});

</script>

<?php require_once '../includes/footer.php'; ?>