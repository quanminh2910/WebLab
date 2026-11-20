<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/preferences.php';
try {
    startSpaSession();
} catch (RuntimeException $error) {
    error_log('Website session error: ' . $error->getMessage());
    http_response_code(503);
    exit('The website is temporarily unavailable. Please try again shortly.');
}

$catalog = [];
$services = [];
$categories = [];
$featuredService = null;
$catalogError = null;

try {
    [$query, $selectedCategory] = serviceSearchCriteria($_GET);
    $catalog = fetchServices();
    $categories = fetchCategories();
    $services = $query === '' && $selectedCategory === 'all' ? $catalog : fetchServices($query, $selectedCategory);

    foreach ($catalog as $service) {
        if ((int) $service['is_featured'] === 1) {
            $featuredService = $service;
            break;
        }
    }
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    $catalogError = $error->getMessage();
} catch (PDOException $error) {
    error_log('Spa catalog database error: ' . $error->getMessage());
    http_response_code(503);
    $services = [];
    $categories = [];
    $catalogError = 'Our services are temporarily unavailable. Please try again shortly.';
}
?>
<!DOCTYPE html>
<html lang="en"<?= themeAttribute() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Massage, Facials &amp; Nail Care | Spa &amp; Beauty Center</title>
    <meta name="description" content="Compare massage, facial, wellness, and nail treatments by price and duration. Choose a specialist and check appointment times at Spa &amp; Beauty Center.">
    <?php if ($catalogError !== null || $query !== '' || $selectedCategory !== 'all'): ?>
        <meta name="robots" content="noindex">
    <?php endif; ?>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php require_once __DIR__ . '/../components/header.php'; ?>

    <main id="main-content" tabindex="-1">
        <section class="hero-section py-5">
            <div class="container text-center">
                <h1>Spa treatments and beauty services</h1>
                <p class="lead">
                    Compare treatments by price and duration, then choose a specialist and time for your visit.
                </p>
                <a href="#items" class="btn btn-primary">
                    Explore Services
                </a>
            </div>
        </section>

        <?php if ($featuredService !== null): ?>
            <section class="featured-service py-5" aria-labelledby="featured-service-title">
                <div class="container">
                    <div class="featured-panel row g-0 align-items-stretch">
                        <div class="col-lg-6">
                            <img
                                src="images/<?= escapeHtml(rawurlencode(basename($featuredService['image']))) ?>"
                                class="featured-image"
                                alt="<?= escapeHtml($featuredService['name']) ?>"
                            >
                        </div>
                        <div class="featured-content col-lg-6 d-flex flex-column justify-content-center">
                            <p class="featured-label">Featured Treatment</p>
                            <h2 id="featured-service-title"><?= escapeHtml($featuredService['name']) ?></h2>
                            <p><?= escapeHtml($featuredService['description']) ?></p>
                            <div class="d-flex flex-wrap gap-4 mb-4">
                                <span><strong>Duration:</strong> <?= escapeHtml(formatServiceDuration($featuredService)) ?></span>
                                <span class="featured-price"><?= escapeHtml(formatServicePrice($featuredService)) ?></span>
                            </div>
                            <a
                                href="../pages/item_details.php?service=<?= escapeHtml(rawurlencode($featuredService['slug'])) ?>"
                                class="btn btn-light align-self-start"
                            >
                                View <?= escapeHtml($featuredService['name']) ?>
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <section id="items" class="py-5" aria-labelledby="services-heading">
            <div class="container">
                <h2 id="services-heading" class="text-center mb-3">Explore Our Services</h2>

                <?php if ($catalogError !== null): ?>
                    <p class="text-center py-4" role="alert"><?= escapeHtml($catalogError) ?></p>
                <?php elseif ($catalog === []): ?>
                    <p class="text-center py-4">No services are available yet. Please check back soon.</p>
                <?php else: ?>
                    <div class="catalog-controls mx-auto mb-4">
                        <form id="service-search" role="search" method="get" action="index.php#items">
                            <label class="form-label fw-semibold" for="service-search-input">Search services</label>
                            <div class="d-flex gap-2">
                                <input
                                    id="service-search-input"
                                    class="form-control"
                                    type="search"
                                    name="q"
                                    value="<?= escapeHtml($query) ?>"
                                    maxlength="150"
                                    placeholder="Try massage or facial"
                                    autocomplete="off"
                                    aria-describedby="search-status"
                                    aria-controls="service-results"
                                >
                                <button class="btn btn-outline-primary" type="submit" name="category" value="<?= escapeHtml($selectedCategory) ?>">Search</button>
                            </div>
                        </form>

                        <div
                            class="category-filters d-flex flex-wrap justify-content-center gap-2 mt-3"
                            role="group"
                            aria-label="Filter services by category"
                        >
                            <button
                                type="submit" form="service-search" name="category" value="all"
                                class="category-filter btn btn-sm btn-outline-secondary<?= $selectedCategory === 'all' ? ' active' : '' ?>"
                                data-category="all"
                                aria-pressed="<?= $selectedCategory === 'all' ? 'true' : 'false' ?>"
                            >
                                All
                            </button>
                            <?php foreach ($categories as $category): ?>
                                <button
                                    type="submit" form="service-search" name="category" value="<?= escapeHtml($category['slug']) ?>"
                                    class="category-filter btn btn-sm btn-outline-secondary<?= $selectedCategory === $category['slug'] ? ' active' : '' ?>"
                                    data-category="<?= escapeHtml($category['slug']) ?>"
                                    aria-pressed="<?= $selectedCategory === $category['slug'] ? 'true' : 'false' ?>"
                                >
                                    <?= escapeHtml($category['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <p id="search-status" class="search-status text-center mt-3 mb-0" aria-live="polite">
                            <?= match (count($services)) {
                                0 => 'No services match your search.',
                                1 => 'Showing 1 service.',
                                default => 'Showing ' . count($services) . ' services.',
                            } ?>
                        </p>
                        <form id="search-recovery" method="get" action="index.php#items" class="text-center"<?= $services ? ' hidden' : '' ?>>
                            <button class="btn btn-outline-secondary mt-2" type="submit">Clear search and filters</button>
                        </form>
                    </div>

                    <p id="favorite-status" class="favorite-status" aria-live="polite" hidden></p>

                    <div id="service-results" class="row g-4" aria-busy="false">
                        <?php require __DIR__ . '/../components/service_cards.php'; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <?php require_once __DIR__ . '/../components/footer.php'; ?>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
    ></script>
    <script src="js/script.js"></script>
</body>
</html>
