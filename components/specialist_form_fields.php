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
<?php if ($services === []): ?>
    <div class="alert alert-warning" role="status">Add a service before saving a specialist profile.</div>
<?php endif; ?>
<form class="management-panel management-form" method="post" action="specialist_form.php<?= $isEdit ? '?id=' . escapeHtml($id) : '' ?>">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
    <div class="row g-3">
        <?php foreach (['name' => 'Full name', 'specialization' => 'Position or specialization'] as $field => $label): ?>
            <div class="col-md-6">
                <label for="<?= $field ?>" class="form-label"><?= escapeHtml($label) ?></label>
                <input type="text" id="<?= $field ?>" name="<?= $field ?>" maxlength="100" required
                       class="form-control<?= isset($errors[$field]) ? ' is-invalid' : '' ?>"
                       value="<?= escapeHtml($values[$field]) ?>"
                       aria-invalid="<?= isset($errors[$field]) ? 'true' : 'false' ?>"
                       aria-describedby="<?= $field ?>-error">
                <div id="<?= $field ?>-error" class="invalid-feedback"><?= escapeHtml($errors[$field] ?? '') ?></div>
            </div>
        <?php endforeach; ?>
        <div class="col-12">
            <label for="biography" class="form-label">Short biography</label>
            <textarea id="biography" name="biography" rows="4" maxlength="1000" required
                      class="form-control<?= isset($errors['biography']) ? ' is-invalid' : '' ?>"
                      aria-invalid="<?= isset($errors['biography']) ? 'true' : 'false' ?>"
                      aria-describedby="biography-help biography-error"><?= escapeHtml($values['biography']) ?></textarea>
            <div id="biography-help" class="form-text">Introduce the specialist in a few sentences. Maximum 1,000 characters.</div>
            <div id="biography-error" class="invalid-feedback"><?= escapeHtml($errors['biography'] ?? '') ?></div>
        </div>
        <div class="col-md-6">
            <label for="photo" class="form-label">Profile photo</label>
            <select id="photo" name="photo" required
                    class="form-select<?= isset($errors['photo']) ? ' is-invalid' : '' ?>"
                    aria-invalid="<?= isset($errors['photo']) ? 'true' : 'false' ?>"
                    aria-describedby="photo-help photo-error">
                <?php foreach ($photos as $photo): ?>
                    <option value="<?= escapeHtml($photo) ?>" <?= $values['photo'] === $photo ? 'selected' : '' ?>><?= $photo === 'default-portrait.svg' ? 'Default portrait' : escapeHtml($photo) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="photo-help" class="form-text">Choose a portrait from the image library, or use the default portrait.</div>
            <div id="photo-error" class="invalid-feedback"><?= escapeHtml($errors['photo'] ?? '') ?></div>
        </div>
        <div class="col-12">
            <fieldset aria-describedby="service_ids-help service_ids-error" aria-invalid="<?= isset($errors['service_ids']) ? 'true' : 'false' ?>">
                <legend class="form-label fs-6">Services offered</legend>
                <p id="service_ids-help" class="form-text mt-0">Select at least one service.</p>
                <div class="row g-2">
                    <?php foreach ($services as $service): ?>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input<?= isset($errors['service_ids']) ? ' is-invalid' : '' ?>"
                                       name="service_ids[]" id="service-<?= escapeHtml($service['id']) ?>" value="<?= escapeHtml($service['id']) ?>"
                                       <?= in_array((string) $service['id'], $values['service_ids'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="service-<?= escapeHtml($service['id']) ?>"><?= escapeHtml($service['name']) ?></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="service_ids-error" class="invalid-feedback<?= isset($errors['service_ids']) ? ' d-block' : '' ?>"><?= escapeHtml($errors['service_ids'] ?? '') ?></div>
            </fieldset>
        </div>
        <div class="col-12 management-actions mt-4">
            <button type="submit" class="btn btn-primary" <?= $services === [] ? 'disabled' : '' ?>><?= $isEdit ? 'Save Changes' : 'Create Specialist' ?></button>
            <a href="manage_specialists.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
