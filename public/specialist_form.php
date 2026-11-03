<?php
require_once __DIR__ . '/../includes/specialist-management.php';
requireManagementAccess();

$isEdit = array_key_exists('id', $_GET);
$id = null;
$pageTitle = $isEdit ? 'Edit Specialist' : 'Add Specialist';
$pageError = null;
$errors = [];
$services = [];
$photos = availableSpecialistPhotos();
$values = ['name' => '', 'specialization' => '', 'biography' => '', 'photo' => 'default-portrait.svg', 'service_ids' => []];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($isEdit) {
    if (!is_string($_GET['id']) || !validManagementInteger($_GET['id'], 4294967295)) {
        http_response_code(400);
        $pageError = 'Invalid specialist link. Please choose a specialist from the management list.';
    } else {
        $id = (int) $_GET['id'];
    }
}
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    $pageError = 'This request method is not supported.';
}

if ($pageError === null) {
    try {
        $services = fetchServices();
        if ($isEdit) {
            $specialist = fetchSpecialistById($id);
            if ($specialist === null) {
                http_response_code(404);
                $pageError = 'This specialist no longer exists.';
            } else {
                foreach (['name', 'specialization', 'biography', 'photo'] as $field) {
                    $values[$field] = (string) $specialist[$field];
                }
                $values['service_ids'] = array_map(static fn (array $service): string => (string) $service['id'], $specialist['services']);
                if (!in_array($values['photo'], $photos, true)) {
                    $values['photo'] = 'default-portrait.svg';
                }
            }
        }

        if ($pageError === null && $method === 'POST') {
            $validation = validateSpecialistInput($_POST, $services, $photos);
            $values = $validation['values'];
            $errors = $validation['errors'];
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errors['csrf_token'] = 'Your form could not be verified. Please try again using the form below.';
            } elseif ($errors !== []) {
                http_response_code(422);
            } else {
                if ($isEdit) {
                    if (!updateSpecialist($id, $values)) {
                        http_response_code(404);
                        $pageError = 'This specialist no longer exists.';
                    } else {
                        setManagementFlash('Specialist updated successfully.');
                    }
                } else {
                    createSpecialist($values);
                    setManagementFlash('Specialist created successfully.');
                }
                if ($pageError === null) {
                    header('Location: manage_specialists.php', true, 303);
                    exit;
                }
            }
        }
    } catch (PDOException $error) {
        error_log('Specialist form database error: ' . $error->getMessage());
        if ($method === 'POST' && (int) ($error->errorInfo[1] ?? 0) === 1452) {
            http_response_code(422);
            $errors['service_ids'] = 'A selected service is no longer available. Please choose from the current services.';
            try {
                $services = fetchServices();
            } catch (PDOException $refreshError) {
                error_log('Specialist form service refresh error: ' . $refreshError->getMessage());
                http_response_code(503);
                $pageError = 'We cannot load the current services right now. Please try again shortly.';
            }
        } else {
            http_response_code(503);
            $pageError = 'We cannot save or load this specialist right now. Please try again shortly.';
        }
    }
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading">
    <h1><?= escapeHtml($pageTitle) ?></h1>
    <a href="manage_specialists.php" class="btn btn-outline-secondary">Back to Specialist Management</a>
</div>
<?php if ($pageError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($pageError) ?></div>
<?php else: ?>
    <?php require __DIR__ . '/../components/specialist_form_fields.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
