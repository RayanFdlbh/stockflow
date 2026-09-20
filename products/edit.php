
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
   RÉCUPÉRATION DU PRODUIT
========================================= */

$productId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$productId || $productId < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM products
    WHERE id = :id
      AND user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    'id' => $productId,
    'user_id' => $userId
]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: index.php');
    exit;
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
            'description',
            'remove_image'
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

        } else {

            /* Récupération des données */

            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $quantity = trim($_POST['quantity'] ?? '');
            $purchasePrice = trim($_POST['purchase_price'] ?? '');
            $sellingPrice = trim($_POST['selling_price'] ?? '');

            $lowStockThreshold = trim(
                $_POST['low_stock_threshold'] ?? ''
            );

            $description = trim($_POST['description'] ?? '');

            $removeImage = $_POST['remove_image'] ?? '0';

            /* =================================
               VALIDATIONS
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

            } elseif (
                $removeImage !== '0' &&
                $removeImage !== '1'
            ) {

                $message = 'Le choix de suppression de l’image est invalide.';

            } elseif (
                $removeImage === '1' &&
                isset($_FILES['image']) &&
                is_array($_FILES['image']) &&
                ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE)
                    !== UPLOAD_ERR_NO_FILE
            ) {

                $message = 'Choisissez soit une nouvelle image, soit la suppression de l’image actuelle.';

            } else {

                /* =================================
                   MODIFICATION TRANSACTIONNELLE
                ================================= */

                $uploadedImage = null;
                $oldImage = null;
                $newImage = null;

                $imageChanged = false;
                $committed = false;

                try {

                    /*
                     * Enregistrer la nouvelle image,
                     * si l'utilisateur en a sélectionné une.
                     */

                    $uploadedImage = uploadProductImage('image');

                    $pdo->beginTransaction();

                    /*
                     * Verrouiller le produit et récupérer
                     * son stock ainsi que son image actuels.
                     */

                    $stmt = $pdo->prepare("
                        SELECT quantity, image
                        FROM products
                        WHERE id = :id
                          AND user_id = :user_id
                        FOR UPDATE
                    ");

                    $stmt->execute([
                        'id' => $productId,
                        'user_id' => $userId
                    ]);

                    $current = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$current) {
                        throw new RuntimeException(
                            'Produit introuvable.'
                        );
                    }

                    $newQuantity = (int) $quantity;
                    $oldQuantity = (int) $current['quantity'];

                    $oldImage = $current['image'] ?: null;
                    $newImage = $oldImage;

                    /*
                     * Priorité :
                     * 1. Suppression demandée ;
                     * 2. Nouvelle image importée ;
                     * 3. Conservation de l'image actuelle.
                     */

                    if ($removeImage === '1') {

                        $newImage = null;
                        $imageChanged = $oldImage !== null;

                    } elseif ($uploadedImage !== null) {

                        $newImage = $uploadedImage;
                        $imageChanged = true;
                    }

                    /* Mettre à jour le produit */

                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET
                            name = :name,
                            category = :category,
                            quantity = :quantity,
                            purchase_price = :purchase_price,
                            selling_price = :selling_price,
                            low_stock_threshold = :low_stock_threshold,
                            description = :description,
                            image = :image
                        WHERE id = :id
                          AND user_id = :user_id
                    ");

                    $stmt->execute([
                        'name' => $name,
                        'category' => $category,
                        'quantity' => $newQuantity,
                        'purchase_price' => $purchasePrice,
                        'selling_price' => $sellingPrice,
                        'low_stock_threshold' => (int) $lowStockThreshold,
                        'description' => $description !== ''
                            ? $description
                            : null,
                        'image' => $newImage,
                        'id' => $productId,
                        'user_id' => $userId
                    ]);

                    /* =================================
                       HISTORIQUE DES MOUVEMENTS
                    ================================= */

                    $difference = $newQuantity - $oldQuantity;

                    if ($difference !== 0) {

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
                            'type' => $difference > 0 ? 'in' : 'out',
                            'quantity' => abs($difference),
                            'reason' => 'Modification manuelle du stock'
                        ]);
                    }

                    $pdo->commit();
                    $committed = true;

                    /*
                     * Supprimer l'ancienne image seulement
                     * après validation de la base de données.
                     */

                    if (
                        $imageChanged &&
                        $oldImage !== null &&
                        $oldImage !== $newImage
                    ) {

                        try {
                            deleteProductImage($oldImage);
                        } catch (Throwable $cleanupError) {

                            error_log(
                                'StockFlow nettoyage ancienne image : '
                                . $cleanupError->getMessage()
                            );
                        }
                    }

                    header('Location: index.php?updated=1');
                    exit;

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    /*
                     * Si la modification SQL n'a pas abouti,
                     * supprimer la nouvelle image importée.
                     */

                    if (
                        !$committed &&
                        $uploadedImage !== null
                    ) {

                        try {
                            deleteProductImage($uploadedImage);
                        } catch (Throwable $cleanupError) {

                            error_log(
                                'StockFlow nettoyage nouvelle image : '
                                . $cleanupError->getMessage()
                            );
                        }
                    }

                    error_log(
                        'StockFlow edit.php: ' . $e->getMessage()
                    );

                    if (
                        $e instanceof RuntimeException &&
                        $e->getMessage() !== 'Produit introuvable.'
                    ) {

                        $message = $e->getMessage();

                    } else {

                        $message = 'Une erreur est survenue pendant la modification.';
                    }
                }
            }
        }

        if ($message !== '') {
            $messageType = 'error';
        }
    }
}

/* =========================================
   VALEURS À AFFICHER
========================================= */

function editFormValue(string $key, $fallback): string
{
    $submitted = $_POST[$key] ?? null;

    return is_string($submitted)
        ? $submitted
        : (string) ($fallback ?? '');
}

$formName = editFormValue(
    'name',
    $product['name']
);

$formCategory = editFormValue(
    'category',
    $product['category']
);

$formQuantity = editFormValue(
    'quantity',
    $product['quantity']
);

$formPurchasePrice = editFormValue(
    'purchase_price',
    $product['purchase_price']
);

$formSellingPrice = editFormValue(
    'selling_price',
    $product['selling_price']
);

$formThreshold = editFormValue(
    'low_stock_threshold',
    $product['low_stock_threshold']
);

$formDescription = editFormValue(
    'description',
    $product['description']
);

$currentImage = $product['image'] ?? null;

$removeImageChecked =
    ($_POST['remove_image'] ?? '0') === '1';

$pageTitle = 'Modifier un produit';

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

                <h1>Modifier le produit</h1>

                <p>
                    Mettez à jour les informations de votre article.
                </p>

            </div>

        </div>

        <div class="topbar-actions">

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

                <div class="user-information">

                    <strong>
                        <?= htmlspecialchars(
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
         CONTENU PRINCIPAL
    ====================================== -->

    <main class="main-content">

        <div class="page-heading">

            <div>

                <h2>
                    <?= htmlspecialchars($product['name']) ?>
                </h2>

                <p>
                    Modifiez les informations nécessaires.
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


        <!-- =====================================
             MESSAGE D'ERREUR
        ====================================== -->

        <?php if (!empty($message)): ?>

            <div class="app-message <?= htmlspecialchars($messageType) ?>">

                <i class="bi bi-exclamation-circle"></i>

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <!-- =====================================
             FORMULAIRE
        ====================================== -->

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

                        <h3>Informations générales</h3>

                        <p>
                            Identité et quantité du produit.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- NOM -->

                    <div class="app-form-group full-width">

                        <label for="name">

                            Nom du produit

                            <span>*</span>

                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            value="<?= htmlspecialchars($formName) ?>"
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
                            value="<?= htmlspecialchars($formCategory) ?>"
                            required
                        >

                    </div>


                    <!-- QUANTITÉ -->

                    <div class="app-form-group">

                        <label for="quantity">

                            Quantité actuelle

                            <span>*</span>

                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="0"
                            max="4294967295"
                            step="1"
                            value="<?= htmlspecialchars($formQuantity) ?>"
                            required
                        >

                        <small>
                            Toute différence sera enregistrée
                            automatiquement dans l'historique.
                        </small>

                    </div>


                    <!-- =================================
                         IMAGE DU PRODUIT
                    ================================== -->

                    <div class="app-form-group full-width">

                        <label for="image">
                            Image du produit
                        </label>

                        <?php if (!empty($currentImage)): ?>

                            <div
                                id="currentImageContainer"
                                style="margin-bottom: 16px;"
                            >

                                <p style="margin-bottom: 8px;">
                                    Image actuelle
                                </p>

                                <img
                                    src="/stockflow/uploads/products/<?= htmlspecialchars(
                                        rawurlencode($currentImage)
                                    ) ?>"
                                    alt="<?= htmlspecialchars(
                                        $product['name']
                                    ) ?>"
                                    style="
                                        width: 160px;
                                        height: 160px;
                                        object-fit: cover;
                                        border-radius: 12px;
                                    "
                                >

                            </div>

                        <?php endif; ?>


                        <input
                            type="file"
                            id="image"
                            name="image"
                            accept="image/jpeg,image/png,image/webp"
                        >

                        <small>
                            Formats acceptés : JPG, PNG et WebP.
                            Taille maximale : 5 Mo.
                            Laissez ce champ vide pour conserver
                            l'image actuelle.
                        </small>


                        <?php if (!empty($currentImage)): ?>

                            <label
                                for="remove_image"
                                style="
                                    display: flex;
                                    align-items: center;
                                    gap: 8px;
                                    margin-top: 16px;
                                    cursor: pointer;
                                "
                            >

                                <input
                                    type="checkbox"
                                    id="remove_image"
                                    name="remove_image"
                                    value="1"
                                    <?= $removeImageChecked
                                        ? 'checked'
                                        : ''
                                    ?>
                                >

                                Supprimer l'image actuelle

                            </label>

                        <?php endif; ?>


                        <!-- APERÇU NOUVELLE IMAGE -->

                        <div
                            id="imagePreviewContainer"
                            style="display: none; margin-top: 16px;"
                        >

                            <p style="margin-bottom: 8px;">
                                Nouvelle image
                            </p>

                            <img
                                id="imagePreview"
                                src=""
                                alt="Aperçu de la nouvelle image"
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

                        <h3>Prix & rentabilité</h3>

                        <p>
                            Ajustez les données financières.
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
                                value="<?= htmlspecialchars(
                                    $formPurchasePrice
                                ) ?>"
                                required
                            >

                            <span>€</span>

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
                                value="<?= htmlspecialchars(
                                    $formSellingPrice
                                ) ?>"
                                required
                            >

                            <span>€</span>

                        </div>

                    </div>


                    <!-- APERÇU DE LA MARGE -->

                    <div class="profit-preview full-width">

                        <div>

                            <span>Marge par unité</span>

                            <strong id="marginPreview">
                                0,00 €
                            </strong>

                        </div>

                        <div>

                            <span>Taux de marge</span>

                            <strong id="marginRatePreview">
                                0 %
                            </strong>

                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================
                 GESTION DU STOCK
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon warning">

                        <i class="bi bi-bell"></i>

                    </div>

                    <div>

                        <h3>Gestion du stock</h3>

                        <p>
                            Configurez les alertes et vos notes.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- SEUIL DE STOCK -->

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
                            placeholder="Informations complémentaires..."
                        ><?= htmlspecialchars($formDescription) ?></textarea>

                    </div>

                </div>

            </section>


            <!-- =================================
                 BOUTONS
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
                    class="primary-action border-0"
                >

                    <i class="bi bi-check-lg"></i>

                    Enregistrer les modifications

                </button>

            </div>

        </form>

    </main>

</div>


<!-- =========================================
     CALCUL DE LA MARGE
========================================= -->

<script>

const purchaseInput =
    document.getElementById('purchase_price');

const sellingInput =
    document.getElementById('selling_price');

const marginPreview =
    document.getElementById('marginPreview');

const marginRatePreview =
    document.getElementById('marginRatePreview');


function updateMarginPreview() {

    const purchase =
        parseFloat(purchaseInput.value) || 0;

    const selling =
        parseFloat(sellingInput.value) || 0;

    const margin =
        selling - purchase;

    const marginRate =
        purchase > 0
            ? (margin / purchase) * 100
            : 0;

    marginPreview.textContent =
        margin.toLocaleString(
            'fr-FR',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        ) + ' €';

    marginRatePreview.textContent =
        marginRate.toLocaleString(
            'fr-FR',
            {
                maximumFractionDigits: 1
            }
        ) + ' %';

    marginPreview.classList.toggle(
        'negative',
        margin < 0
    );

    marginRatePreview.classList.toggle(
        'negative',
        margin < 0
    );

}

purchaseInput.addEventListener(
    'input',
    updateMarginPreview
);

sellingInput.addEventListener(
    'input',
    updateMarginPreview
);

updateMarginPreview();

</script>


<!-- =========================================
     GESTION DE L'APERÇU IMAGE
========================================= -->

<script>

const imageInput =
    document.getElementById('image');

const imagePreviewContainer =
    document.getElementById('imagePreviewContainer');

const imagePreview =
    document.getElementById('imagePreview');

const removeImageCheckbox =
    document.getElementById('remove_image');

const currentImageContainer =
    document.getElementById('currentImageContainer');

let currentPreviewUrl = null;


function clearImagePreview() {

    if (currentPreviewUrl !== null) {

        URL.revokeObjectURL(currentPreviewUrl);
        currentPreviewUrl = null;
    }

    imagePreview.removeAttribute('src');
    imagePreviewContainer.style.display = 'none';
}


imageInput.addEventListener('change', function () {

    clearImagePreview();

    const file = this.files[0];

    if (!file) {
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
        return;
    }

    if (file.size > maxSize) {

        alert("L'image ne doit pas dépasser 5 Mo.");

        this.value = '';
        return;
    }

    /*
     * Choisir une nouvelle image annule
     * une éventuelle demande de suppression.
     */

    if (removeImageCheckbox) {
        removeImageCheckbox.checked = false;
    }

    if (currentImageContainer) {
        currentImageContainer.style.display = 'block';
    }

    currentPreviewUrl = URL.createObjectURL(file);

    imagePreview.src = currentPreviewUrl;
    imagePreviewContainer.style.display = 'block';

});


if (removeImageCheckbox) {

    removeImageCheckbox.addEventListener(
        'change',
        function () {

            if (this.checked) {

                imageInput.value = '';
                clearImagePreview();

                if (currentImageContainer) {
                    currentImageContainer.style.display = 'none';
                }

            } else {

                if (currentImageContainer) {
                    currentImageContainer.style.display = 'block';
                }
            }
        }
    );

    /*
     * Refléter l'état du formulaire
     * après une erreur de validation.
     */

    if (
        removeImageCheckbox.checked &&
        currentImageContainer
    ) {
        currentImageContainer.style.display = 'none';
    }
}

window.addEventListener('beforeunload', clearImagePreview);

</script>


<?php require_once '../includes/footer.php'; ?>
