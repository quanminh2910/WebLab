<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/services.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Use GET to search the catalog.');
}

try {
    [$query, $category] = serviceSearchCriteria($_GET);
    refreshExistingSpaSession();
    $services = fetchServices($query, $category);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    exit(escapeHtml($error->getMessage()));
} catch (RuntimeException $error) {
    error_log('Service search failed: ' . $error->getMessage());
    http_response_code(503);
    exit('Service search is temporarily unavailable.');
}

require __DIR__ . '/../components/service_cards.php';
