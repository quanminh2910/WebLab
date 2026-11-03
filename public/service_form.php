<?php
require_once __DIR__ . '/../includes/service-management.php';
requireManagementAccess();

$isEdit = array_key_exists('id', $_GET);
$id = null;
$pageTitle = $isEdit ? 'Edit Service' : 'Add Service';
$pageError = null;
$errors = [];
$categories = [];
$images = availableServiceImages();
$values = array_fill_keys([
    'name', 'slug', 'category_id', 'short_description', 'description', 'price',
    'duration_min_minutes', 'duration_max_minutes', 'image',
], '');
$values['is_featured'] = '0';

if ($isEdit) {
    $id = is_string($_GET['id'])
        ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
        : false;
    if ($id === false) {
        http_response_code(400);
        $pageError = 'Invalid service link. Please choose a service from the management list.';
    }
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    $pageError = 'This request method is not supported.';
}

// Load and process the form before rendering any HTML.
if ($pageError === null) {
    try {
        $categories = fetchCategories();
        if ($isEdit) {
            $service = fetchServiceById($id);
            if ($service === null) {
                http_response_code(404);
                $pageError = 'This service no longer exists.';
            } else {
                foreach ($values as $field => $value) {
                    $values[$field] = (string) $service[$field];
                }
            }
        }

        if ($pageError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $validation = validateServiceInput($_POST, $categories, $images);
            $values = $validation['values'];
            $errors = $validation['errors'];
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errors['csrf_token'] = 'Your form could not be verified. Please try again using the form below.';
            } else {
                if ($errors === [] && serviceSlugExists($values['slug'], $isEdit ? $id : null)) {
                    $errors['slug'] = 'This URL slug is already used by another service.';
                }
                if ($errors !== []) {
                    http_response_code(422);
                } else {
                    if ($isEdit) {
                        if (!updateService($id, $values)) {
                            http_response_code(404);
                            $pageError = 'This service no longer exists.';
                        } else {
                            setManagementFlash('Service updated successfully.');
                        }
                    } else {
                        createService($values);
                        setManagementFlash('Service created successfully.');
                    }
                    if ($pageError === null) {
                        header('Location: manage_services.php', true, 303);
                        exit;
                    }
                }
            }
        }
    } catch (PDOException $error) {
        error_log('Service form database error: ' . $error->getMessage());
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
            http_response_code(422);
            $errors['slug'] = 'This URL slug is already used by another service.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (int) ($error->errorInfo[1] ?? 0) === 1452) {
            http_response_code(422);
            $errors['category_id'] = 'This category is no longer available. Please choose another category.';
        } else {
            http_response_code(503);
            $pageError = 'We cannot save or load this service right now. Please try again shortly.';
        }
    }
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <h1><?= escapeHtml($pageTitle) ?></h1>
    <a href="manage_services.php" class="btn btn-outline-secondary">Back to Service Management</a>
</div>
<?php if ($pageError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($pageError) ?></div>
<?php else: ?>
    <?php require __DIR__ . '/../components/service_form_fields.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
