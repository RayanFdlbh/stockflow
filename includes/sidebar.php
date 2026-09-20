<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['PHP_SELF'];

?>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <a href="/stockflow/dashboard.php" class="sidebar-logo">

            <div class="logo-icon">
                S
            </div>

            <span>StockFlow</span>

        </a>

        <button
            class="sidebar-close"
            id="sidebarClose"
            type="button"
            aria-label="Fermer le menu"
        >
            <i class="bi bi-x-lg"></i>
        </button>

    </div>


    <nav class="sidebar-nav">

        <p class="sidebar-label">
            ESPACE DE TRAVAIL
        </p>


        <a
            href="/stockflow/dashboard.php"
            class="nav-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
        >

            <i class="bi bi-grid-1x2"></i>

            <span>Dashboard</span>

        </a>


      <a
    href="/stockflow/products/index.php"
    class="nav-item <?= str_contains($currentPath, '/products/')
        ? 'active'
        : ''
    ?>"
>

    <i class="bi bi-box-seam"></i>

    <span>Produits</span>

</a>


        <a
            href="/stockflow/movements/index.php"
            class="nav-item"
        >

            <i class="bi bi-arrow-left-right"></i>

            <span>Mouvements</span>

        </a>


        <a
            href="/stockflow/sales/index.php"
            class="nav-item"
        >

            <i class="bi bi-cart3"></i>

            <span>Ventes</span>

        </a>


        <a
            href="/stockflow/statistics/index.php"
            class="nav-item"
        >

            <i class="bi bi-bar-chart-line"></i>

            <span>Statistiques</span>

        </a>


        <?php if ($_SESSION['role'] === 'admin'): ?>

            <p class="sidebar-label admin-label">
                ADMINISTRATION
            </p>

            <a
                href="/stockflow/admin/users.php"
                class="nav-item"
            >

                <i class="bi bi-people"></i>

                <span>Utilisateurs</span>

            </a>

        <?php endif; ?>

    </nav>


    <div class="sidebar-bottom">

        <a
            href="/stockflow/settings/index.php"
            class="nav-item"
        >

            <i class="bi bi-gear"></i>

            <span>Paramètres</span>

        </a>


        <a
            href="/stockflow/auth/logout.php"
            class="nav-item logout-item"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>Déconnexion</span>

        </a>

    </div>

</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>