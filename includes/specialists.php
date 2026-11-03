<?php

require_once __DIR__ . '/services.php';

function fetchSpecialists(): array
{
    $statement = databaseConnection()->query(
        'SELECT st.*, s.id AS service_id, s.name AS service_name, s.slug AS service_slug
         FROM staff AS st
         LEFT JOIN staff_services AS ss ON ss.staff_id = st.id
         LEFT JOIN services AS s ON s.id = ss.service_id
         ORDER BY st.id, s.id'
    );

    return groupSpecialistRows($statement->fetchAll());
}

function fetchSpecialistById(int $id): ?array
{
    $statement = databaseConnection()->prepare(
        'SELECT st.*, s.id AS service_id, s.name AS service_name, s.slug AS service_slug
         FROM staff AS st
         LEFT JOIN staff_services AS ss ON ss.staff_id = st.id
         LEFT JOIN services AS s ON s.id = ss.service_id
         WHERE st.id = :id
         ORDER BY s.id'
    );
    $statement->execute(['id' => $id]);
    $specialists = groupSpecialistRows($statement->fetchAll());

    return $specialists[0] ?? null;
}

function groupSpecialistRows(array $rows): array
{
    // A LEFT JOIN keeps specialists visible even if their services were deleted.
    $specialists = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($specialists[$id])) {
            $specialists[$id] = $row;
            unset($specialists[$id]['service_id'], $specialists[$id]['service_name'], $specialists[$id]['service_slug']);
            $specialists[$id]['services'] = [];
        }
        if ($row['service_id'] !== null) {
            $specialists[$id]['services'][] = [
                'id' => $row['service_id'],
                'name' => $row['service_name'],
                'slug' => $row['service_slug'],
            ];
        }
    }

    return array_values($specialists);
}

function availableSpecialistPhotos(): array
{
    $photos = ['default-portrait.svg'];
    $directory = __DIR__ . '/../public/images/staff';
    if (!is_dir($directory)) {
        return $photos;
    }

    foreach (scandir($directory) ?: [] as $filename) {
        if (strpbrk($filename, "/\\") === false
            && is_file($directory . '/' . $filename)
            && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $photos[] = $filename;
        }
    }

    return $photos;
}

function specialistPhotoUrl(string $photo): string
{
    if (!in_array($photo, availableSpecialistPhotos(), true)) {
        $photo = 'default-portrait.svg';
    }

    return 'images/staff/' . rawurlencode($photo);
}
