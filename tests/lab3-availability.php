<?php
// Run from the project folder after importing the Lab 3 schema and sample services.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/services.php';
require_once __DIR__ . '/../includes/availability.php';

function expectTimes(array $actual, array $expected, string $case): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("Failed: $case. Got " . json_encode($actual));
    }
}

$db = databaseConnection();
$service = fetchServiceBySlug('manicure-pedicure');
if (!$service || (int) $service['duration_max_minutes'] !== 75) {
    throw new RuntimeException('This check uses the 75-minute sample nail service.');
}
$serviceId = (int) $service['id'];
$monday = new DateTimeImmutable('next Monday midnight', new DateTimeZone('Asia/Ho_Chi_Minh'));
$now = $monday->modify('-1 day');
$db->beginTransaction();
try {
    $db->exec("INSERT INTO staff (name, specialization, biography) VALUES ('Test specialist', 'Nails', 'Test fixture')");
    $staffId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO staff_services (staff_id, service_id) VALUES (?, ?)')->execute([$staffId, $serviceId]);
    $db->prepare("INSERT INTO staff_hours VALUES (?, 1, '09:00', '12:00')")->execute([$staffId]);
    $allTimes = ['09:00', '09:30', '10:00', '10:30'];
    expectTimes(availableStartTimes($serviceId, $staffId, $monday, $now), $allTimes, '75 minutes must fit before closing');
    expectTimes(availableStartTimes($serviceId, $staffId, $monday->modify('+1 day'), $now), [], 'day off');
    expectTimes(availableStartTimes($serviceId, $staffId, $monday, $monday->setTime(9, 30)), ['10:00', '10:30'], 'elapsed starts');

    $db->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)')
        ->execute(['Availability test', bin2hex(random_bytes(12)) . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO appointments (user_id, staff_id, service_id, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $staffId, $serviceId, $monday->format('Y-m-d 10:15:00'), $monday->format('Y-m-d 11:30:00')]);
    $appointmentId = (int) $db->lastInsertId();
    expectTimes(availableStartTimes($serviceId, $staffId, $monday, $now), ['09:00'], 'adjacent and overlapping appointments');
    $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?")->execute([$appointmentId]);
    expectTimes(availableStartTimes($serviceId, $staffId, $monday, $now), $allTimes, 'cancelled appointment releases the time');
} finally {
    $db->rollBack();
}
echo "PASS: shift hours, service duration, elapsed times, conflicts and cancellation. Test records rolled back.\n";
