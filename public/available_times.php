<?php
require_once __DIR__ . '/../includes/availability.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo json_encode(['error' => 'Use GET to check availability.']);
    exit;
}

try {
    $now = spaLocalNow();
    [$serviceId, $staffId, $day] = availabilitySelection($_GET, $now);
    echo json_encode(['times' => availableStartTimes($serviceId, $staffId, $day, $now)]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (PDOException $error) {
    error_log('Availability lookup failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Available times could not be loaded. Please try again.']);
}
