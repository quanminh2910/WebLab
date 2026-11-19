<?php
// Run with PHP CLI against a local Lab 3 database containing the sample services.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/appointments.php';
require_once __DIR__ . '/../includes/services.php';

if (($argv[1] ?? '') === 'worker') {
    [, , $user, $service, $staff, $date, $time] = $argv;
    $day = new DateTimeImmutable($date, spaLocalNow()->getTimezone());
    databaseConnection();
    echo "ready\n";
    fflush(STDOUT);
    try {
        bookAppointment((int) $user, (int) $service, (int) $staff, $day, $time);
        echo "booked\n";
    } catch (DomainException $error) {
        echo "conflict\n";
    }
    exit;
}

function checkBooking(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = databaseConnection();
$monday = spaLocalNow()->modify('next Monday midnight');
$date = $monday->format('Y-m-d');
$massage = (int) (fetchServiceBySlug('swedish-massage')['id'] ?? 0);
$nails = (int) (fetchServiceBySlug('manicure-pedicure')['id'] ?? 0);
checkBooking($massage && $nails, 'Import the sample services before running this check.');
$users = [];
$staff = null;
$workers = [];
try {
    $db->beginTransaction();
    $db->exec("INSERT INTO staff (name, specialization, biography) VALUES ('Booking test', 'Test', 'Test fixture')");
    $staff = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO staff_services (staff_id, service_id) VALUES (?, ?), (?, ?)')
        ->execute([$staff, $massage, $staff, $nails]);
    $db->prepare("INSERT INTO staff_hours (staff_id, weekday_number, opens_at, closes_at) VALUES (?, 1, '09:00', '13:00')")
        ->execute([$staff]);
    for ($i = 0; $i < 2; $i++) {
        $db->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)')
            ->execute(['Booking test', bin2hex(random_bytes(12)) . '@example.test', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $users[] = (int) $db->lastInsertId();
    }
    $db->commit();

    // Hold the specialist until both independent database connections are ready to book.
    $db->beginTransaction();
    $db->prepare('SELECT id FROM staff WHERE id = ? FOR UPDATE')->execute([$staff]);
    foreach ([[$users[0], $massage, '10:00'], [$users[1], $nails, '10:30']] as [$user, $service, $time]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $user, $service, $staff, $date, $time],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        checkBooking(is_resource($process), 'Could not start the concurrent booking worker.');
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [, $pipes]) {
        checkBooking(trim((string) fgets($pipes[1])) === 'ready', 'Booking worker failed before the race.');
    }
    $db->commit();
    $results = [];
    foreach ($workers as [, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        checkBooking(stream_get_contents($pipes[2]) === '', 'Booking worker reported an unexpected error.');
    }
    sort($results);
    checkBooking($results === ['booked', 'conflict'], 'Overlapping requests must yield one booking and one conflict.');
    $rows = $db->prepare('SELECT * FROM appointments WHERE staff_id = ?');
    $rows->execute([$staff]);
    $bookings = $rows->fetchAll();
    checkBooking(count($bookings) === 1, 'Exactly one appointment should be stored.');
    $booking = $bookings[0];
    $owner = (int) $booking['user_id'];
    $other = $owner === $users[0] ? $users[1] : $users[0];
    $expectedEnd = (int) $booking['service_id'] === $massage ? '11:00:00' : '11:45:00';
    checkBooking($booking['ends_at'] === "$date $expectedEnd", 'The end must include the full service duration.');
    checkBooking(count(appointmentsForUser($owner, spaLocalNow())) === 1, 'Owner should see the booking.');
    checkBooking(!appointmentsForUser($other, spaLocalNow()), 'Other customer should have no bookings.');
    checkBooking(!cancelAppointment($other, (int) $booking['id']), 'Other customer must not cancel the booking.');
    try {
        bookAppointment($owner, (int) $booking['service_id'], $staff, $monday, substr($booking['starts_at'], 11, 5));
        throw new RuntimeException('A duplicate submission was accepted.');
    } catch (DomainException $expected) {
        // The existing appointment still occupies this interval.
    }
    checkBooking(cancelAppointment($owner, (int) $booking['id']), 'Owner should be able to cancel.');
    checkBooking(!cancelAppointment($owner, (int) $booking['id']), 'A cancelled visit cannot be cancelled a second time.');
    bookAppointment($other, $nails, $staff, $monday, '10:30');
    checkBooking(count(appointmentsForUser($other, spaLocalNow())) === 1, 'The released time should be bookable.');
    bookAppointment($owner, $massage, $staff, $monday, '09:30');
    checkBooking(count(appointmentsForUser($owner, spaLocalNow())) === 2, 'A visit may end exactly when the next begins.');

    $past = $monday->modify('-2 weeks')->format('Y-m-d');
    $db->prepare('INSERT INTO appointments (user_id, staff_id, service_id, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$owner, $staff, $massage, "$past 10:00:00", "$past 11:00:00"]);
    checkBooking(!cancelAppointment($owner, (int) $db->lastInsertId()), 'Past appointments cannot be cancelled.');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
    if ($staff !== null) {
        $db->prepare('DELETE FROM appointments WHERE staff_id = ?')->execute([$staff]);
        $db->prepare('DELETE FROM staff WHERE id = ?')->execute([$staff]);
    }
    foreach ($users as $user) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user]);
    }
}
echo "PASS: concurrent overlaps, adjacent visits, duplicate submission, duration, private history, cancellation and rebooking.\n";
