<?php

require_once __DIR__ . '/availability.php';

function bookAppointment(int $userId, int $serviceId, int $staffId, DateTimeImmutable $day, string $startTime): void
{
    if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $startTime)) {
        throw new InvalidArgumentException('Choose an available start time.');
    }

    $db = databaseConnection();
    $db->beginTransaction();
    try {
        // Lock the specialist before the first availability read, across all their services.
        $lock = $db->prepare('SELECT id FROM staff WHERE id = ? FOR UPDATE');
        $lock->execute([$staffId]);
        if (!$lock->fetchColumn()) {
            throw new InvalidArgumentException('Choose a specialist who offers this service.');
        }
        $slots = availableSlotEnds($serviceId, $staffId, $day, spaLocalNow());
        if (!isset($slots[$startTime])) {
            throw new DomainException("$startTime is no longer available. Please choose another start time.");
        }
        $insert = $db->prepare(
            'INSERT INTO appointments (user_id, staff_id, service_id, starts_at, ends_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$userId, $staffId, $serviceId, $day->format('Y-m-d') . ' ' . $startTime . ':00', $slots[$startTime]]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function appointmentsForUser(int $userId, DateTimeImmutable $now): array
{
    $statement = databaseConnection()->prepare(
        "SELECT a.id, a.starts_at, a.ends_at, a.status, s.name AS service_name, st.name AS staff_name,
                (a.status = 'booked' AND a.starts_at > ?) AS upcoming
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN staff st ON st.id = a.staff_id
         WHERE a.user_id = ?
         ORDER BY upcoming DESC, CASE WHEN upcoming THEN a.starts_at END ASC, a.starts_at DESC, a.id DESC"
    );
    $statement->execute([$now->format('Y-m-d H:i:s'), $userId]);
    return $statement->fetchAll();
}

function cancelAppointment(int $userId, int $appointmentId): bool
{
    $statement = databaseConnection()->prepare(
        "UPDATE appointments SET status = 'cancelled'
         WHERE id = ? AND user_id = ? AND status = 'booked' AND starts_at > ?"
    );
    $statement->execute([$appointmentId, $userId, spaLocalNow()->format('Y-m-d H:i:s')]);
    return $statement->rowCount() === 1;
}
