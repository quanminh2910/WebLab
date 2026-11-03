<?php
require_once __DIR__ . '/../includes/service-management.php';
requireManagementAccess();

$pageTitle = 'Manage Services';
$services = [];
$errorMessage = null;
$flashMessage = pullManagementFlash();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    $errorMessage = 'Please open the service management page using its navigation link.';
} else {
    try {
        $services = fetchServices();
    } catch (PDOException $error) {
        error_log('Spa management list database error: ' . $error->getMessage());
        http_response_code(503);
        $errorMessage = 'We cannot load services right now. Please try again shortly.';
    }
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading">
    <div>
        <h1>Manage Services</h1>
        <p class="mb-0">Add and update treatments in the public service catalog.</p>
    </div>
    <a href="service_form.php" class="btn btn-primary">Add Service</a>
</div>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
<?php endif; ?>

<?php if ($errorMessage !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
<?php elseif ($services === []): ?>
    <div class="management-panel">
        <h2 class="h4">No services yet</h2>
        <p>Add your first treatment to display it in the catalog.</p>
        <a href="service_form.php" class="btn btn-primary">Add Service</a>
    </div>
<?php else: ?>
    <section class="management-panel" aria-labelledby="service-list-heading">
        <h2 id="service-list-heading" class="h4 mb-3">Service Catalog</h2>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Service catalog table; scroll horizontally on smaller screens">
            <table class="table table-striped management-table">
                <caption class="visually-hidden">Services with category, duration, price, and management actions.</caption>
                <thead>
                    <tr>
                        <th scope="col">Service</th>
                        <th scope="col">Category</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Price</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <th scope="row">
                                <?= escapeHtml($service['name']) ?>
                                <?php if ((int) $service['is_featured'] === 1): ?>
                                    <span class="badge text-bg-secondary">Featured</span>
                                <?php endif; ?>
                            </th>
                            <td><?= escapeHtml($service['category_name']) ?></td>
                            <td><?= escapeHtml(formatServiceDuration($service)) ?></td>
                            <td><?= escapeHtml(formatServicePrice($service)) ?></td>
                            <td>
                                <div class="management-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="../pages/item_details.php?service=<?= escapeHtml(rawurlencode($service['slug'])) ?>" aria-label="View <?= escapeHtml($service['name']) ?>">View</a>
                                    <a class="btn btn-sm btn-outline-primary" href="service_form.php?id=<?= escapeHtml($service['id']) ?>" aria-label="Edit <?= escapeHtml($service['name']) ?>">Edit</a>
                                    <a class="btn btn-sm btn-danger" href="delete_service.php?id=<?= escapeHtml($service['id']) ?>" aria-label="Delete <?= escapeHtml($service['name']) ?>">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<p class="mt-4 mb-0"><a href="index.php#items" class="btn btn-outline-secondary">Back to Public Catalog</a></p>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
