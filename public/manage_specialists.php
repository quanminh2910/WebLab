<?php
require_once __DIR__ . '/../includes/specialist-management.php';
requireManagementAccess();

$pageTitle = 'Manage Specialists';
$specialists = [];
$errorMessage = null;
$flashMessage = pullManagementFlash();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    $errorMessage = 'Please open specialist management using its navigation link.';
} else {
    try {
        $specialists = fetchSpecialists();
    } catch (PDOException $error) {
        error_log('Specialist management list database error: ' . $error->getMessage());
        http_response_code(503);
        $errorMessage = 'We cannot load specialists right now. Please try again shortly.';
    }
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading">
    <div>
        <h1>Manage Specialists</h1>
        <p class="mb-0">Update team profiles and the services each specialist offers.</p>
    </div>
    <a href="specialist_form.php" class="btn btn-primary">Add Specialist</a>
</div>
<?php if ($flashMessage !== null): ?>
    <div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
<?php elseif ($specialists === []): ?>
    <div class="management-panel">
        <h2 class="h4">No specialists yet</h2>
        <p>Add your first specialist to introduce them on the team page.</p>
        <a href="specialist_form.php" class="btn btn-primary">Add Specialist</a>
    </div>
<?php else: ?>
    <section class="management-panel" aria-labelledby="specialist-list-heading">
        <h2 id="specialist-list-heading" class="h4 mb-3">Team Profiles</h2>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Specialists table; scroll horizontally on smaller screens">
            <table class="table table-striped management-table">
                <caption class="visually-hidden">Specialists with specialization, services, and management actions.</caption>
                <thead>
                    <tr>
                        <th scope="col">Specialist</th>
                        <th scope="col">Specialization</th>
                        <th scope="col">Services offered</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($specialists as $specialist): ?>
                        <tr>
                            <th scope="row"><?= escapeHtml($specialist['name']) ?></th>
                            <td><?= escapeHtml($specialist['specialization']) ?></td>
                            <td>
                                <?php if ($specialist['services'] === []): ?>
                                    <span class="text-muted">No services assigned</span>
                                <?php else: ?>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($specialist['services'] as $service): ?>
                                            <li><?= escapeHtml($service['name']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="management-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="specialists.php#specialist-<?= escapeHtml($specialist['id']) ?>" aria-label="View <?= escapeHtml($specialist['name']) ?>">View</a>
                                    <a class="btn btn-sm btn-outline-primary" href="specialist_form.php?id=<?= escapeHtml($specialist['id']) ?>" aria-label="Edit <?= escapeHtml($specialist['name']) ?>">Edit</a>
                                    <a class="btn btn-sm btn-danger" href="delete_specialist.php?id=<?= escapeHtml($specialist['id']) ?>" aria-label="Delete <?= escapeHtml($specialist['name']) ?>">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<p class="mt-4 mb-0"><a href="specialists.php" class="btn btn-outline-secondary">View Public Team Page</a></p>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
