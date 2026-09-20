
<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

$userId = (int) $_SESSION['user_id'];

function statsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function statsPrice($value): string
{
    return number_format(
        (float) $value,
        2,
        ',',
        ' '
    ) . ' €';
}

/* =========================================
   STATISTIQUES DES VENTES
========================================= */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_sales,
        COALESCE(SUM(quantity), 0) AS units_sold,
        COALESCE(SUM(quantity * sale_price), 0) AS revenue

    FROM sales

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$salesStats = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   STATISTIQUES DU STOCK
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
                CASE
                    WHEN quantity <= low_stock_threshold
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS low_stock

    FROM products

    WHERE user_id = :user_id
");

$stmt->execute([
    'user_id' => $userId
]);

$stockStats = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================================
   VENTES DES 12 DERNIERS MOIS
========================================= */

$timezone = new DateTimeZone('Europe/Paris');

$now = new DateTimeImmutable('now', $timezone);

$firstMonth = $now
    ->modify('first day of this month')
    ->setTime(0, 0)
    ->modify('-11 months');

$nextMonth = $now
    ->modify('first day of next month')
    ->setTime(0, 0);

$stmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS sale_month,

        COALESCE(
            SUM(quantity * sale_price),
            0
        ) AS revenue,

        COUNT(*) AS sales_count

    FROM sales

    WHERE user_id = :user_id
      AND created_at >= :start_date
      AND created_at < :end_date

    GROUP BY DATE_FORMAT(created_at, '%Y-%m')

    ORDER BY sale_month ASC
");

$stmt->execute([
    'user_id' => $userId,
    'start_date' => $firstMonth->format('Y-m-d H:i:s'),
    'end_date' => $nextMonth->format('Y-m-d H:i:s')
]);

$monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyMap = [];

foreach ($monthlyRows as $row) {
    $monthlyMap[$row['sale_month']] = $row;
}

$monthLabels = [];
$monthRevenues = [];
$monthSales = [];

$monthNames = [
    1 => 'Janv.',
    2 => 'Févr.',
    3 => 'Mars',
    4 => 'Avr.',
    5 => 'Mai',
    6 => 'Juin',
    7 => 'Juil.',
    8 => 'Août',
    9 => 'Sept.',
    10 => 'Oct.',
    11 => 'Nov.',
    12 => 'Déc.'
];

for ($i = 0; $i < 12; $i++) {

    $month = $firstMonth->modify("+{$i} months");

    $key = $month->format('Y-m');

    $monthLabels[] =
        $monthNames[(int) $month->format('n')]
        . ' '
        . $month->format('y');

    $monthRevenues[] = round(
        (float) ($monthlyMap[$key]['revenue'] ?? 0),
        2
    );

    $monthSales[] = (int) (
        $monthlyMap[$key]['sales_count'] ?? 0
    );
}

/* =========================================
   PRODUITS LES PLUS VENDUS
========================================= */

$stmt = $pdo->prepare("
    SELECT
        p.name,

        SUM(s.quantity) AS units_sold,

        SUM(
            s.quantity * s.sale_price
        ) AS revenue

    FROM sales s

    INNER JOIN products p
        ON p.id = s.product_id
       AND p.user_id = s.user_id

    WHERE s.user_id = :user_id

    GROUP BY p.id, p.name

    ORDER BY
        units_sold DESC,
        revenue DESC

    LIMIT 5
");

$stmt->execute([
    'user_id' => $userId
]);

$topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   STOCK PAR CATÉGORIE
========================================= */

$stmt = $pdo->prepare("
    SELECT
        category,

        COUNT(*) AS product_count,

        COALESCE(
            SUM(quantity * purchase_price),
            0
        ) AS stock_value

    FROM products

    WHERE user_id = :user_id

    GROUP BY category

    ORDER BY stock_value DESC
");

$stmt->execute([
    'user_id' => $userId
]);

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [];
$categoryValues = [];

foreach ($categories as $category) {

    $categoryLabels[] = $category['category'];

    $categoryValues[] = round(
        (float) $category['stock_value'],
        2
    );
}

/* =========================================
   AFFICHAGE
========================================= */

$pageTitle = 'Statistiques';

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
                <h1>Statistiques</h1>
                <p>Analysez les performances de votre activité.</p>
            </div>

        </div>

    </header>

    <main class="main-content">

        <div class="page-heading">

            <div>
                <h2>Vue analytique</h2>
                <p>Vos ventes et votre inventaire en un coup d'œil.</p>
            </div>

            <a href="../dashboard.php" class="secondary-action">
                <i class="bi bi-arrow-left"></i>
                Dashboard
            </a>

        </div>

        <!-- INDICATEURS -->

        <section class="stats-grid">

            <article class="stat-card">

                <div class="stat-card-top">
                    <div class="stat-icon value-icon">
                        <i class="bi bi-currency-euro"></i>
                    </div>
                    <span class="stat-label">Chiffre d'affaires</span>
                </div>

                <strong class="stat-value">
                    <?= statsPrice($salesStats['revenue']) ?>
                </strong>

                <span class="stat-description">
                    Total des ventes enregistrées
                </span>

            </article>

            <article class="stat-card">

                <div class="stat-card-top">
                    <div class="stat-icon products-icon">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <span class="stat-label">Ventes</span>
                </div>

                <strong class="stat-value">
                    <?= (int) $salesStats['total_sales'] ?>
                </strong>

                <span class="stat-description">
                    Transactions enregistrées
                </span>

            </article>

            <article class="stat-card">

                <div class="stat-card-top">
                    <div class="stat-icon profit-icon">
                        <i class="bi bi-bag-check"></i>
                    </div>
                    <span class="stat-label">Unités vendues</span>
                </div>

                <strong class="stat-value">
                    <?= (int) $salesStats['units_sold'] ?>
                </strong>

                <span class="stat-description">
                    Quantité totale écoulée
                </span>

            </article>

            <article class="stat-card">

                <div class="stat-card-top">
                    <div class="stat-icon alert-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <span class="stat-label">Stock faible</span>
                </div>

                <strong class="stat-value">
                    <?= (int) $stockStats['low_stock'] ?>
                </strong>

                <span class="stat-description">
                    Produits à surveiller
                </span>

            </article>

        </section>

        <!-- GRAPHIQUES -->

        <section class="dashboard-grid">

            <article class="dashboard-card chart-card-large">

                <div class="card-heading">

                    <div>
                        <h3>Évolution du chiffre d'affaires</h3>
                        <p>Les 12 derniers mois.</p>
                    </div>

                    <i class="bi bi-graph-up card-heading-icon"></i>

                </div>

                <div class="chart-container">
                    <canvas id="monthlyRevenueChart"></canvas>
                </div>

            </article>

            <article class="dashboard-card">

                <div class="card-heading">

                    <div>
                        <h3>Stock par catégorie</h3>
                        <p>Valeur au prix d'achat.</p>
                    </div>

                </div>

                <div class="chart-container">

                    <?php if (!empty($categories)): ?>

                        <canvas id="categoryStatsChart"></canvas>

                    <?php else: ?>

                        <div class="empty-state compact">

                            <i class="bi bi-pie-chart"></i>

                            <strong>Aucun produit</strong>

                            <span>
                                Les catégories apparaîtront ici.
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </article>

        </section>

        <!-- TOP PRODUITS -->

        <section class="dashboard-card stats-top-card">

            <div class="card-heading">

                <div>
                    <h3>Top 5 des produits vendus</h3>
                    <p>Classement selon le nombre d'unités vendues.</p>
                </div>

                <a href="../sales/index.php">Toutes les ventes</a>

            </div>

            <?php if (!empty($topProducts)): ?>

                <div class="stats-table-wrapper">

                    <table class="stats-table">

                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th>Unités vendues</th>
                                <th>Chiffre d'affaires</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($topProducts as $product): ?>

                                <tr>

                                    <td>
                                        <strong>
                                            <?= statsEscape($product['name']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= (int) $product['units_sold'] ?>
                                    </td>

                                    <td>
                                        <?= statsPrice($product['revenue']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state compact">

                    <i class="bi bi-bar-chart"></i>

                    <strong>Aucune vente</strong>

                    <span>
                        Les produits les plus vendus apparaîtront ici.
                    </span>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<style>

.stats-top-card {
    margin-top: 28px;
}

.stats-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.stats-table {
    width: 100%;
    min-width: 550px;
    border-collapse: collapse;
}

.stats-table th,
.stats-table td {
    padding: 16px 12px;
    text-align: left;
    border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.stats-table th {
    font-size: 12px;
    opacity: 0.7;
}

.stats-table td {
    font-size: 14px;
}

.stats-table tbody tr:last-child td {
    border-bottom: none;
}

</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js"></script>

<script>

const monthLabels = <?= json_encode(
    $monthLabels,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;

const monthRevenues = <?= json_encode($monthRevenues) ?>;

const categoryLabels = <?= json_encode(
    $categoryLabels,
    JSON_UNESCAPED_UNICODE
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?>;

const categoryValues = <?= json_encode($categoryValues) ?>;

if (typeof Chart !== 'undefined') {

    const revenueCanvas =
        document.getElementById('monthlyRevenueChart');

    if (revenueCanvas) {

        new Chart(revenueCanvas, {

            type: 'line',

            data: {

                labels: monthLabels,

                datasets: [{

                    label: "Chiffre d'affaires (€)",

                    data: monthRevenues,

                    borderColor: '#6366f1',

                    backgroundColor: 'rgba(99, 102, 241, 0.12)',

                    fill: true,

                    tension: 0.35,

                    borderWidth: 3

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

                    y: {
                        beginAtZero: true
                    }

                }

            }

        });

    }

    const categoryCanvas =
        document.getElementById('categoryStatsChart');

    if (categoryCanvas) {

        new Chart(categoryCanvas, {

            type: 'doughnut',

            data: {

                labels: categoryLabels,

                datasets: [{

                    data: categoryValues,

                    backgroundColor: [
                        '#6366f1',
                        '#22c55e',
                        '#f59e0b',
                        '#ef4444',
                        '#06b6d4',
                        '#a855f7',
                        '#64748b'
                    ],

                    borderWidth: 0

                }]

            },

            options: {

                responsive: true,
                maintainAspectRatio: false,

                plugins: {

                    legend: {
                        position: 'bottom'
                    }

                }

            }

        });

    }

}

</script>

<?php require_once '../includes/footer.php'; ?>
