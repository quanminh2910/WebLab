<?php
require_once __DIR__ . '/../includes/specialist-management.php';
requireManagementAccess();

$specialist = null;
$errorMessage = null;
$formError = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id = $_GET['id'] ?? null;

if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    $errorMessage = 'Please select a specialist from the management page.';
} elseif (!is_string($id) || !validManagementInteger($id, 4294967295)) {
    http_response_code(400);
    $errorMessage = 'Invalid specialist link. Please choose an existing specialist.';
} else {
    try {
        $specialist = fetchSpecialistById((int) $id);
        if ($specialist === null) {
            http_response_code(404);
            $errorMessage = 'This specialist no longer exists.';
        } elseif ($method === 'POST') {
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errorMessage = 'Your confirmation could not be verified. Reopen the delete confirmation and try again.';
            } elseif (($_POST['confirm_delete'] ?? null) !== '1') {
                http_response_code(422);
                $formError = 'Select the confirmation checkbox before deleting this specialist.';
            } elseif (deleteSpecialist((int) $id)) {
                setManagementFlash('Specialist deleted successfully.');
                header('Location: manage_specialists.php', true, 303);
                exit;
            } else {
                http_response_code(404);
                $errorMessage = 'This specialist has already been removed.';
            }
        }
    } catch (PDOException $error) {
        error_log('Specialist delete database error: ' . $error->getMessage());
        http_response_code(503);
        $errorMessage = 'We cannot complete this request right now. Please try again shortly.';
    }
}

$pageTitle = 'Delete Specialist';
require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading"><h1>Delete Specialist</h1></div>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <a href="manage_specialists.php" class="btn btn-outline-secondary">Back to Specialist Management</a>
<?php else: ?>
    <section class="management-panel management-confirmation" aria-labelledby="delete-specialist-name">
        <h2 id="delete-specialist-name" class="h4"><?= escapeHtml($specialist['name']) ?></h2>
        <p><?= escapeHtml($specialist['specialization']) ?></p>
        <div class="alert alert-warning" id="delete-warning">
            This permanently removes the specialist profile and their service assignments. The services and photo files will be kept.
        </div>
        <?php if ($formError !== null): ?>
            <div class="alert alert-danger" id="delete-error" role="alert"><?= escapeHtml($formError) ?></div>
        <?php endif; ?>
        <form method="post" action="delete_specialist.php?id=<?= escapeHtml($specialist['id']) ?>" aria-describedby="delete-warning<?= $formError !== null ? ' delete-error' : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="confirm-delete" name="confirm_delete" value="1" required>
                <label class="form-check-label" for="confirm-delete">I confirm that I want to delete <?= escapeHtml($specialist['name']) ?>.</label>
            </div>
            <div class="management-actions">
                <button type="submit" class="btn btn-danger">Delete Specialist</button>
                <a href="manage_specialists.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
