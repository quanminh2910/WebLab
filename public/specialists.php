<?php
require_once __DIR__ . '/../includes/specialists.php';
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/preferences.php';

$specialists = [];
$errorMessage = null;
try {
    startSpaSession();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        $errorMessage = 'Please open the specialists page using its navigation link.';
    } else {
        $specialists = fetchSpecialists();
    }
} catch (PDOException | RuntimeException $error) {
    error_log('Specialists page error: ' . $error->getMessage());
    http_response_code(503);
    $errorMessage = 'We cannot load our specialists right now. Please try again shortly.';
}
?>
<!DOCTYPE html>
<html lang="en"<?= themeAttribute() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Meet our beauty specialists and explore their massage, facial, wellness, and nail care services.">
    <title>Our Specialists | Spa &amp; Beauty Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/specialists.css">
</head>
<body>
    <?php require __DIR__ . '/../components/header.php'; ?>
    <main class="container py-5 specialists-page">
        <div class="specialists-intro mb-5">
            <h1>Meet Our Specialists</h1>
            <p class="lead mb-0">Get to know the people behind our treatments and find the services they offer.</p>
        </div>

        <?php if ($errorMessage !== null): ?>
            <div class="specialists-notice" role="alert"><?= escapeHtml($errorMessage) ?></div>
        <?php elseif ($specialists === []): ?>
            <div class="specialists-notice" role="status">
                <h2 class="h4">Our team profiles are coming soon</h2>
                <p class="mb-0">Please explore our services or contact us for more information.</p>
            </div>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-4">
                <?php foreach ($specialists as $specialist): ?>
                    <?php $photoUrl = specialistPhotoUrl($specialist['photo']); ?>
                    <div class="col">
                        <article id="specialist-<?= escapeHtml($specialist['id']) ?>" class="card specialist-card h-100" aria-labelledby="specialist-name-<?= escapeHtml($specialist['id']) ?>">
                            <img src="<?= escapeHtml($photoUrl) ?>"
                                 class="specialist-photo" width="480" height="400" loading="lazy"
                                 alt="<?= $photoUrl === 'images/staff/default-portrait.svg' ? 'Default portrait for ' : 'Portrait of ' ?><?= escapeHtml($specialist['name']) ?>">
                            <div class="card-body d-flex flex-column">
                                <h2 id="specialist-name-<?= escapeHtml($specialist['id']) ?>" class="h4 card-title"><?= escapeHtml($specialist['name']) ?></h2>
                                <p class="specialist-specialization"><?= escapeHtml($specialist['specialization']) ?></p>
                                <p class="specialist-biography"><?= escapeHtml($specialist['biography']) ?></p>
                                <div class="mt-auto">
                                    <h3 class="h6">Services offered</h3>
                                    <?php if ($specialist['services'] === []): ?>
                                        <p class="text-muted mb-0">Please contact us for available treatments.</p>
                                    <?php else: ?>
                                        <ul class="specialist-services mb-0">
                                            <?php foreach ($specialist['services'] as $service): ?>
                                                <li><a href="../pages/item_details.php?service=<?= escapeHtml(rawurlencode($service['slug'])) ?>"><?= escapeHtml($service['name']) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="mt-4 mb-0"><a href="index.php#items" class="btn btn-outline-primary">Explore All Services</a></p>
    </main>
    <?php require __DIR__ . '/../components/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/script.js"></script>
</body>
</html>
