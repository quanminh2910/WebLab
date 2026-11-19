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
    $idOptions = ['options' => ['min_range' => 1, 'max_range' => 4294967295]];
    $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT, $idOptions);
    $staffId = filter_var($_GET['staff_id'] ?? null, FILTER_VALIDATE_INT, $idOptions);
    if (!$serviceId || !$staffId) {
        throw new InvalidArgumentException('Choose a service and a specialist.');
    }
    $now = spaLocalNow();
    $date = $_GET['date'] ?? '';
    if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
        throw new InvalidArgumentException('Choose a valid date.');
    }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
    if (!$day || $day->format('Y-m-d') !== $date || $date < $now->format('Y-m-d')) {
        throw new InvalidArgumentException('Choose today or a future date.');
    }
    echo json_encode(['times' => availableStartTimes($serviceId, $staffId, $day, $now)]);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (PDOException $error) {
    error_log('Availability lookup failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Available times could not be loaded. Please try again.']);
}
