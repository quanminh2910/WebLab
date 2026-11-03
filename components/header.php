<?php
require_once __DIR__ . '/../auth/auth.php';
$navigationUser = null;
if (array_key_exists('authenticatedUser', get_defined_vars())) {
    $navigationUser = $authenticatedUser;
} else {
    try {
        $navigationUser = currentUser();
    } catch (PDOException | RuntimeException $error) {
        error_log('Account navigation error: ' . $error->getMessage());
    }
}
$currentPage = basename($_SERVER['SCRIPT_NAME']);
$isHomePage = $currentPage === 'index.php';
$isItemPage = $currentPage === 'item_details.php';
$isManagementPage = in_array($currentPage, ['manage_services.php', 'service_form.php', 'delete_service.php'], true);
$isLocalVisitor = isLocalRequest();
?>
<header>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../public/index.php">
                <img
                    src="../public/images/logo.png"
                    class="site-logo"
                    alt=""
                    width="48"
                    height="48"
                >
                <span>Spa &amp; Beauty Center</span>
            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainNavbar"
                aria-controls="mainNavbar"
                aria-expanded="false"
                aria-label="Toggle navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a
                            class="nav-link<?= $isHomePage ? ' active' : '' ?>"
                            <?= $isHomePage ? 'aria-current="page"' : '' ?>
                            href="../public/index.php"
                        >
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a
                            class="nav-link<?= $isItemPage ? ' active' : '' ?>"
                            <?= $isItemPage ? 'aria-current="page"' : '' ?>
                            href="../public/index.php#items"
                        >
                            Items
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../public/index.php#service-search">
                            Search
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../public/index.php#contact">
                            Contact
                        </a>
                    </li>
                    <?php if ($isLocalVisitor && ($navigationUser['role'] ?? null) === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isManagementPage ? ' active' : '' ?>"
                               <?= $isManagementPage ? 'aria-current="page"' : '' ?>
                               href="../public/manage_services.php">Manage Services</a>
                        </li>
                    <?php endif; ?>
                    <?php if ($navigationUser !== null): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $currentPage === 'dashboard.php' ? ' active' : '' ?>"
                               <?= $currentPage === 'dashboard.php' ? 'aria-current="page"' : '' ?>
                               href="../public/dashboard.php">My Account</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $currentPage === 'login.php' ? ' active' : '' ?>"
                               <?= $currentPage === 'login.php' ? 'aria-current="page"' : '' ?>
                               href="../public/login.php">Log In</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= $currentPage === 'register.php' ? ' active' : '' ?>"
                               <?= $currentPage === 'register.php' ? 'aria-current="page"' : '' ?>
                               href="../public/register.php">Register</a>
                        </li>
                    <?php endif; ?>
                </ul>

                <button
                    id="theme-toggle"
                    class="theme-toggle btn btn-sm ms-lg-3 mt-3 mt-lg-0"
                    type="button"
                    aria-pressed="false"
                    aria-label="Switch to dark mode"
                >
                    <span id="theme-icon" aria-hidden="true">&#9790;</span>
                    <span id="theme-label">Dark mode</span>
                </button>
            </div>
        </div>
    </nav>
</header>
