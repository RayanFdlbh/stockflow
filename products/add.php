
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
   TOKEN CSRF
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   VARIABLES
========================================= */

$message = '';
$messageType = '';

/* =========================================
   TRAITEMENT DU FORMULAIRE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Vérification CSRF */

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {

        $message = 'La session du formulaire est invalide. Veuillez réessayer.';
        $messageType = 'error';

    } else {

        /* Vérification des types reçus */

        $fields = [
            'name',
            'category',
            'quantity',
            'purchase_price',
            'selling_price',
            'low_stock_threshold',
            'description'
        ];

        $validTypes = true;

        foreach ($fields as $field) {
            if (
                isset($_POST[$field]) &&
                !is_string($_POST[$field])
            ) {
                $validTypes = false;
                break;
            }
        }

        if (!$validTypes) {

            $message = 'Les données du formulaire sont invalides.';
            $messageType = 'error';

        } else {

            /* Récupération des données */

            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $quantity = trim($_POST['quantity'] ?? '');
            $purchasePrice = trim($_POST['purchase_price'] ?? '');
            $sellingPrice = trim($_POST['selling_price'] ?? '');

            $lowStockThreshold = trim(
                $_POST['low_stock_threshold'] ?? '3'
            );

            $description = trim($_POST['description'] ?? '');

            /* =================================
               VALIDATION
            ================================= */

            if (
                $name === '' ||
                $category === '' ||
                $quantity === '' ||
                $purchasePrice === '' ||
                $sellingPrice === '' ||
                $lowStockThreshold === ''
            ) {

                $message = 'Veuillez remplir tous les champs obligatoires.';

            } elseif (mb_strlen($name, 'UTF-8') > 150) {

                $message = 'Le nom du produit ne peut pas dépasser 150 caractères.';

            } elseif (mb_strlen($category, 'UTF-8') > 100) {

                $message = 'La catégorie ne peut pas dépasser 100 caractères.';

            } elseif (
                !preg_match('/^(0|[1-9][0-9]*)$/D', $quantity) ||
                (float) $quantity > 4294967295
            ) {

                $message = 'La quantité doit être un entier entre 0 et 4294967295.';

            } elseif (
                !preg_match(
                    '/^[0-9]{1,8}(\.[0-9]{1,2})?$/D',
                    $purchasePrice
                )
            ) {

                $message = 'Le prix d’achat est invalide (maximum 99999999.99).';

            } elseif (
                !preg_match(
                    '/^[0-9]{1,8}(\.[0-9]{1,2})?$/D',
                    $sellingPrice
                )
            ) {

                $message = 'Le prix de vente est invalide (maximum 99999999.99).';

            } elseif (
                !preg_match(
                    '/^(0|[1-9][0-9]*)$/D',
                    $lowStockThreshold
                ) ||
                (float) $lowStockThreshold > 4294967295
            ) {

                $message = 'Le seuil de stock doit être un entier entre 0 et 4294967295.';

            } else {

                /* =================================
                   CRÉATION DU PRODUIT ET IMAGE
                ================================= */

                $uploadedImage = null;

                try {

                    /*
                     * L'image est validée et enregistrée
                     * avant le début de la transaction.
                     *
                     * Si la base de données échoue,
                     * le fichier sera supprimé.
                     */

                    $uploadedImage = uploadProductImage('image');

                    $pdo->beginTransaction();

                    /* Création du produit */

                    $stmt = $pdo->prepare("
                        INSERT INTO products (
                            user_id,
                            name,
                            category,
                            quantity,
                            purchase_price,
                            selling_price,
                            low_stock_threshold,
                            description,
                            image
                        )
                        VALUES (
                            :user_id,
                            :name,
                            :category,
                            :quantity,
                            :purchase_price,
                            :selling_price,
                            :low_stock_threshold,
                            :description,
                            :image
                        )
                    ");

                    $stmt->execute([
                        'user_id' => $userId,
                        'name' => $name,
                        'category' => $category,
                        'quantity' => (int) $quantity,
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'low_stock_threshold' => (int) $lowStockThreshold,
                        'description' => $description !== ''
                            ? $description
                            : null,
                        'image' => $uploadedImage
                    ]);

                    /* Récupération de l'ID */

                    $productId = (int) $pdo->lastInsertId();

                    /* =================================
                       MOUVEMENT DE STOCK INITIAL
                    ================================= */

                    if ((int) $quantity > 0) {

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
                                'in',
                                :quantity,
                                'Stock initial'
                            )
                        ");

                        $stmt->execute([
                            'product_id' => $productId,
                            'user_id' => $userId,
                            'quantity' => (int) $quantity
                        ]);
                    }

                    /* Valider la transaction */

                    $pdo->commit();

                    header('Location: index.php?created=1');
                    exit;

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    /*
                     * Si un fichier a été enregistré
                     * mais que la création a échoué,
                     * on le supprime.
                     */

                    if ($uploadedImage !== null) {
                        deleteProductImage($uploadedImage);
                    }

                    error_log(
                        'StockFlow add.php: ' . $e->getMessage()
                    );

                    /*
                     * Afficher les erreurs de validation
                     * d'image sans exposer les erreurs SQL.
                     */

                    if ($e instanceof RuntimeException) {

                        $message = $e->getMessage();

                    } else {

                        $message = 'Une erreur est survenue pendant la création du produit.';

                    }
                }
            }

            if ($message !== '') {
                $messageType = 'error';
            }
        }
    }
}

/* =========================================
   VALEURS DU FORMULAIRE
========================================= */

function addFormValue(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? null;

    return is_string($value) ? $value : $default;
}

$formName = addFormValue('name');
$formCategory = addFormValue('category');
$formQuantity = addFormValue('quantity', '0');
$formPurchasePrice = addFormValue('purchase_price');
$formSellingPrice = addFormValue('selling_price');
$formThreshold = addFormValue('low_stock_threshold', '3');
$formDescription = addFormValue('description');

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Ajouter un produit';

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

                <span class="topbar-label">
                    Gestion des produits
                </span>

                <h1>
                    Ajouter un produit
                </h1>

            </div>

        </div>

        <div class="topbar-right">

            <div class="user-profile">

                <div class="user-avatar">

                    <?= htmlspecialchars(
                        strtoupper(
                            substr(
                                $_SESSION['username'] ?? 'U',
                                0,
                                1
                            )
                        )
                    ) ?>

                </div>

                <div class="user-profile-info">

                    <strong>
                        <?= htmlspecialchars(
                            $_SESSION['username'] ?? 'Utilisateur'
                        ) ?>
                    </strong>

                    <span>
                        <?= htmlspecialchars(
                            ucfirst(
                                $_SESSION['role'] ?? 'user'
                            )
                        ) ?>
                    </span>

                </div>

            </div>

        </div>

    </header>


    <!-- =====================================
         CONTENU PRINCIPAL
    ====================================== -->

    <main class="main-content">

        <!-- =================================
             EN-TÊTE
        ================================== -->

        <div class="page-heading">

            <div>

                <span class="page-eyebrow">
                    Produits
                </span>

                <h2>
                    Nouveau produit
                </h2>

                <p>
                    Ajoutez un nouveau produit à votre
                    inventaire et configurez son stock.
                </p>

            </div>

            <a
                href="index.php"
                class="secondary-action"
            >

                <i class="bi bi-arrow-left"></i>

                Retour aux produits

            </a>

        </div>


        <!-- =================================
             MESSAGE D'ERREUR
        ================================== -->

        <?php if ($message !== ''): ?>

            <div
                class="app-message <?= htmlspecialchars(
                    $messageType
                ) ?>"
            >

                <i class="bi bi-exclamation-circle"></i>

                <span>
                    <?= htmlspecialchars($message) ?>
                </span>

            </div>

        <?php endif; ?>


        <!-- =================================
             FORMULAIRE
        ================================== -->

        <form
            method="POST"
            action=""
            class="product-form"
            enctype="multipart/form-data"
        >

            <!-- TOKEN CSRF -->

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >


            <!-- =================================
                 INFORMATIONS GÉNÉRALES
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon">

                        <i class="bi bi-box-seam"></i>

                    </div>

                    <div>

                        <h3>
                            Informations générales
                        </h3>

                        <p>
                            Informations principales du produit.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- NOM DU PRODUIT -->

                    <div class="app-form-group">

                        <label for="name">

                            Nom du produit

                            <span>*</span>

                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            placeholder="Ex : Nike Air Max 95"
                            value="<?= htmlspecialchars(
                                $formName
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- CATÉGORIE -->

                    <div class="app-form-group">

                        <label for="category">

                            Catégorie

                            <span>*</span>

                        </label>

                        <input
                            type="text"
                            id="category"
                            name="category"
                            maxlength="100"
                            placeholder="Ex : Sneakers"
                            value="<?= htmlspecialchars(
                                $formCategory
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- QUANTITÉ INITIALE -->

                    <div class="app-form-group">

                        <label for="quantity">

                            Quantité initiale

                            <span>*</span>

                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="0"
                            max="4294967295"
                            step="1"
                            placeholder="0"
                            value="<?= htmlspecialchars(
                                $formQuantity
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- IMAGE DU PRODUIT -->

                    <div class="app-form-group full-width">

                        <label for="image">
                            Image du produit
                        </label>

                        <input
                            type="file"
                            id="image"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                        >

                        <small>
                            Formats acceptés : JPG, PNG et WebP.
                            Taille maximale : 5 Mo.
                        </small>

                        <div
                            id="imagePreviewContainer"
                            style="display: none; margin-top: 16px;"
                        >

                            <img
                                id="imagePreview"
                                src=""
                                alt="Aperçu de l'image du produit"
                                style="
                                    width: 160px;
                                    height: 160px;
                                    object-fit: cover;
                                    border-radius: 12px;
                                "
                            >

                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================
                 PRIX ET RENTABILITÉ
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon money">

                        <i class="bi bi-currency-euro"></i>

                    </div>

                    <div>

                        <h3>
                            Prix & rentabilité
                        </h3>

                        <p>
                            Définissez vos coûts et votre prix
                            de vente souhaité.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- PRIX D'ACHAT -->

                    <div class="app-form-group">

                        <label for="purchase_price">

                            Prix d'achat

                            <span>*</span>

                        </label>

                        <div class="input-with-symbol">

                            <input
                                type="number"
                                id="purchase_price"
                                name="purchase_price"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                placeholder="0.00"
                                value="<?= htmlspecialchars(
                                    $formPurchasePrice
                                ) ?>"
                                required
                            >

                            <span>
                                €
                            </span>

                        </div>

                    </div>


                    <!-- PRIX DE VENTE -->

                    <div class="app-form-group">

                        <label for="selling_price">

                            Prix de vente souhaité

                            <span>*</span>

                        </label>

                        <div class="input-with-symbol">

                            <input
                                type="number"
                                id="selling_price"
                                name="selling_price"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                placeholder="0.00"
                                value="<?= htmlspecialchars(
                                    $formSellingPrice
                                ) ?>"
                                required
                            >

                            <span>
                                €
                            </span>

                        </div>

                    </div>


                    <!-- APERÇU DE LA MARGE -->

                    <div class="profit-preview">

                        <span>
                            Marge unitaire estimée
                        </span>

                        <strong id="marginPreview">
                            0,00 €
                        </strong>

                        <small id="marginPercentage">
                            0 % de marge
                        </small>

                    </div>

                </div>

            </section>


            <!-- =================================
                 GESTION DU STOCK
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon warning">

                        <i class="bi bi-exclamation-triangle"></i>

                    </div>

                    <div>

                        <h3>
                            Gestion du stock
                        </h3>

                        <p>
                            Configurez le niveau à partir duquel
                            StockFlow doit considérer le stock
                            comme faible.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- SEUIL DE STOCK FAIBLE -->

                    <div class="app-form-group">

                        <label for="low_stock_threshold">

                            Seuil de stock faible

                        </label>

                        <input
                            type="number"
                            id="low_stock_threshold"
                            name="low_stock_threshold"
                            min="0"
                            max="4294967295"
                            step="1"
                            value="<?= htmlspecialchars(
                                $formThreshold
                            ) ?>"
                            required
                        >

                        <small>
                            Exemple : avec un seuil de 3,
                            une alerte apparaîtra lorsqu'il
                            restera 3 produits ou moins.
                        </small>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="app-form-group full-width">

                        <label for="description">

                            Description / Notes

                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="5"
                            placeholder="Ajoutez des informations complémentaires sur le produit..."
                        ><?= htmlspecialchars(
                            $formDescription
                        ) ?></textarea>

                    </div>

                </div>

            </section>


            <!-- =================================
                 ACTIONS
            ================================== -->

            <div class="form-actions">

                <a
                    href="index.php"
                    class="secondary-action"
                >
                    Annuler
                </a>

                <button
                    type="submit"
                    class="primary-action"
                >

                    <i class="bi bi-plus-lg"></i>

                    Créer le produit

                </button>

            </div>

        </form>

    </main>

</div>


<!-- =========================================
     CALCUL AUTOMATIQUE DE LA MARGE
========================================= -->

<script>

const purchasePriceInput =
    document.getElementById('purchase_price');

const sellingPriceInput =
    document.getElementById('selling_price');

const marginPreview =
    document.getElementById('marginPreview');

const marginPercentage =
    document.getElementById('marginPercentage');


function updateMarginPreview() {

    const purchasePrice =
        parseFloat(
            purchasePriceInput.value
        ) || 0;

    const sellingPrice =
        parseFloat(
            sellingPriceInput.value
        ) || 0;

    const margin =
        sellingPrice - purchasePrice;

    let percentage = 0;

    if (sellingPrice > 0) {

        percentage =
            (margin / sellingPrice) * 100;

    }

    marginPreview.textContent =
        margin.toLocaleString(
            'fr-FR',
            {
                style: 'currency',
                currency: 'EUR'
            }
        );

    marginPercentage.textContent =
        percentage.toFixed(1)
        + ' % de marge';

    if (margin < 0) {

        marginPreview.classList.add(
            'negative'
        );

    } else {

        marginPreview.classList.remove(
            'negative'
        );

    }

}


purchasePriceInput.addEventListener(
    'input',
    updateMarginPreview
);

sellingPriceInput.addEventListener(
    'input',
    updateMarginPreview
);

updateMarginPreview();

</script>


<!-- =========================================
     APERÇU DE L'IMAGE
========================================= -->

<script>

const imageInput =
    document.getElementById('image');

const imagePreviewContainer =
    document.getElementById('imagePreviewContainer');

const imagePreview =
    document.getElementById('imagePreview');

let currentImageUrl = null;


imageInput.addEventListener('change', function () {

    const file = this.files[0];

    /*
     * Révoquer l'ancienne URL temporaire
     * pour éviter de conserver des ressources
     * inutiles en mémoire.
     */

    if (currentImageUrl !== null) {

        URL.revokeObjectURL(currentImageUrl);

        currentImageUrl = null;
    }

    if (!file) {

        imagePreview.removeAttribute('src');

        imagePreviewContainer.style.display = 'none';

        return;
    }

    const allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    const maxSize = 5 * 1024 * 1024;

    if (!allowedTypes.includes(file.type)) {

        alert('Choisissez une image JPG, PNG ou WebP.');

        this.value = '';

        imagePreviewContainer.style.display = 'none';

        return;
    }

    if (file.size > maxSize) {

        alert("L'image ne doit pas dépasser 5 Mo.");

        this.value = '';

        imagePreviewContainer.style.display = 'none';

        return;
    }

    currentImageUrl = URL.createObjectURL(file);

    imagePreview.src = currentImageUrl;

    imagePreviewContainer.style.display = 'block';

});

</script>


<?php require_once '../includes/footer.php'; ?>
