<?php
require_once __DIR__ . '/../includes/service-management.php';
requireManagementAccess();

$service = null;
$errorTitle = null;
$errorMessage = null;
$formError = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$serviceId = $_GET['id'] ?? null;

if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    $errorTitle = 'Method Not Allowed';
    $errorMessage = 'Please use the service management page to select a service.';
} elseif (!is_string($serviceId) || !validManagementInteger($serviceId, 4294967295)) {
    http_response_code(400);
    $errorTitle = 'Invalid Service Link';
    $errorMessage = 'Choose an existing service from the management page.';
} else {
    try {
        $service = fetchServiceById((int) $serviceId);
        if ($service === null) {
            http_response_code(404);
            $errorTitle = 'Service Not Found';
            $errorMessage = 'This service is no longer available. Return to the service list.';
        } elseif ($method === 'POST') {
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errorTitle = 'Confirmation Expired';
                $errorMessage = 'Reopen the delete confirmation from the service list and try again.';
            } elseif (($_POST['confirm_delete'] ?? null) !== '1') {
                http_response_code(422);
                $formError = 'Select the confirmation checkbox before deleting this service.';
            } elseif (deleteService((int) $serviceId)) {
                setManagementFlash('Service deleted successfully.');
                header('Location: manage_services.php', true, 303);
                exit;
            } else {
                http_response_code(404);
                $errorTitle = 'Service Not Found';
                $errorMessage = 'This service has already been removed. Return to the service list.';
            }
        }
    } catch (PDOException $error) {
        error_log('Spa management delete database error: ' . $error->getMessage());
        if ((int) ($error->errorInfo[1] ?? 0) === 1451) {
            http_response_code(409);
            $errorTitle = 'Service Cannot Be Deleted';
            $errorMessage = 'Other records still use this service. Resolve those references before trying again.';
        } else {
            http_response_code(503);
            $errorTitle = 'Service Temporarily Unavailable';
            $errorMessage = 'We could not complete this request right now. Please try again shortly.';
        }
    }
}

$pageTitle = $errorTitle ?? 'Delete Service';
require __DIR__ . '/../components/management_header.php';
?>
<?php if ($errorTitle !== null): ?>
    <h1><?= escapeHtml($errorTitle) ?></h1>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <a href="manage_services.php" class="btn btn-outline-secondary">Back to Manage Services</a>
<?php else: ?>
    <div class="management-heading">
        <div>
            <h1>Delete Service</h1>
            <p class="mb-0">Review the service before confirming its removal.</p>
        </div>
    </div>
    <section class="management-panel management-confirmation" aria-labelledby="delete-service-name">
        <h2 id="delete-service-name" class="h4"><?= escapeHtml($service['name']) ?></h2>
        <dl class="row mt-3">
            <dt class="col-sm-3">Category</dt>
            <dd class="col-sm-9"><?= escapeHtml($service['category_name']) ?></dd>
            <dt class="col-sm-3">Duration</dt>
            <dd class="col-sm-9"><?= escapeHtml(formatServiceDuration($service)) ?></dd>
            <dt class="col-sm-3">Price</dt>
            <dd class="col-sm-9"><?= escapeHtml(formatServicePrice($service)) ?></dd>
        </dl>
        <div class="alert alert-warning" id="delete-warning">
            Deleting this service removes it from the database and public catalog. This cannot be undone here. Its image file will be kept.
        </div>
        <?php if ($formError !== null): ?>
            <div class="alert alert-danger" id="delete-error" role="alert"><?= escapeHtml($formError) ?></div>
        <?php endif; ?>
        <form method="post" action="delete_service.php?id=<?= escapeHtml($service['id']) ?>" aria-describedby="delete-warning<?= $formError !== null ? ' delete-error' : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="confirm_delete" id="confirm-delete" value="1" required>
                <label class="form-check-label" for="confirm-delete">I confirm that I want to delete <?= escapeHtml($service['name']) ?>.</label>
            </div>
            <div class="management-actions">
                <button class="btn btn-danger" type="submit">Delete Service</button>
                <a class="btn btn-outline-secondary" href="manage_services.php">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
