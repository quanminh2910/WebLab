<?php
// Field metadata keeps the add and edit forms consistent.
$fields = [
    'name' => ['Service name', 'text', 150, ''],
    'slug' => ['URL slug', 'text', 150, 'Use lowercase letters, numbers, and hyphens, for example swedish-massage.'],
    'category_id' => ['Category', 'select', null, ''],
    'price' => ['Price (VND)', 'number', 9999999999, 'Enter the whole amount without commas, for example 550000.'],
    'duration_min_minutes' => ['Minimum duration (minutes)', 'number', 65535, 'For a fixed duration, use the same minimum and maximum.'],
    'duration_max_minutes' => ['Maximum duration (minutes)', 'number', 65535, 'Maximum duration must be at least the minimum.'],
    'short_description' => ['Short description', 'textarea', 255, 'Shown on catalog cards. Maximum 255 characters.'],
    'description' => ['Full description', 'textarea', 10000, 'Shown on the details page. Maximum 10,000 characters.'],
    'image' => ['Service photograph', 'select', null, 'Choose an existing photo from the website image library.'],
];
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <p class="mb-2">Please correct the following:</p>
        <ul class="mb-0">
            <?php foreach ($errors as $message): ?>
                <li><?= escapeHtml($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($categories === [] || $images === []): ?>
    <div class="alert alert-warning" role="status">A category and a service photograph must be available before you can save a service.</div>
<?php endif; ?>
<form class="management-panel management-form p-4" method="post">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
    <div class="row g-3">
        <?php foreach ($fields as $field => [$label, $type, $limit, $help]): ?>
            <?php $invalid = isset($errors[$field]); ?>
            <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
                <label for="<?= $field ?>" class="form-label"><?= escapeHtml($label) ?></label>
                <?php if ($type === 'select'): ?>
                    <select id="<?= $field ?>" name="<?= $field ?>"
                            class="form-select<?= $invalid ? ' is-invalid' : '' ?>"
                            aria-invalid="<?= $invalid ? 'true' : 'false' ?>"
                            aria-describedby="<?= $field ?>-help <?= $field ?>-error" required>
                        <option value="">Choose <?= $field === 'image' ? 'a photograph' : 'a category' ?></option>
                        <?php $options = $field === 'image' ? array_combine($images, $images) : array_column($categories, 'name', 'id'); ?>
                        <?php foreach ($options as $optionValue => $optionLabel): ?>
                            <option value="<?= escapeHtml($optionValue) ?>" <?= (string) $optionValue === $values[$field] ? 'selected' : '' ?>><?= escapeHtml($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($type === 'textarea'): ?>
                    <textarea id="<?= $field ?>" name="<?= $field ?>"
                              class="form-control<?= $invalid ? ' is-invalid' : '' ?>"
                              rows="<?= $field === 'description' ? '5' : '2' ?>" maxlength="<?= $limit ?>"
                              aria-invalid="<?= $invalid ? 'true' : 'false' ?>"
                              aria-describedby="<?= $field ?>-help <?= $field ?>-error" required><?= escapeHtml($values[$field]) ?></textarea>
                <?php else: ?>
                    <input type="<?= $type ?>" id="<?= $field ?>" name="<?= $field ?>"
                           class="form-control<?= $invalid ? ' is-invalid' : '' ?>"
                           value="<?= escapeHtml($values[$field]) ?>"
                           <?php if ($type === 'number'): ?>min="1" max="<?= $limit ?>" step="1"<?php else: ?>maxlength="<?= $limit ?>"<?php endif; ?>
                           <?php if ($field === 'slug'): ?>pattern="[a-z0-9]+(-[a-z0-9]+)*"<?php endif; ?>
                           aria-invalid="<?= $invalid ? 'true' : 'false' ?>"
                           aria-describedby="<?= $field ?>-help <?= $field ?>-error" required>
                <?php endif; ?>
                <div id="<?= $field ?>-help" class="form-text"><?= escapeHtml($help) ?></div>
                <div id="<?= $field ?>-error" class="invalid-feedback"><?= escapeHtml($errors[$field] ?? '') ?></div>
            </div>
        <?php endforeach; ?>
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" id="is_featured" name="is_featured" value="1"
                       class="form-check-input<?= isset($errors['is_featured']) ? ' is-invalid' : '' ?>"
                       <?= $values['is_featured'] === '1' ? 'checked' : '' ?>
                       aria-invalid="<?= isset($errors['is_featured']) ? 'true' : 'false' ?>"
                       aria-describedby="is_featured-help is_featured-error">
                <label for="is_featured" class="form-check-label">Feature this service on the homepage</label>
                <div id="is_featured-error" class="invalid-feedback"><?= escapeHtml($errors['is_featured'] ?? '') ?></div>
            </div>
            <p id="is_featured-help" class="form-text">If several services are featured, the earliest added service is displayed.</p>
        </div>
        <div class="col-12 management-actions d-flex flex-wrap gap-2 mt-4">
            <button type="submit" class="btn btn-primary" <?= ($categories === [] || $images === []) ? 'disabled' : '' ?>><?= $isEdit ? 'Save Changes' : 'Create Service' ?></button>
            <a href="manage_services.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
