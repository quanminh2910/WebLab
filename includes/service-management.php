<?php

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/../auth/auth.php';

function requireManagementAccess(): void
{
    // Keep the local-development restriction as well as administrator authentication.
    if (PHP_SAPI !== 'cli' && !isLocalRequest()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Management is available only on this computer during local development.');
    }

    try {
        requireAdministrator();
    } catch (PDOException | RuntimeException $error) {
        error_log('Management access error: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Management is temporarily unavailable. Please try again shortly.');
    }
}

function setManagementFlash(string $message): void
{
    $_SESSION['management_flash'] = $message;
}

function pullManagementFlash(): ?string
{
    $message = $_SESSION['management_flash'] ?? null;
    unset($_SESSION['management_flash']);

    return is_string($message) ? $message : null;
}

function availableServiceImages(): array
{
    $directory = __DIR__ . '/../public/images';
    $images = [];

    foreach (scandir($directory) ?: [] as $filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (is_file($directory . '/' . $filename)
            && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
            && strcasecmp($filename, 'logo.png') !== 0) {
            $images[] = $filename;
        }
    }

    return $images;
}

function validateServiceInput(array $input, array $categories, array $images): array
{
    $values = [
        'name' => '',
        'slug' => '',
        'category_id' => '',
        'short_description' => '',
        'description' => '',
        'price' => '',
        'duration_min_minutes' => '',
        'duration_max_minutes' => '',
        'image' => '',
        'is_featured' => '0',
    ];
    $errors = [];

    foreach ($values as $field => $default) {
        if (!array_key_exists($field, $input)) {
            if ($field !== 'is_featured') {
                $errors[$field] = 'This field is required.';
            }
            continue;
        }

        // Browser form fields are strings. Integer values are also safe to preserve.
        if (!is_string($input[$field]) && !is_int($input[$field])) {
            $errors[$field] = 'Enter a single valid value.';
            continue;
        }

        $values[$field] = trim((string) $input[$field]);
        if (!mb_check_encoding($values[$field], 'UTF-8')) {
            $errors[$field] = 'Enter valid text.';
        } elseif ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    $limits = ['name' => 150, 'slug' => 150, 'short_description' => 255, 'description' => 10000];
    foreach ($limits as $field => $limit) {
        if (!isset($errors[$field]) && mb_strlen($values[$field], 'UTF-8') > $limit) {
            $errors[$field] = 'Use no more than ' . $limit . ' characters.';
        }
    }

    if (!isset($errors['slug'])
        && !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $values['slug'])) {
        $errors['slug'] = 'Use lowercase letters, numbers, and single hyphens between words.';
    }

    $categoryIds = array_map(static fn (array $category): string => (string) $category['id'], $categories);
    if (!isset($errors['category_id']) && !in_array($values['category_id'], $categoryIds, true)) {
        $errors['category_id'] = 'Choose an existing category.';
    }

    if (!isset($errors['image'])
        && ($values['image'] !== basename($values['image']) || !in_array($values['image'], $images, true))) {
        $errors['image'] = 'Choose one of the available service images.';
    }

    $integerLimits = [
        'price' => 9999999999,
        'duration_min_minutes' => 65535,
        'duration_max_minutes' => 65535,
    ];
    foreach ($integerLimits as $field => $maximum) {
        if (!isset($errors[$field]) && !validManagementInteger($values[$field], $maximum)) {
            $errors[$field] = 'Enter a whole number from 1 to ' . number_format($maximum) . '.';
        }
    }

    if (!isset($errors['duration_min_minutes']) && !isset($errors['duration_max_minutes'])
        && (int) $values['duration_max_minutes'] < (int) $values['duration_min_minutes']) {
        $errors['duration_max_minutes'] = 'Maximum duration must be at least the minimum duration.';
    }

    if (!isset($errors['is_featured']) && !in_array($values['is_featured'], ['0', '1'], true)) {
        $errors['is_featured'] = 'Choose a valid featured setting.';
    }

    return ['values' => $values, 'errors' => $errors];
}

function validManagementInteger(string $value, int $maximum): bool
{
    if (!preg_match('/\A[0-9]+\z/', $value)) {
        return false;
    }

    // Compare digit strings before casting so very large submissions cannot overflow.
    $digits = ltrim($value, '0');
    $limit = (string) $maximum;

    return $digits !== '' && (strlen($digits) < strlen($limit)
        || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0));
}

function fetchServiceById(int $id): ?array
{
    $statement = databaseConnection()->prepare(
        'SELECT s.*, c.name AS category_name, c.slug AS category_slug
         FROM services AS s
         INNER JOIN categories AS c ON c.id = s.category_id
         WHERE s.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $service = $statement->fetch();

    return $service === false ? null : $service;
}

function serviceSlugExists(string $slug, ?int $exceptId = null): bool
{
    $sql = 'SELECT id FROM services WHERE slug = :slug';
    $parameters = ['slug' => $slug];
    if ($exceptId !== null) {
        $sql .= ' AND id <> :except_id';
        $parameters['except_id'] = $exceptId;
    }
    $statement = databaseConnection()->prepare($sql . ' LIMIT 1');
    $statement->execute($parameters);

    return $statement->fetch() !== false;
}

function serviceWriteParameters(array $values): array
{
    // Only these fixed columns can be written, regardless of submitted field names.
    return [
        'name' => $values['name'],
        'slug' => $values['slug'],
        'category_id' => $values['category_id'],
        'short_description' => $values['short_description'],
        'description' => $values['description'],
        'price' => $values['price'],
        'duration_min_minutes' => $values['duration_min_minutes'],
        'duration_max_minutes' => $values['duration_max_minutes'],
        'image' => $values['image'],
        'is_featured' => $values['is_featured'],
    ];
}

function createService(array $values): int
{
    $statement = databaseConnection()->prepare(
        'INSERT INTO services
            (name, slug, category_id, short_description, description, price,
             duration_min_minutes, duration_max_minutes, image, is_featured)
         VALUES
            (:name, :slug, :category_id, :short_description, :description, :price,
             :duration_min_minutes, :duration_max_minutes, :image, :is_featured)'
    );
    $statement->execute(serviceWriteParameters($values));

    return (int) databaseConnection()->lastInsertId();
}

function updateService(int $id, array $values): bool
{
    $parameters = serviceWriteParameters($values);
    $parameters['id'] = $id;
    $statement = databaseConnection()->prepare(
        'UPDATE services SET
            name = :name, slug = :slug, category_id = :category_id,
            short_description = :short_description, description = :description, price = :price,
            duration_min_minutes = :duration_min_minutes, duration_max_minutes = :duration_max_minutes,
            image = :image, is_featured = :is_featured
         WHERE id = :id'
    );
    $statement->execute($parameters);

    // An unchanged record is still a successful update, not a missing service.
    return $statement->rowCount() > 0 || fetchServiceById($id) !== null;
}

function deleteService(int $id): bool
{
    $statement = databaseConnection()->prepare('DELETE FROM services WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
