<?php

require_once __DIR__ . '/database.php';

function spaLocalNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
}

function availabilitySelection(array $input, DateTimeImmutable $now): array
{
    $idOptions = ['options' => ['min_range' => 1, 'max_range' => 4294967295]];
    $serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT, $idOptions);
    $staffId = filter_var($input['staff_id'] ?? null, FILTER_VALIDATE_INT, $idOptions);
    if (!$serviceId || !$staffId) {
        throw new InvalidArgumentException('Choose a service and a specialist.');
    }
    $date = $input['date'] ?? '';
    if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)) {
        throw new InvalidArgumentException('Choose a valid date.');
    }
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
    if (!$day || $day->format('Y-m-d') !== $date || $date < $now->format('Y-m-d')) {
        throw new InvalidArgumentException('Choose today or a future date.');
    }
    return [$serviceId, $staffId, $day];
}

function specialistsForService(int $serviceId): array
{
    $statement = databaseConnection()->prepare(
        'SELECT st.id, st.name FROM staff st
         JOIN staff_services ss ON ss.staff_id = st.id
         WHERE ss.service_id = ? ORDER BY st.name, st.id'
    );
    $statement->execute([$serviceId]);
    return $statement->fetchAll();
}

function availableStartTimes(int $serviceId, int $staffId, DateTimeImmutable $day, DateTimeImmutable $now): array
{
    return array_keys(availableSlotEnds($serviceId, $staffId, $day, $now));
}

// Each selectable start maps to the end time accounting for the full treatment.
function availableSlotEnds(int $serviceId, int $staffId, DateTimeImmutable $day, DateTimeImmutable $now): array
{
    $db = databaseConnection();
    $shift = $db->prepare(
        'SELECT s.duration_max_minutes, h.opens_at, h.closes_at
         FROM staff_services ss JOIN services s ON s.id = ss.service_id
         LEFT JOIN staff_hours h ON h.staff_id = ss.staff_id AND h.weekday_number = ?
         WHERE ss.service_id = ? AND ss.staff_id = ?'
    );
    $shift->execute([$day->format('N'), $serviceId, $staffId]);
    $hours = $shift->fetch();
    if (!$hours) {
        throw new InvalidArgumentException('Choose a specialist who offers this service.');
    }
    if ($hours['opens_at'] === null) {
        return [];
    }

    $date = $day->format('Y-m-d');
    $opens = new DateTimeImmutable($date . ' ' . $hours['opens_at'], $day->getTimezone());
    $closes = new DateTimeImmutable($date . ' ' . $hours['closes_at'], $day->getTimezone());
    $bookings = $db->prepare(
        "SELECT starts_at, ends_at FROM appointments
         WHERE staff_id = ? AND status = 'booked' AND starts_at < ? AND ends_at > ?"
    );
    $bookings->execute([$staffId, $closes->format('Y-m-d H:i:s'), $opens->format('Y-m-d H:i:s')]);
    $busyPeriods = $bookings->fetchAll();

    // Allow enough time for the longest duration advertised for this treatment.
    $duration = new DateInterval('PT' . $hours['duration_max_minutes'] . 'M');
    $step = new DateInterval('PT30M');
    $times = [];
    for ($start = $opens; $start->add($duration) <= $closes; $start = $start->add($step)) {
        if ($start <= $now) {
            continue;
        }
        $startsAt = $start->format('Y-m-d H:i:s');
        $endsAt = $start->add($duration)->format('Y-m-d H:i:s');
        foreach ($busyPeriods as $busy) {
            // Adjacent appointments may share an end/start time.
            if ($startsAt < $busy['ends_at'] && $endsAt > $busy['starts_at']) {
                continue 2;
            }
        }
        $times[$start->format('H:i')] = $endsAt;
    }
    return $times;
}
