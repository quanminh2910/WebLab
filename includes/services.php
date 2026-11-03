<?php

require_once __DIR__ . '/database.php';

function fetchServices(): array
{
    $statement = databaseConnection()->query(
        'SELECT s.*, c.name AS category_name, c.slug AS category_slug
         FROM services AS s
         INNER JOIN categories AS c ON c.id = s.category_id
         ORDER BY s.id'
    );

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
