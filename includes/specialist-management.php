<?php

require_once __DIR__ . '/specialists.php';
require_once __DIR__ . '/service-management.php';

function validateSpecialistInput(array $input, array $services, array $photos): array
{
    $values = ['name' => '', 'specialization' => '', 'biography' => '', 'photo' => 'default-portrait.svg', 'service_ids' => []];
    $errors = [];

    foreach (['name' => 100, 'specialization' => 100, 'biography' => 1000, 'photo' => 255] as $field => $limit) {
        if (!isset($input[$field]) || (!is_string($input[$field]) && !is_int($input[$field]))) {
            $errors[$field] = 'Enter a single valid value.';
            continue;
        }
        $values[$field] = trim((string) $input[$field]);
        if ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        } elseif (!mb_check_encoding($values[$field], 'UTF-8') || str_contains($values[$field], "\0")) {
            $errors[$field] = 'Enter valid text.';
        } elseif (mb_strlen($values[$field], 'UTF-8') > $limit) {
            $errors[$field] = 'Use no more than ' . $limit . ' characters.';
        }
    }

    if (!isset($errors['photo'])
        && (strpbrk($values['photo'], "/\\") !== false || !in_array($values['photo'], $photos, true))) {
        $errors['photo'] = 'Choose an available specialist photograph.';
    }

    $availableIds = array_map(static fn (array $service): string => (string) $service['id'], $services);
    if (!isset($input['service_ids']) || !is_array($input['service_ids']) || $input['service_ids'] === []) {
        $errors['service_ids'] = 'Choose at least one service.';
    } else {
        foreach ($input['service_ids'] as $submittedId) {
            if ((!is_string($submittedId) && !is_int($submittedId))
                || !validManagementInteger((string) $submittedId, 4294967295)) {
                $errors['service_ids'] = 'Choose only services from the list.';
                continue;
            }
            $id = (string) (int) $submittedId;
            if (!in_array($id, $availableIds, true)) {
                $errors['service_ids'] = 'A selected service is no longer available. Choose services from the list.';
            } elseif (!in_array($id, $values['service_ids'], true)) {
                $values['service_ids'][] = $id;
            }
        }
    }

    return ['values' => $values, 'errors' => $errors];
}

function specialistWriteParameters(array $values): array
{
    return [
        'name' => $values['name'],
        'specialization' => $values['specialization'],
        'biography' => $values['biography'],
        'photo' => $values['photo'],
    ];
}

function insertSpecialistServices(PDO $connection, int $staffId, array $serviceIds): void
{
    $statement = $connection->prepare('INSERT INTO staff_services (staff_id, service_id) VALUES (:staff_id, :service_id)');
    foreach ($serviceIds as $serviceId) {
        $statement->execute(['staff_id' => $staffId, 'service_id' => $serviceId]);
    }
}

function createSpecialist(array $values): int
{
    $connection = databaseConnection();
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            'INSERT INTO staff (name, specialization, biography, photo)
             VALUES (:name, :specialization, :biography, :photo)'
        );
        $statement->execute(specialistWriteParameters($values));
        $id = (int) $connection->lastInsertId();
        insertSpecialistServices($connection, $id, $values['service_ids']);
        $connection->commit();

        return $id;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function updateSpecialist(int $id, array $values): bool
{
    $connection = databaseConnection();
    $connection->beginTransaction();
    try {
        // Lock the profile so an unchanged update still distinguishes a missing row.
        $lookup = $connection->prepare('SELECT id FROM staff WHERE id = :id FOR UPDATE');
        $lookup->execute(['id' => $id]);
        if ($lookup->fetch() === false) {
            $connection->rollBack();
            return false;
        }

        $parameters = specialistWriteParameters($values);
        $parameters['id'] = $id;
        $statement = $connection->prepare(
            'UPDATE staff SET name = :name, specialization = :specialization,
                biography = :biography, photo = :photo WHERE id = :id'
        );
        $statement->execute($parameters);
        $remove = $connection->prepare('DELETE FROM staff_services WHERE staff_id = :id');
        $remove->execute(['id' => $id]);
        insertSpecialistServices($connection, $id, $values['service_ids']);
        $connection->commit();

        return true;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function deleteSpecialist(int $id): bool
{
    // The junction table's foreign key removes assignments; service records stay intact.
    $statement = databaseConnection()->prepare('DELETE FROM staff WHERE id = :id');
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
