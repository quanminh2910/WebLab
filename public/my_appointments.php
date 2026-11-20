<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../includes/appointments.php';

$pageTitle = 'My Appointments';
$authenticatedUser = null;
$appointments = [];
$errorMessage = null;
$flashMessage = null;

try {
    $authenticatedUser = requireAuthenticatedUser();
    $userId = (int) $authenticatedUser['id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $id = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $errorMessage = 'Your cancellation form expired. Please try again.';
        } elseif (!$id || !cancelAppointment($userId, $id)) {
            http_response_code(409);
            $errorMessage = 'This appointment cannot be cancelled. You can cancel your own appointments before they start.';
        } else {
            setAuthFlash('Your appointment has been cancelled.');
            header('Location: my_appointments.php', true, 303);
            exit;
        }
    } elseif (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        $errorMessage = 'Use the appointment list to manage your bookings.';
    }
    $now = spaLocalNow();
    $token = csrfToken();
    $appointments = appointmentsForUser($userId, $now);
    $flashMessage = pullAuthFlash();
} catch (RuntimeException $error) {
    error_log('Spa appointments error: ' . $error->getMessage());
    http_response_code(503);
    $errorMessage = 'Your appointments are temporarily unavailable. Please try again shortly.';
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading">
    <div>
        <h1>My Appointments</h1>
        <p class="mb-0">Times are shown in Ho Chi Minh City time. You can cancel an appointment before it starts.</p>
    </div>
    <a href="index.php#items" class="btn btn-primary">Explore Services</a>
</div>
<?php if ($flashMessage !== null): ?>
    <p class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></p>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <p class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></p>
<?php endif; ?>
<?php if ($appointments): ?>
    <div class="management-panel table-responsive" role="region" aria-label="Your appointments" tabindex="0">
        <table class="table management-table">
            <caption class="visually-hidden">Your appointments and their booking status</caption>
            <thead><tr>
                <th scope="col">Service</th><th scope="col">Specialist</th><th scope="col">Date and time</th>
                <th scope="col">Status</th><th scope="col">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($appointments as $appointment): ?>
                <?php $start = new DateTimeImmutable($appointment['starts_at'], $now->getTimezone()); ?>
                <tr>
                    <th scope="row"><?= escapeHtml($appointment['service_name']) ?></th>
                    <td><?= escapeHtml($appointment['staff_name']) ?></td>
                    <td><?= $start->format('D, j M Y') ?><br><?= $start->format('H:i') ?>–<?= escapeHtml(substr($appointment['ends_at'], 11, 5)) ?></td>
                    <td><?= $appointment['status'] === 'cancelled' ? 'Cancelled' : 'Booked' ?></td>
                    <td>
                        <?php if ($appointment['upcoming']): ?>
                            <details>
                                <summary>Cancel appointment</summary>
                                <form method="post" action="my_appointments.php" class="mt-2">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                                    <input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Confirm cancellation</button>
                                </form>
                            </details>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($errorMessage === null): ?>
    <p class="management-panel">You have no appointments yet. Choose a service to book your first visit.</p>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
