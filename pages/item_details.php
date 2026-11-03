<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/preferences.php';
try {
    startSpaSession();
} catch (RuntimeException $error) {
    error_log('Service page session error: ' . $error->getMessage());
    http_response_code(503);
    exit('The website is temporarily unavailable. Please try again shortly.');
}

$service = null;
$errorTitle = null;
$errorMessage = null;
$serviceKey = $_GET['service'] ?? 'swedish-massage';

if (!is_string($serviceKey) || strlen($serviceKey) > 150
    || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $serviceKey)) {
    http_response_code(400);
    $errorTitle = 'Invalid Service Link';
    $errorMessage = 'Please choose a service from our catalog.';
} else {
    try {
        $service = fetchServiceBySlug($serviceKey);

        if ($service === null) {
            http_response_code(404);
            $errorTitle = 'Service Not Found';
            $errorMessage = 'This service is no longer available. Please explore our other treatments.';
        }
    } catch (PDOException $error) {
        error_log('Spa service details database error: ' . $error->getMessage());
        http_response_code(503);
        $errorTitle = 'Service Temporarily Unavailable';
        $errorMessage = 'We cannot load this service right now. Please try again shortly.';
    }
}

$pageTitle = $service !== null ? $service['name'] : $errorTitle;
?>
<!DOCTYPE html>
<html lang="en"<?= themeAttribute() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escapeHtml($pageTitle) ?> | Spa &amp; Beauty Center</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body>
    <?php require_once __DIR__ . '/../components/header.php'; ?>

    <main class="container py-5">
        <?php if ($service !== null): ?>
            <div class="row align-items-center g-5">
                <div class="col-md-6">
                    <img
                        src="../public/images/<?= escapeHtml(rawurlencode(basename($service['image']))) ?>"
                        class="item-detail-image img-fluid rounded shadow-sm"
                        alt="<?= escapeHtml($service['name']) ?>"
                    >
                </div>

                <div class="col-md-6">
                    <h1><?= escapeHtml($service['name']) ?></h1>
                    <p class="text-muted">
                        Category: <?= escapeHtml($service['category_name']) ?>
                    </p>
                    <p class="text-muted">
                        Duration: <?= escapeHtml(formatServiceDuration($service)) ?>
                    </p>
                    <p class="detail-price h3 mb-3">
                        <?= escapeHtml(formatServicePrice($service)) ?>
                    </p>
                    <p><?= escapeHtml($service['description']) ?></p>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button
                            type="button"
                            class="btn btn-primary"
                            onclick="showMessage()"
                        >
                            Book This Service
                        </button>
                        <a href="../public/index.php#items" class="btn btn-outline-secondary">
                            Back to Services
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <h1><?= escapeHtml($errorTitle) ?></h1>
                <p role="alert"><?= escapeHtml($errorMessage) ?></p>
                <a href="../public/index.php#items" class="btn btn-primary">
                    Back to Services
                </a>
            </div>
        <?php endif; ?>
    </main>

    <?php require_once __DIR__ . '/../components/footer.php'; ?>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
    ></script>
    <script src="../public/js/script.js"></script>
</body>
</html>
