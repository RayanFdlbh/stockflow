
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
   PROTECTION HTML
========================================= */

function saleEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* =========================================
   FORMATAGE DES PRIX
========================================= */

function saleFormatPrice($value): string
{
    return number_format(
        (float) $value,
        2,
        ',',
        ' '
    ) . ' €';
}

/* =========================================
   FILTRES
========================================= */

$search = $_GET['search'] ?? '';
$period = $_GET['period'] ?? 'all';
$pageInput = $_GET['page'] ?? '1';

if (!is_string($search)) {
    $search = '';
}

if (!is_string($period)) {
    $period = 'all';
}

if (!is_string($pageInput)) {
    $pageInput = '1';
}

$search = trim($search);

if (mb_strlen($search, 'UTF-8') > 150) {
    $search = mb_substr($search, 0, 150, 'UTF-8');
}

$allowedPeriods = [
    'all',
    'today',
    'week',
    'month',
    'year'
];

if (!in_array($period, $allowedPeriods, true)) {
    $period = 'all';
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
   DATES DES FILTRES
========================================= */

$timezone = new DateTimeZone('Europe/Paris');

$now = new DateTimeImmutable(
    'now',
    $timezone
);

$periodStart = null;
$periodEnd = null;

switch ($period) {

    case 'today':

        $periodStart = $now->setTime(0, 0, 0);

        $periodEnd = $periodStart->modify('+1 day');

        break;

    case 'week':

        $periodStart = $now
            ->modify('monday this week')
            ->setTime(0, 0, 0);

        $periodEnd = $periodStart->modify('+1 week');

        break;

    case 'month':

        $periodStart = $now
            ->modify('first day of this month')
            ->setTime(0, 0, 0);

        $periodEnd = $periodStart->modify('+1 month');

        break;

    case 'year':

        $periodStart = $now
            ->setDate((int) $now->format('Y'), 1, 1)
            ->setTime(0, 0, 0);

        $periodEnd = $periodStart->modify('+1 year');

        break;
}

/* =========================================
   PARAMÈTRES DES REQUÊTES
========================================= */

$where = [
    's.user_id = :user_id'
];

$params = [
    'user_id' => $userId
];

if ($search !== '') {

    $where[] = 'p.name LIKE :search';

    $params['search'] = '%' . $search . '%';
}

if ($periodStart !== null && $periodEnd !== null) {

    $where[] = 's.created_at >= :period_start';

    $where[] = 's.created_at < :period_end';

    $params['period_start'] = $periodStart->format('Y-m-d H:i:s');

    $params['period_end'] = $periodEnd->format('Y-m-d H:i:s');
}

$whereSql = implode(' AND ', $where);

/* =========================================
   STATISTIQUES GLOBALES
========================================= */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_sales,
        COALESCE(SUM(quantity), 0) AS total_units,
        COALESCE(SUM(quantity * sale_price), 0) AS total_revenue
    FROM sales
    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$globalStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalSales = (int) ($globalStats['total_sales'] ?? 0);

$totalUnits = (int) ($globalStats['total_units'] ?? 0);

$totalRevenue = (float) ($globalStats['total_revenue'] ?? 0);

/* =========================================
   CHIFFRE D'AFFAIRES DU MOIS
========================================= */

$monthStart = $now
    ->modify('first day of this month')
    ->setTime(0, 0, 0);

$monthEnd = $monthStart->modify('+1 month');

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(quantity * sale_price), 0)
            AS monthly_revenue
    FROM sales
    WHERE user_id = :user_id
      AND created_at >= :month_start
      AND created_at < :month_end
");

$stmt->execute([
    'user_id' => $userId,
    'month_start' => $monthStart->format('Y-m-d H:i:s'),
    'month_end' => $monthEnd->format('Y-m-d H:i:s')
]);

$monthlyRevenue = (float) $stmt->fetchColumn();

/* =========================================
   STATISTIQUES DES RÉSULTATS FILTRÉS
========================================= */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS filtered_sales,
        COALESCE(SUM(s.quantity), 0) AS filtered_units,
        COALESCE(
            SUM(s.quantity * s.sale_price),
            0
        ) AS filtered_revenue
    FROM sales s
    INNER JOIN products p
        ON p.id = s.product_id
       AND p.user_id = s.user_id
    WHERE $whereSql
");

$stmt->execute($params);

$filteredStats = $stmt->fetch(PDO::FETCH_ASSOC);

$filteredSales = (int) ($filteredStats['filtered_sales'] ?? 0);

$filteredUnits = (int) ($filteredStats['filtered_units'] ?? 0);

$filteredRevenue = (float) ($filteredStats['filtered_revenue'] ?? 0);

/* =========================================
   PAGINATION
========================================= */

$totalPages = max(
    1,
    (int) ceil($filteredSales / $perPage)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $perPage;

/* =========================================
   RÉCUPÉRATION DES VENTES
========================================= */

$sql = "
    SELECT
        s.id,
        s.product_id,
        s.quantity,
        s.sale_price,
        s.created_at,
        p.name AS product_name,
        p.category AS product_category,
        p.image AS product_image
    FROM sales s
    INNER JOIN products p
        ON p.id = s.product_id
       AND p.user_id = s.user_id
    WHERE $whereSql
    ORDER BY
        s.created_at DESC,
        s.id DESC
    LIMIT :limit
    OFFSET :offset
";

$stmt = $pdo->prepare($sql);

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

$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   LIENS DE PAGINATION
========================================= */

function salePageUrl(int $targetPage): string
{
    global $search, $period;

    return 'index.php?' . http_build_query([
        'search' => $search,
        'period' => $period,
        'page' => $targetPage
    ]);
}

/* =========================================
   MESSAGE DE CONFIRMATION
========================================= */

$successMessage = '';

if (($_GET['created'] ?? '') === '1') {

    $successMessage = 'La vente a été enregistrée avec succès. Le stock a été mis à jour.';
}

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Historique des ventes';

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

                <h1>Gestion des ventes</h1>

                <p>
                    Consultez vos ventes et votre chiffre d'affaires.
                </p>

            </div>

        </div>

        <div class="topbar-actions">

            <div class="user-profile">

                <div class="user-avatar">

                    <?= saleEscape(
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
                        <?= saleEscape(
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

        <!-- EN-TÊTE -->

        <div class="page-heading">

            <div>

                <h2>Historique des ventes</h2>

                <p>
                    Retrouvez toutes vos transactions
                    et suivez votre activité commerciale.
                </p>

            </div>

            <a
                href="add.php"
                class="primary-action"
            >

                <i class="bi bi-plus-lg"></i>

                Nouvelle vente

            </a>

        </div>


        <!-- =================================
             MESSAGE DE SUCCÈS
        ================================== -->

        <?php if ($successMessage !== ''): ?>

            <div class="app-message success">

                <i class="bi bi-check-circle"></i>

                <?= saleEscape($successMessage) ?>

            </div>

        <?php endif; ?>


        <!-- =================================
             STATISTIQUES GLOBALES
        ================================== -->

        <div
            class="sales-stats-grid"
            style="
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 20px;
                margin-bottom: 28px;
            "
        >

            <!-- CHIFFRE D'AFFAIRES TOTAL -->

            <div class="form-card" style="margin: 0;">

                <div class="form-card-heading">

                    <div class="form-card-icon money">
                        <i class="bi bi-currency-euro"></i>
                    </div>

                    <div>
                        <p>Chiffre d'affaires total</p>
                    </div>

                </div>

                <h3 style="font-size: 26px; margin-top: 12px;">

                    <?= saleFormatPrice($totalRevenue) ?>

                </h3>

                <small>
                    Depuis la première vente enregistrée
                </small>

            </div>


            <!-- CHIFFRE D'AFFAIRES DU MOIS -->

            <div class="form-card" style="margin: 0;">

                <div class="form-card-heading">

                    <div class="form-card-icon">
                        <i class="bi bi-calendar-month"></i>
                    </div>

                    <div>
                        <p>Chiffre d'affaires du mois</p>
                    </div>

                </div>

                <h3 style="font-size: 26px; margin-top: 12px;">

                    <?= saleFormatPrice($monthlyRevenue) ?>

                </h3>

                <small>
                    <?= saleEscape(
                        ucfirst(
                            $now->format('m/Y')
                        )
                    ) ?>
                </small>

            </div>


            <!-- NOMBRE DE VENTES -->

            <div class="form-card" style="margin: 0;">

                <div class="form-card-heading">

                    <div class="form-card-icon">
                        <i class="bi bi-receipt"></i>
                    </div>

                    <div>
                        <p>Nombre de ventes</p>
                    </div>

                </div>

                <h3 style="font-size: 26px; margin-top: 12px;">

                    <?= number_format(
                        $totalSales,
                        0,
                        ',',
                        ' '
                    ) ?>

                </h3>

                <small>
                    Transactions enregistrées
                </small>

            </div>


            <!-- UNITÉS VENDUES -->

            <div class="form-card" style="margin: 0;">

                <div class="form-card-heading">

                    <div class="form-card-icon warning">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <div>
                        <p>Unités vendues</p>
                    </div>

                </div>

                <h3 style="font-size: 26px; margin-top: 12px;">

                    <?= number_format(
                        $totalUnits,
                        0,
                        ',',
                        ' '
                    ) ?>

                </h3>

                <small>
                    Quantité totale écoulée
                </small>

            </div>

        </div>


        <!-- =================================
             FILTRES
        ================================== -->

        <section class="form-card">

            <div class="form-card-heading">

                <div class="form-card-icon">
                    <i class="bi bi-funnel"></i>
                </div>

                <div>

                    <h3>Rechercher une vente</h3>

                    <p>
                        Filtrez l'historique par produit ou par période.
                    </p>

                </div>

            </div>


            <form
                method="GET"
                action="index.php"
                class="form-grid"
            >

                <!-- RECHERCHE -->

                <div class="app-form-group">

                    <label for="search">
                        Nom du produit
                    </label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        maxlength="150"
                        placeholder="Rechercher un produit..."
                        value="<?= saleEscape($search) ?>"
                    >

                </div>


                <!-- PÉRIODE -->

                <div class="app-form-group">

                    <label for="period">
                        Période
                    </label>

                    <select
                        id="period"
                        name="period"
                    >

                        <option
                            value="all"
                            <?= $period === 'all' ? 'selected' : '' ?>
                        >
                            Toutes les périodes
                        </option>

                        <option
                            value="today"
                            <?= $period === 'today' ? 'selected' : '' ?>
                        >
                            Aujourd'hui
                        </option>

                        <option
                            value="week"
                            <?= $period === 'week' ? 'selected' : '' ?>
                        >
                            Cette semaine
                        </option>

                        <option
                            value="month"
                            <?= $period === 'month' ? 'selected' : '' ?>
                        >
                            Ce mois-ci
                        </option>

                        <option
                            value="year"
                            <?= $period === 'year' ? 'selected' : '' ?>
                        >
                            Cette année
                        </option>

                    </select>

                </div>


                <!-- ACTIONS -->

                <div class="app-form-group full-width">

                    <div
                        style="
                            display: flex;
                            flex-wrap: wrap;
                            gap: 12px;
                        "
                    >

                        <button
                            type="submit"
                            class="primary-action border-0"
                        >

                            <i class="bi bi-search"></i>

                            Rechercher

                        </button>

                        <a
                            href="index.php"
                            class="secondary-action"
                        >

                            <i class="bi bi-arrow-counterclockwise"></i>

                            Réinitialiser

                        </a>

                    </div>

                </div>

            </form>

        </section>


        <!-- =================================
             RÉSULTATS FILTRÉS
        ================================== -->

        <section class="form-card">

            <div class="form-card-heading">

                <div class="form-card-icon money">
                    <i class="bi bi-bar-chart"></i>
                </div>

                <div>

                    <h3>Résultats de la recherche</h3>

                    <p>
                        Statistiques correspondant aux filtres actifs.
                    </p>

                </div>

            </div>


            <div
                class="sales-filtered-stats"
                style="
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 20px;
                "
            >

                <div>

                    <small>Ventes</small>

                    <h3 style="margin-top: 8px;">
                        <?= number_format(
                            $filteredSales,
                            0,
                            ',',
                            ' '
                        ) ?>
                    </h3>

                </div>


                <div>

                    <small>Unités vendues</small>

                    <h3 style="margin-top: 8px;">
                        <?= number_format(
                            $filteredUnits,
                            0,
                            ',',
                            ' '
                        ) ?>
                    </h3>

                </div>


                <div>

                    <small>Chiffre d'affaires</small>

                    <h3 style="margin-top: 8px;">
                        <?= saleFormatPrice($filteredRevenue) ?>
                    </h3>

                </div>

            </div>

        </section>


        <!-- =================================
             TABLEAU DES VENTES
        ================================== -->

        <section class="form-card">

            <div class="form-card-heading">

                <div class="form-card-icon">
                    <i class="bi bi-clock-history"></i>
                </div>

                <div>

                    <h3>Transactions</h3>

                    <p>
                        <?= number_format(
                            $filteredSales,
                            0,
                            ',',
                            ' '
                        ) ?>
                        vente(s) trouvée(s).
                    </p>

                </div>

            </div>


            <?php if (empty($sales)): ?>

                <!-- ÉTAT VIDE -->

                <div
                    style="
                        text-align: center;
                        padding: 48px 20px;
                    "
                >

                    <i
                        class="bi bi-cart-x"
                        style="font-size: 42px;"
                    ></i>

                    <h3 style="margin-top: 16px;">
                        Aucune vente trouvée
                    </h3>

                    <p style="margin-top: 8px;">
                        Aucun enregistrement ne correspond
                        aux critères sélectionnés.
                    </p>

                    <a
                        href="add.php"
                        class="primary-action"
                        style="
                            display: inline-flex;
                            margin-top: 20px;
                        "
                    >

                        <i class="bi bi-plus-lg"></i>

                        Enregistrer une vente

                    </a>

                </div>

            <?php else: ?>

                <div
                    style="
                        width: 100%;
                        overflow-x: auto;
                    "
                >

                    <table
                        style="
                            width: 100%;
                            border-collapse: collapse;
                            min-width: 780px;
                        "
                    >

                        <thead>

                            <tr
                                style="
                                    text-align: left;
                                    border-bottom: 1px solid #e5e7eb;
                                "
                            >

                                <th style="padding: 14px 12px;">
                                    Produit
                                </th>

                                <th style="padding: 14px 12px;">
                                    Date
                                </th>

                                <th style="padding: 14px 12px;">
                                    Quantité
                                </th>

                                <th style="padding: 14px 12px;">
                                    Prix unitaire
                                </th>

                                <th style="padding: 14px 12px;">
                                    Total
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($sales as $sale): ?>

                                <?php

                                $saleTotal =
                                    (int) $sale['quantity']
                                    * (float) $sale['sale_price'];

                                $saleDate = new DateTimeImmutable(
                                    $sale['created_at'],
                                    $timezone
                                );

                                ?>

                                <tr
                                    style="
                                        border-bottom: 1px solid #e5e7eb;
                                    "
                                >

                                    <!-- PRODUIT -->

                                    <td style="padding: 14px 12px;">

                                        <div
                                            style="
                                                display: flex;
                                                align-items: center;
                                                gap: 12px;
                                            "
                                        >

                                            <?php if (!empty($sale['product_image'])): ?>

                                                <img
                                                    src="/stockflow/uploads/products/<?= saleEscape(
                                                        rawurlencode(
                                                            $sale['product_image']
                                                        )
                                                    ) ?>"
                                                    alt="<?= saleEscape(
                                                        $sale['product_name']
                                                    ) ?>"
                                                    style="
                                                        width: 46px;
                                                        height: 46px;
                                                        border-radius: 10px;
                                                        object-fit: cover;
                                                    "
                                                >

                                            <?php else: ?>

                                                <div
                                                    style="
                                                        width: 46px;
                                                        height: 46px;
                                                        display: flex;
                                                        align-items: center;
                                                        justify-content: center;
                                                        border-radius: 10px;
                                                        background: #f3f4f6;
                                                    "
                                                >

                                                    <i class="bi bi-box-seam"></i>

                                                </div>

                                            <?php endif; ?>


                                            <div>

                                                <strong>
                                                    <?= saleEscape(
                                                        $sale['product_name']
                                                    ) ?>
                                                </strong>

                                                <div>

                                                    <small>
                                                        <?= saleEscape(
                                                            $sale['product_category']
                                                        ) ?>
                                                    </small>

                                                </div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- DATE -->

                                    <td style="padding: 14px 12px;">

                                        <?= saleEscape(
                                            $saleDate->format('d/m/Y')
                                        ) ?>

                                        <div>

                                            <small>
                                                <?= saleEscape(
                                                    $saleDate->format('H:i')
                                                ) ?>
                                            </small>

                                        </div>

                                    </td>


                                    <!-- QUANTITÉ -->

                                    <td style="padding: 14px 12px;">

                                        <?= number_format(
                                            (int) $sale['quantity'],
                                            0,
                                            ',',
                                            ' '
                                        ) ?>

                                    </td>


                                    <!-- PRIX UNITAIRE -->

                                    <td style="padding: 14px 12px;">

                                        <?= saleFormatPrice(
                                            $sale['sale_price']
                                        ) ?>

                                    </td>


                                    <!-- TOTAL -->

                                    <td style="padding: 14px 12px;">

                                        <strong>
                                            <?= saleFormatPrice($saleTotal) ?>
                                        </strong>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- =================================
                     PAGINATION
                ================================== -->

                <?php if ($totalPages > 1): ?>

                    <div
                        style="
                            display: flex;
                            justify-content: space-between;
                            align-items: center;
                            flex-wrap: wrap;
                            gap: 12px;
                            margin-top: 24px;
                        "
                    >

                        <span>

                            Page <?= $page ?>
                            sur <?= $totalPages ?>

                        </span>


                        <div
                            style="
                                display: flex;
                                gap: 10px;
                                align-items: center;
                                flex-wrap: wrap;
                            "
                        >

                            <?php if ($page > 1): ?>

                                <a
                                    href="<?= saleEscape(
                                        salePageUrl($page - 1)
                                    ) ?>"
                                    class="secondary-action"
                                >

                                    <i class="bi bi-chevron-left"></i>

                                    Précédent

                                </a>

                            <?php endif; ?>


                            <?php if ($page < $totalPages): ?>

                                <a
                                    href="<?= saleEscape(
                                        salePageUrl($page + 1)
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
     RESPONSIVE
========================================= -->

<style>

@media (max-width: 1200px) {

    .sales-stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

}

@media (max-width: 650px) {

    .sales-stats-grid {
        grid-template-columns: 1fr !important;
    }

    .sales-filtered-stats {
        grid-template-columns: 1fr !important;
    }

}

</style>


<?php require_once '../includes/footer.php'; ?>
