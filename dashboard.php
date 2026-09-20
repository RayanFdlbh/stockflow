
<?php

session_start();

/* =========================================
   PROTECTION
========================================= */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'config/database.php';

$userId = (int) $_SESSION['user_id'];

/* =========================================
   FONCTIONS D'AFFICHAGE
========================================= */

function dashboardEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dashboardPrice($value): string
{
    return number_format(
        (float) $value,
        2,
        ',',
        ' '
    ) . ' €';
}

function dashboardDate($value): string
{
    if (empty($value)) {
        return '—';
    }

    return date(
        'd/m/Y',
        strtotime((string) $value)
    );
}

function dashboardDateTime($value): string
{
    if (empty($value)) {
        return '—';
    }

    return date(
        'd/m/Y à H:i',
        strtotime((string) $value)
    );
}

/* =========================================
   STATISTIQUES PRINCIPALES DU STOCK
========================================= */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_products,

        COALESCE(
            SUM(quantity * purchase_price),
            0
        ) AS stock_value,

        COALESCE(
            SUM(
                quantity * (
                    selling_price - purchase_price
                )
            ),
            0
        ) AS potential_profit,

        COALESCE(
            SUM(
                CASE
                    WHEN quantity <= low_stock_threshold
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock_count

    FROM products

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   ÉTAT DU STOCK
========================================= */

$stmt = $pdo->prepare("
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN quantity = 0
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS out_of_stock,

        COALESCE(
            SUM(
                CASE
                    WHEN quantity > 0
                     AND quantity <= low_stock_threshold
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock,

        COALESCE(
            SUM(
                CASE
                    WHEN quantity > low_stock_threshold
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS healthy_stock

    FROM products

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$stockStatus = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   PRODUITS EN STOCK FAIBLE
========================================= */

$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        quantity,
        low_stock_threshold,
        image

    FROM products

    WHERE user_id = :user_id
      AND quantity <= low_stock_threshold

    ORDER BY
        quantity ASC,
        name ASC

    LIMIT 5
");

$stmt->execute([
    'user_id' => $userId
]);

$lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   ACTIVITÉ RÉCENTE
========================================= */

$stmt = $pdo->prepare("
    SELECT
        sm.type,
        sm.quantity,
        sm.reason,
        sm.created_at,
        p.name AS product_name

    FROM stock_movements sm

    INNER JOIN products p
        ON sm.product_id = p.id
       AND sm.user_id = p.user_id

    WHERE sm.user_id = :user_id

    ORDER BY
        sm.created_at DESC,
        sm.id DESC

    LIMIT 5
");

$stmt->execute([
    'user_id' => $userId
]);

$recentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   VALEUR DU STOCK PAR CATÉGORIE
========================================= */

$stmt = $pdo->prepare("
    SELECT
        category,
        SUM(quantity * purchase_price) AS total_value

    FROM products

    WHERE user_id = :user_id

    GROUP BY category

    ORDER BY total_value DESC

    LIMIT 7
");

$stmt->execute([
    'user_id' => $userId
]);

$categoryValues = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
$categoryData = [];

foreach ($categoryValues as $category) {

    $categoryLabels[] = $category['category'];

    $categoryData[] = round(
        (float) $category['total_value'],
        2
    );
}

/* =========================================
   STATISTIQUES GLOBALES DES VENTES
========================================= */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_sales,

        COALESCE(
            SUM(quantity),
            0
        ) AS total_units,

        COALESCE(
            SUM(quantity * sale_price),
            0
        ) AS total_revenue

    FROM sales

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$salesStats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalSales = (int) ($salesStats['total_sales'] ?? 0);

$totalUnits = (int) ($salesStats['total_units'] ?? 0);

$totalRevenue = (float) ($salesStats['total_revenue'] ?? 0);

/* =========================================
   VENTES DU MOIS
========================================= */

$timezone = new DateTimeZone('Europe/Paris');

$now = new DateTimeImmutable(
    'now',
    $timezone
);

$monthStart = $now
    ->modify('first day of this month')
    ->setTime(0, 0, 0);

$monthEnd = $monthStart->modify('+1 month');

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS monthly_sales,

        COALESCE(
            SUM(quantity),
            0
        ) AS monthly_units,

        COALESCE(
            SUM(quantity * sale_price),
            0
        ) AS monthly_revenue

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

$monthlyStats = $stmt->fetch(PDO::FETCH_ASSOC);

$monthlySales = (int) ($monthlyStats['monthly_sales'] ?? 0);

$monthlyUnits = (int) ($monthlyStats['monthly_units'] ?? 0);

$monthlyRevenue = (float) ($monthlyStats['monthly_revenue'] ?? 0);

/* =========================================
   CINQ DERNIÈRES VENTES
========================================= */

$stmt = $pdo->prepare("
    SELECT
        s.id,
        s.product_id,
        s.quantity,
        s.sale_price,
        s.created_at,

        p.name AS product_name,
        p.image AS product_image

    FROM sales s

    INNER JOIN products p
        ON p.id = s.product_id
       AND p.user_id = s.user_id

    WHERE s.user_id = :user_id

    ORDER BY
        s.created_at DESC,
        s.id DESC

    LIMIT 5
");

$stmt->execute([
    'user_id' => $userId
]);

$recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   DONNÉES POUR LE GRAPHIQUE DES VENTES
   SEPT DERNIERS JOURS
========================================= */

$sevenDaysStart = $now
    ->setTime(0, 0, 0)
    ->modify('-6 days');

$sevenDaysEnd = $now
    ->setTime(0, 0, 0)
    ->modify('+1 day');

$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) AS sale_day,

        COALESCE(
            SUM(quantity * sale_price),
            0
        ) AS revenue

    FROM sales

    WHERE user_id = :user_id
      AND created_at >= :start_date
      AND created_at < :end_date

    GROUP BY DATE(created_at)

    ORDER BY sale_day ASC
");

$stmt->execute([
    'user_id' => $userId,
    'start_date' => $sevenDaysStart->format('Y-m-d H:i:s'),
    'end_date' => $sevenDaysEnd->format('Y-m-d H:i:s')
]);

$dailySalesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dailyRevenueMap = [];

foreach ($dailySalesRows as $row) {

    $dailyRevenueMap[$row['sale_day']] =
        (float) $row['revenue'];
}

$salesChartLabels = [];
$salesChartData = [];

for ($i = 0; $i < 7; $i++) {

    $day = $sevenDaysStart->modify("+{$i} days");

    $dayKey = $day->format('Y-m-d');

    $salesChartLabels[] = $day->format('d/m');

    $salesChartData[] = round(
        $dailyRevenueMap[$dayKey] ?? 0,
        2
    );
}

/* =========================================
   DONNÉES D'AFFICHAGE
========================================= */

$totalStatus =
    (int) $stockStatus['healthy_stock']
    + (int) $stockStatus['low_stock']
    + (int) $stockStatus['out_of_stock'];

$pageTitle = 'Dashboard';

require_once 'includes/header.php';
require_once 'includes/sidebar.php';

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

                <h1>

                    Bonjour,

                    <?= dashboardEscape(
                        $_SESSION['username'] ?? 'Utilisateur'
                    ) ?> 👋

                </h1>

                <p>
                    Voici un aperçu de votre activité.
                </p>

            </div>

        </div>


        <div class="topbar-actions">

            <a
                href="/stockflow/products/index.php"
                class="icon-button"
                aria-label="Voir les alertes de stock"
                title="Voir les produits"
            >

                <i class="bi bi-bell"></i>

                <?php if ((int) $stats['low_stock_count'] > 0): ?>

                    <span class="notification-dot"></span>

                <?php endif; ?>

            </a>


            <div class="user-profile">

                <div class="user-avatar">

                    <?= dashboardEscape(
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

                        <?= dashboardEscape(
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

        <!-- =================================
             EN-TÊTE
        ================================== -->

        <div class="page-heading">

            <div>

                <h2>Vue d'ensemble</h2>

                <p>
                    Suivez votre stock et vos performances.
                </p>

            </div>


            <div class="dashboard-heading-actions">

                <a
                    href="/stockflow/sales/add.php"
                    class="secondary-action"
                >

                    <i class="bi bi-cart-plus"></i>

                    Nouvelle vente

                </a>

                <a
                    href="/stockflow/products/add.php"
                    class="primary-action"
                >

                    <i class="bi bi-plus-lg"></i>

                    Ajouter un produit

                </a>

            </div>

        </div>


        <!-- =================================
             STATISTIQUES DU STOCK
        ================================== -->

        <section class="stats-grid">

            <!-- PRODUITS -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon products-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <span class="stat-label">
                        Produits
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        (int) $stats['total_products'],
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Produits différents enregistrés
                </span>

            </article>


            <!-- VALEUR DU STOCK -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon value-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>

                    <span class="stat-label">
                        Valeur du stock
                    </span>

                </div>

                <strong class="stat-value">

                    <?= dashboardPrice(
                        $stats['stock_value']
                    ) ?>

                </strong>

                <span class="stat-description">
                    Basée sur vos prix d'achat
                </span>

            </article>


            <!-- BÉNÉFICE POTENTIEL -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon profit-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <span class="stat-label">
                        Bénéfice potentiel
                    </span>

                </div>

                <strong class="stat-value">

                    <?= dashboardPrice(
                        $stats['potential_profit']
                    ) ?>

                </strong>

                <span class="stat-description">
                    Si tout le stock est vendu
                </span>

            </article>


            <!-- STOCK FAIBLE -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon alert-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>

                    <span class="stat-label">
                        Stock faible
                    </span>

                </div>

                <strong class="stat-value">

                    <?= (int) $stats['low_stock_count'] ?>

                </strong>

                <span class="stat-description">
                    Produits nécessitant votre attention
                </span>

            </article>

        </section>


        <!-- =================================
             SECTION VENTES
        ================================== -->

        <div class="dashboard-section-heading">

            <div>

                <h2>Performances commerciales</h2>

                <p>
                    Vos ventes et votre chiffre d'affaires.
                </p>

            </div>

            <a
                href="/stockflow/sales/index.php"
                class="secondary-action"
            >

                <i class="bi bi-arrow-right"></i>

                Historique des ventes

            </a>

        </div>


        <!-- =================================
             STATISTIQUES DES VENTES
        ================================== -->

        <section class="stats-grid sales-stats-grid">

            <!-- CHIFFRE D'AFFAIRES TOTAL -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon value-icon">
                        <i class="bi bi-currency-euro"></i>
                    </div>

                    <span class="stat-label">
                        Chiffre d'affaires
                    </span>

                </div>

                <strong class="stat-value">

                    <?= dashboardPrice($totalRevenue) ?>

                </strong>

                <span class="stat-description">
                    Total des ventes enregistrées
                </span>

            </article>


            <!-- CHIFFRE D'AFFAIRES DU MOIS -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon profit-icon">
                        <i class="bi bi-calendar-month"></i>
                    </div>

                    <span class="stat-label">
                        CA du mois
                    </span>

                </div>

                <strong class="stat-value">

                    <?= dashboardPrice($monthlyRevenue) ?>

                </strong>

                <span class="stat-description">

                    <?= $monthlySales ?> vente(s) ce mois-ci

                </span>

            </article>


            <!-- NOMBRE DE VENTES -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon products-icon">
                        <i class="bi bi-receipt"></i>
                    </div>

                    <span class="stat-label">
                        Nombre de ventes
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        $totalSales,
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">
                    Transactions enregistrées
                </span>

            </article>


            <!-- UNITÉS VENDUES -->

            <article class="stat-card">

                <div class="stat-card-top">

                    <div class="stat-icon alert-icon">
                        <i class="bi bi-bag-check"></i>
                    </div>

                    <span class="stat-label">
                        Unités vendues
                    </span>

                </div>

                <strong class="stat-value">

                    <?= number_format(
                        $totalUnits,
                        0,
                        ',',
                        ' '
                    ) ?>

                </strong>

                <span class="stat-description">

                    <?= number_format(
                        $monthlyUnits,
                        0,
                        ',',
                        ' '
                    ) ?> ce mois-ci

                </span>

            </article>

        </section>


        <!-- =================================
             GRAPHIQUE DES VENTES
        ================================== -->

        <section class="dashboard-grid">

            <article class="dashboard-card chart-card-large">

                <div class="card-heading">

                    <div>

                        <h3>
                            Chiffre d'affaires des 7 derniers jours
                        </h3>

                        <p>
                            Évolution quotidienne de vos ventes.
                        </p>

                    </div>

                    <i class="bi bi-graph-up card-heading-icon"></i>

                </div>


                <div class="chart-container">

                    <?php if ($totalSales > 0): ?>

                        <canvas id="salesChart"></canvas>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-graph-up"></i>

                            <strong>
                                Aucune vente enregistrée
                            </strong>

                            <span>
                                Enregistrez votre première vente
                                pour suivre votre chiffre d'affaires.
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </article>


            <!-- DERNIÈRES VENTES -->

            <article class="dashboard-card">

                <div class="card-heading">

                    <div>

                        <h3>Dernières ventes</h3>

                        <p>
                            Vos cinq dernières transactions.
                        </p>

                    </div>

                    <a href="/stockflow/sales/index.php">
                        Tout voir
                    </a>

                </div>


                <?php if (!empty($recentSales)): ?>

                    <div class="activity-list">

                        <?php foreach ($recentSales as $sale): ?>

                            <?php

                            $saleTotal =
                                (int) $sale['quantity']
                                * (float) $sale['sale_price'];

                            ?>

                            <div class="activity-item">

                                <div class="activity-icon">

                                    <i class="bi bi-cart-check"></i>

                                </div>


                                <div class="activity-content">

                                    <strong>

                                        <?= dashboardEscape(
                                            $sale['product_name']
                                        ) ?>

                                    </strong>

                                    <span>

                                        <?= (int) $sale['quantity'] ?>
                                        unité(s)

                                        ·

                                        <?= dashboardPrice($saleTotal) ?>

                                    </span>

                                </div>


                                <time
                                    title="<?= dashboardEscape(
                                        dashboardDateTime(
                                            $sale['created_at']
                                        )
                                    ) ?>"
                                >

                                    <?= dashboardDate(
                                        $sale['created_at']
                                    ) ?>

                                </time>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state compact">

                        <i class="bi bi-cart-x"></i>

                        <strong>
                            Aucune vente
                        </strong>

                        <span>
                            Vos dernières ventes apparaîtront ici.
                        </span>

                    </div>

                <?php endif; ?>

            </article>

        </section>


        <!-- =================================
             GRAPHIQUES DU STOCK
        ================================== -->

        <div class="dashboard-section-heading">

            <div>

                <h2>Inventaire</h2>

                <p>
                    Répartition et état de votre stock.
                </p>

            </div>

        </div>


        <section class="dashboard-grid">

            <!-- VALEUR PAR CATÉGORIE -->

            <article class="dashboard-card chart-card-large">

                <div class="card-heading">

                    <div>

                        <h3>
                            Valeur du stock par catégorie
                        </h3>

                        <p>
                            Répartition actuelle de votre investissement.
                        </p>

                    </div>

                    <i class="bi bi-bar-chart-line card-heading-icon"></i>

                </div>


                <div class="chart-container">

                    <?php if (!empty($categoryValues)): ?>

                        <canvas id="categoryChart"></canvas>

                    <?php else: ?>

                        <div class="empty-state">

                            <i class="bi bi-bar-chart"></i>

                            <strong>
                                Aucune donnée disponible
                            </strong>

                            <span>
                                Ajoutez vos premiers produits
                                pour afficher le graphique.
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </article>


            <!-- ÉTAT DU STOCK -->

            <article class="dashboard-card">

                <div class="card-heading">

                    <div>

                        <h3>État du stock</h3>

                        <p>
                            Santé générale de votre inventaire.
                        </p>

                    </div>

                </div>


                <?php if ($totalStatus > 0): ?>

                    <div class="donut-wrapper">

                        <div class="donut-container">

                            <canvas id="stockStatusChart"></canvas>

                        </div>


                        <div class="stock-legend">

                            <div>

                                <span class="legend-dot healthy"></span>

                                Stock correct

                                <strong>

                                    <?= (int) $stockStatus['healthy_stock'] ?>

                                </strong>

                            </div>


                            <div>

                                <span class="legend-dot warning"></span>

                                Stock faible

                                <strong>

                                    <?= (int) $stockStatus['low_stock'] ?>

                                </strong>

                            </div>


                            <div>

                                <span class="legend-dot danger"></span>

                                Rupture

                                <strong>

                                    <?= (int) $stockStatus['out_of_stock'] ?>

                                </strong>

                            </div>

                        </div>

                    </div>

                <?php else: ?>

                    <div class="empty-state compact">

                        <i class="bi bi-pie-chart"></i>

                        <strong>
                            Aucun produit
                        </strong>

                        <span>
                            L'état de votre stock apparaîtra ici.
                        </span>

                    </div>

                <?php endif; ?>

            </article>

        </section>


        <!-- =================================
             BAS DU DASHBOARD
        ================================== -->

        <section class="dashboard-grid bottom-grid">

            <!-- ACTIVITÉ RÉCENTE -->

            <article class="dashboard-card">

                <div class="card-heading">

                    <div>

                        <h3>Activité récente</h3>

                        <p>
                            Derniers mouvements de votre inventaire.
                        </p>

                    </div>

                    <a href="/stockflow/movements/index.php">
                        Tout voir
                    </a>

                </div>


                <?php if (!empty($recentActivities)): ?>

                    <div class="activity-list">

                        <?php foreach ($recentActivities as $activity): ?>

                            <div class="activity-item">

                                <div class="activity-icon">

                                    <?php if ($activity['type'] === 'in'): ?>

                                        <i class="bi bi-arrow-down-left"></i>

                                    <?php elseif ($activity['type'] === 'out'): ?>

                                        <i class="bi bi-arrow-up-right"></i>

                                    <?php else: ?>

                                        <i class="bi bi-arrow-repeat"></i>

                                    <?php endif; ?>

                                </div>


                                <div class="activity-content">

                                    <strong>

                                        <?= dashboardEscape(
                                            $activity['product_name']
                                        ) ?>

                                    </strong>

                                    <span>

                                        <?= $activity['type'] === 'in'
                                            ? 'Entrée'
                                            : (
                                                $activity['type'] === 'out'
                                                ? 'Sortie'
                                                : 'Ajustement'
                                            )
                                        ?>

                                        ·

                                        <?= (int) $activity['quantity'] ?>
                                        unité(s)

                                    </span>

                                </div>


                                <time>

                                    <?= dashboardDate(
                                        $activity['created_at']
                                    ) ?>

                                </time>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state compact">

                        <i class="bi bi-clock-history"></i>

                        <strong>
                            Aucune activité
                        </strong>

                        <span>
                            Vos mouvements récents apparaîtront ici.
                        </span>

                    </div>

                <?php endif; ?>

            </article>


            <!-- PRODUITS À SURVEILLER -->

            <article class="dashboard-card">

                <div class="card-heading">

                    <div>

                        <h3>À surveiller</h3>

                        <p>
                            Produits en stock faible ou épuisés.
                        </p>

                    </div>

                    <a href="/stockflow/products/index.php">
                        Produits
                    </a>

                </div>


                <?php if (!empty($lowStockProducts)): ?>

                    <div class="low-stock-list">

                        <?php foreach ($lowStockProducts as $product): ?>

                            <div class="low-stock-item">

                                <div class="product-mini-image">

                                    <?php if (!empty($product['image'])): ?>

                                        <img
                                            src="/stockflow/uploads/products/<?= dashboardEscape(
                                                rawurlencode(
                                                    $product['image']
                                                )
                                            ) ?>"
                                            alt=""
                                        >

                                    <?php else: ?>

                                        <i class="bi bi-box"></i>

                                    <?php endif; ?>

                                </div>


                                <div class="low-stock-info">

                                    <strong>

                                        <?= dashboardEscape(
                                            $product['name']
                                        ) ?>

                                    </strong>

                                    <span>

                                        Seuil :

                                        <?= (int) $product['low_stock_threshold'] ?>

                                    </span>

                                </div>


                                <span
                                    class="stock-badge <?= (int) $product['quantity'] === 0
                                        ? 'out'
                                        : 'low'
                                    ?>"
                                >

                                    <?= (int) $product['quantity'] === 0
                                        ? 'Rupture'
                                        : (int) $product['quantity'] . ' restant(s)'
                                    ?>

                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="empty-state compact">

                        <i class="bi bi-check-circle"></i>

                        <strong>
                            Tout va bien
                        </strong>

                        <span>
                            Aucun produit n'est actuellement en stock faible.
                        </span>

                    </div>

                <?php endif; ?>

            </article>

        </section>

    </main>

</div>


<!-- =========================================
     STYLE COMPLÉMENTAIRE
========================================= -->

<style>

.dashboard-heading-actions {

    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;

}

.dashboard-section-heading {

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;

    margin: 32px 0 20px;

}

.dashboard-section-heading h2 {

    margin: 0 0 6px;
    font-size: 22px;

}

.dashboard-section-heading p {

    margin: 0;
    opacity: 0.75;

}

@media (max-width: 700px) {

    .dashboard-section-heading {

        flex-direction: column;
        align-items: flex-start;

    }

    .dashboard-heading-actions {

        width: 100%;

    }

}

</style>


<!-- =========================================
     CHART.JS
========================================= -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js"></script>

<script>

/* =========================================
   DONNÉES DES GRAPHIQUES
========================================= */

const categoryLabels = <?= json_encode(
    $categoryLabels,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;

const categoryData = <?= json_encode(
    $categoryData
) ?>;

const salesChartLabels = <?= json_encode(
    $salesChartLabels,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;

const salesChartData = <?= json_encode(
    $salesChartData
) ?>;


/* =========================================
   GRAPHIQUE DES VENTES
========================================= */

const salesCanvas =
    document.getElementById('salesChart');

if (salesCanvas && typeof Chart !== 'undefined') {

    new Chart(salesCanvas, {

        type: 'line',

        data: {

            labels: salesChartLabels,

            datasets: [{

                label: "Chiffre d'affaires (€)",

                data: salesChartData,

                borderColor: '#6366f1',

                backgroundColor: 'rgba(99, 102, 241, 0.12)',

                fill: true,

                tension: 0.35,

                borderWidth: 3,

                pointRadius: 4,

                pointHoverRadius: 6

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                x: {

                    grid: {
                        display: false
                    },

                    ticks: {
                        color: '#94a3b8'
                    }

                },

                y: {

                    beginAtZero: true,

                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    },

                    ticks: {

                        color: '#94a3b8',

                        callback: function(value) {
                            return value + ' €';
                        }

                    }

                }

            }

        }

    });

}


/* =========================================
   VALEUR DU STOCK PAR CATÉGORIE
========================================= */

const categoryCanvas =
    document.getElementById('categoryChart');

if (categoryCanvas && typeof Chart !== 'undefined') {

    new Chart(categoryCanvas, {

        type: 'bar',

        data: {

            labels: categoryLabels,

            datasets: [{

                label: 'Valeur du stock (€)',

                data: categoryData,

                backgroundColor: 'rgba(99, 102, 241, 0.65)',

                borderColor: '#818cf8',

                borderWidth: 1,

                borderRadius: 8

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                x: {

                    grid: {
                        display: false
                    },

                    ticks: {
                        color: '#94a3b8'
                    }

                },

                y: {

                    beginAtZero: true,

                    grid: {
                        color: 'rgba(255,255,255,0.05)'
                    },

                    ticks: {

                        color: '#94a3b8',

                        callback: function(value) {
                            return value + ' €';
                        }

                    }

                }

            }

        }

    });

}


/* =========================================
   ÉTAT DU STOCK
========================================= */

const stockCanvas =
    document.getElementById('stockStatusChart');

if (stockCanvas && typeof Chart !== 'undefined') {

    new Chart(stockCanvas, {

        type: 'doughnut',

        data: {

            labels: [
                'Stock correct',
                'Stock faible',
                'Rupture'
            ],

            datasets: [{

                data: [

                    <?= (int) $stockStatus['healthy_stock'] ?>,

                    <?= (int) $stockStatus['low_stock'] ?>,

                    <?= (int) $stockStatus['out_of_stock'] ?>

                ],

                backgroundColor: [
                    '#22c55e',
                    '#f59e0b',
                    '#ef4444'
                ],

                borderWidth: 0

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            cutout: '72%',

            plugins: {

                legend: {
                    display: false
                }

            }

        }

    });

}

</script>


<?php require_once 'includes/footer.php'; ?>
