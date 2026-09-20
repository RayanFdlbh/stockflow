
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
   CSRF
========================================= */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================
   VARIABLES
========================================= */

$message = '';
$messageType = '';

$selectedProductId = '';
$formQuantity = '1';

/* =========================================
   RÉCUPÉRER LES PRODUITS DE L'UTILISATEUR
========================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        category,
        quantity,
        selling_price,
        image
    FROM products
    WHERE user_id = :user_id
    ORDER BY name ASC
");

$stmt->execute([
    'user_id' => $userId
]);

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   TRAITEMENT DU FORMULAIRE
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {

        $message = 'La session du formulaire est invalide. Veuillez réessayer.';

    } else {

        $productIdInput = $_POST['product_id'] ?? '';
        $quantityInput = $_POST['quantity'] ?? '';

        if (
            !is_string($productIdInput) ||
            !is_string($quantityInput)
        ) {

            $message = 'Les données du formulaire sont invalides.';

        } else {

            $selectedProductId = trim($productIdInput);
            $formQuantity = trim($quantityInput);

            /* =================================
               VALIDATION
            ================================= */

            if (
                !preg_match(
                    '/^[1-9][0-9]*$/D',
                    $selectedProductId
                ) ||
                (float) $selectedProductId > 4294967295
            ) {

                $message = 'Veuillez sélectionner un produit valide.';

            } elseif (
                !preg_match(
                    '/^[1-9][0-9]*$/D',
                    $formQuantity
                ) ||
                (float) $formQuantity > 4294967295
            ) {

                $message = 'La quantité vendue doit être un entier positif.';

            } else {

                /* =================================
                   ENREGISTREMENT TRANSACTIONNEL
                ================================= */

                try {

                    $pdo->beginTransaction();

                    /*
                     * Verrouiller le produit pour éviter
                     * deux ventes simultanées dépassant
                     * le stock disponible.
                     */

                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            quantity,
                            selling_price
                        FROM products
                        WHERE id = :id
                          AND user_id = :user_id
                        FOR UPDATE
                    ");

                    $stmt->execute([
                        'id' => (int) $selectedProductId,
                        'user_id' => $userId
                    ]);

                    $product = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {

                        $pdo->rollBack();

                        $message = 'Ce produit est introuvable.';

                    } else {

                        $saleQuantity = (int) $formQuantity;
                        $availableQuantity = (int) $product['quantity'];

                        if ($saleQuantity > $availableQuantity) {

                            $pdo->rollBack();

                            $message = 'Stock insuffisant : il reste '
                                . $availableQuantity
                                . ' unité(s) disponibles.';

                        } else {

                            /*
                             * 1. Enregistrer la vente.
                             *
                             * sale_price conserve le prix
                             * unitaire au moment de la vente.
                             */

                            $stmt = $pdo->prepare("
                                INSERT INTO sales (
                                    product_id,
                                    user_id,
                                    quantity,
                                    sale_price
                                )
                                VALUES (
                                    :product_id,
                                    :user_id,
                                    :quantity,
                                    :sale_price
                                )
                            ");

                            $stmt->execute([
                                'product_id' => (int) $product['id'],
                                'user_id' => $userId,
                                'quantity' => $saleQuantity,
                                'sale_price' => $product['selling_price']
                            ]);

                            /*
                             * 2. Diminuer le stock.
                             */

                            $stmt = $pdo->prepare("
                                UPDATE products
                                SET quantity = quantity - :quantity
                                WHERE id = :id
                                  AND user_id = :user_id
                                  AND quantity >= :minimum_quantity
                            ");

                            $stmt->execute([
                                'quantity' => $saleQuantity,
                                'id' => (int) $product['id'],
                                'user_id' => $userId,
                                'minimum_quantity' => $saleQuantity
                            ]);

                            if ($stmt->rowCount() !== 1) {

                                throw new RuntimeException(
                                    'Impossible de mettre à jour le stock.'
                                );
                            }

                            /*
                             * 3. Enregistrer le mouvement.
                             */

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
                                    'out',
                                    :quantity,
                                    :reason
                                )
                            ");

                            $stmt->execute([
                                'product_id' => (int) $product['id'],
                                'user_id' => $userId,
                                'quantity' => $saleQuantity,
                                'reason' => 'Vente'
                            ]);

                            /*
                             * 4. Valider l'ensemble.
                             */

                            $pdo->commit();

                            header('Location: index.php?created=1');
                            exit;
                        }
                    }

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'StockFlow sales/add.php : '
                        . $e->getMessage()
                    );

                    $message = 'Une erreur est survenue pendant l’enregistrement de la vente.';
                }
            }
        }
    }

    if ($message !== '') {
        $messageType = 'error';
    }
}

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Enregistrer une vente';

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

                <h1>Enregistrer une vente</h1>

                <p>
                    Ajoutez une vente à votre inventaire.
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
         CONTENU
    ====================================== -->

    <main class="main-content">

        <div class="page-heading">

            <div>

                <h2>Nouvelle vente</h2>

                <p>
                    Sélectionnez un produit et indiquez
                    la quantité vendue.
                </p>

            </div>

            <a
                href="../products/index.php"
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

            <div class="app-message <?= htmlspecialchars($messageType) ?>">

                <i class="bi bi-exclamation-circle"></i>

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <!-- =================================
             FORMULAIRE
        ================================== -->

        <form
            method="POST"
            action=""
            class="product-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token']
                ) ?>"
            >


            <!-- =================================
                 INFORMATIONS DE VENTE
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon">

                        <i class="bi bi-cart-check"></i>

                    </div>

                    <div>

                        <h3>Informations de la vente</h3>

                        <p>
                            Le stock sera mis à jour
                            automatiquement après validation.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <!-- PRODUIT -->

                    <div class="app-form-group full-width">

                        <label for="product_id">

                            Produit

                            <span>*</span>

                        </label>

                        <select
                            id="product_id"
                            name="product_id"
                            required
                        >

                            <option value="">
                                Sélectionnez un produit
                            </option>

                            <?php foreach ($products as $product): ?>

                                <option
                                    value="<?= (int) $product['id'] ?>"
                                    data-price="<?= htmlspecialchars(
                                        $product['selling_price']
                                    ) ?>"
                                    data-stock="<?= (int) $product['quantity'] ?>"
                                    <?= $selectedProductId === (string) $product['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                    <?= (int) $product['quantity'] === 0
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >

                                    <?= htmlspecialchars($product['name']) ?>

                                    —
                                    Stock : <?= (int) $product['quantity'] ?>

                                    —
                                    <?= number_format(
                                        (float) $product['selling_price'],
                                        2,
                                        ',',
                                        ' '
                                    ) ?> €

                                </option>

                            <?php endforeach; ?>

                        </select>

                        <small>
                            Les produits en rupture de stock
                            ne peuvent pas être sélectionnés.
                        </small>

                    </div>


                    <!-- QUANTITÉ -->

                    <div class="app-form-group">

                        <label for="quantity">

                            Quantité vendue

                            <span>*</span>

                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="1"
                            max="4294967295"
                            step="1"
                            value="<?= htmlspecialchars($formQuantity) ?>"
                            required
                        >

                    </div>


                    <!-- STOCK DISPONIBLE -->

                    <div class="app-form-group">

                        <label>
                            Stock disponible
                        </label>

                        <div
                            id="availableStock"
                            style="
                                padding: 12px 14px;
                                border: 1px solid #e5e7eb;
                                border-radius: 10px;
                            "
                        >
                            Sélectionnez un produit
                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================
                 RÉCAPITULATIF
            ================================== -->

            <section class="form-card">

                <div class="form-card-heading">

                    <div class="form-card-icon money">

                        <i class="bi bi-currency-euro"></i>

                    </div>

                    <div>

                        <h3>Récapitulatif</h3>

                        <p>
                            Montant calculé à partir du prix
                            de vente actuel du produit.
                        </p>

                    </div>

                </div>


                <div class="form-grid">

                    <div class="app-form-group">

                        <label>
                            Prix unitaire
                        </label>

                        <div
                            id="unitPricePreview"
                            style="
                                padding: 12px 14px;
                                border: 1px solid #e5e7eb;
                                border-radius: 10px;
                            "
                        >
                            0,00 €
                        </div>

                    </div>


                    <div class="app-form-group">

                        <label>
                            Total de la vente
                        </label>

                        <div
                            id="totalPreview"
                            style="
                                padding: 12px 14px;
                                border: 1px solid #e5e7eb;
                                border-radius: 10px;
                                font-weight: 700;
                            "
                        >
                            0,00 €
                        </div>

                    </div>

                </div>

            </section>


            <!-- =================================
                 ACTIONS
            ================================== -->

            <div class="form-actions">

                <a
                    href="../products/index.php"
                    class="secondary-action"
                >
                    Annuler
                </a>

                <button
                    type="submit"
                    class="primary-action border-0"
                    id="submitSale"
                    <?= count($products) === 0 ? 'disabled' : '' ?>
                >

                    <i class="bi bi-check-lg"></i>

                    Enregistrer la vente

                </button>

            </div>

        </form>

    </main>

</div>


<!-- =========================================
     APERÇU DYNAMIQUE DE LA VENTE
========================================= -->

<script>

const productSelect =
    document.getElementById('product_id');

const quantityInput =
    document.getElementById('quantity');

const availableStock =
    document.getElementById('availableStock');

const unitPricePreview =
    document.getElementById('unitPricePreview');

const totalPreview =
    document.getElementById('totalPreview');

const submitSale =
    document.getElementById('submitSale');


function formatPrice(value) {

    return value.toLocaleString(
        'fr-FR',
        {
            style: 'currency',
            currency: 'EUR'
        }
    );
}


function updateSalePreview() {

    const option =
        productSelect.options[productSelect.selectedIndex];

    if (!option || !option.value) {

        availableStock.textContent =
            'Sélectionnez un produit';

        unitPricePreview.textContent =
            formatPrice(0);

        totalPreview.textContent =
            formatPrice(0);

        submitSale.disabled = true;

        return;
    }

    const price =
        parseFloat(option.dataset.price) || 0;

    const stock =
        parseInt(option.dataset.stock, 10) || 0;

    const quantity =
        Number(quantityInput.value);

    availableStock.textContent =
        stock + ' unité(s)';

    unitPricePreview.textContent =
        formatPrice(price);

    const validQuantity =
        Number.isInteger(quantity) &&
        quantity >= 1 &&
        quantity <= stock;

    totalPreview.textContent =
        formatPrice(
            validQuantity
                ? price * quantity
                : 0
        );

    /*
     * Le contrôle JavaScript facilite
     * la saisie. Le serveur revérifie
     * toujours le stock avant la vente.
     */

    submitSale.disabled = !validQuantity;

    quantityInput.max = stock;

}


productSelect.addEventListener(
    'change',
    updateSalePreview
);

quantityInput.addEventListener(
    'input',
    updateSalePreview
);

updateSalePreview();

</script>


<?php require_once '../includes/footer.php'; ?>
