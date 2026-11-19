<?php

require_once __DIR__ . '/database.php';

function serviceSearchCriteria(array $input): array
{
    $query = $input['q'] ?? '';
    $category = $input['category'] ?? 'all';
    if (!is_string($query) || !is_string($category)
        || !mb_check_encoding([$query, $category], 'UTF-8')
        || mb_strlen($query, 'UTF-8') > 150 || mb_strlen($category, 'UTF-8') > 100) {
        throw new InvalidArgumentException('Please enter a valid search term and category.');
    }

    $category = trim($category);
    return [trim($query), $category === '' ? 'all' : $category];
}

function fetchServices(string $query = '', string $category = 'all'): array
{
    $sql = 'SELECT s.*, c.name AS category_name, c.slug AS category_slug
            FROM services AS s INNER JOIN categories AS c ON c.id = s.category_id';
    $conditions = [];
    $parameters = [];

    if ($query !== '') {
        // Match literal text (including % and _) against the catalog fields visitors can see.
        $conditions[] = "LOCATE(:query, CONCAT_WS(' ',
            s.name, s.short_description, c.name, CONCAT(FORMAT(s.price, 0), ' VND'), s.price,
            CASE WHEN s.duration_min_minutes = s.duration_max_minutes THEN s.duration_min_minutes
                 ELSE CONCAT(s.duration_min_minutes, '–', s.duration_max_minutes) END, 'min'
        )) > 0";
        $parameters['query'] = $query;
    }
    if ($category !== 'all') {
        $conditions[] = 'c.slug = :category';
        $parameters['category'] = $category;
    }
    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $statement = databaseConnection()->prepare($sql . ' ORDER BY s.id');
    $statement->execute($parameters);

    return $statement->fetchAll();
}

function fetchCategories(): array
{
    $statement = databaseConnection()->query(
        'SELECT id, name, slug, description FROM categories ORDER BY id'
    );

    return $statement->fetchAll();
}

function fetchServiceBySlug(string $slug): ?array
{
    // Bound parameters keep user-supplied slugs separate from SQL instructions.
    $statement = databaseConnection()->prepare(
        'SELECT s.*, c.name AS category_name, c.slug AS category_slug
         FROM services AS s
         INNER JOIN categories AS c ON c.id = s.category_id
         WHERE s.slug = :slug
         LIMIT 1'
    );
    $statement->execute(['slug' => $slug]);
    $service = $statement->fetch();

    return $service === false ? null : $service;
}

function formatServicePrice(array $service): string
{
    return number_format((float) $service['price'], 0, '.', ',') . ' VND';
}

function formatServiceDuration(array $service): string
{
    $minimum = (int) $service['duration_min_minutes'];
    $maximum = (int) $service['duration_max_minutes'];

    return ($minimum === $maximum ? (string) $minimum : $minimum . '–' . $maximum) . ' min';
}

function escapeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
