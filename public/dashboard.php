<?php
require_once __DIR__ . '/../auth/auth.php';
header('Cache-Control: no-store');
$pageTitle = 'My Dashboard';
$isAuthPage = true;
$authenticatedUser = null;
$errorMessage = null;
$flashMessage = null;
$token = null;

try {
    startSpaSession();
    $authenticatedUser = requireAuthenticatedUser();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        $errorMessage = 'Please open your dashboard using its navigation link.';
    } else {
        $token = csrfToken();
        $flashMessage = pullAuthFlash();
    }
} catch (PDOException $error) {
    error_log('Spa dashboard database error: ' . $error->getMessage());
    http_response_code(503);
    $errorMessage = 'Your dashboard is temporarily unavailable. Please try again shortly.';
} catch (RuntimeException $error) {
    error_log('Spa dashboard error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'We could not load your dashboard. Please try again shortly.';
}

require __DIR__ . '/../components/management_header.php';
?>
<div class="management-heading">
    <div>
        <h1>My Dashboard</h1>
        <?php if ($authenticatedUser !== null): ?><p class="mb-0">Welcome, <?= escapeHtml($authenticatedUser['full_name']) ?>.</p><?php endif; ?>
    </div>
</div>
<?php if ($flashMessage !== null): ?><div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div><?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <a href="dashboard.php" class="btn btn-outline-secondary">Try Again</a>
<?php elseif ($authenticatedUser !== null): ?>
    <section class="management-panel auth-dashboard" aria-labelledby="account-heading">
        <h2 id="account-heading" class="h4 mb-3">Your Account</h2>
        <dl class="row">
            <dt class="col-sm-3">Full name</dt>
            <dd class="col-sm-9"><?= escapeHtml($authenticatedUser['full_name']) ?></dd>
            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= escapeHtml($authenticatedUser['email']) ?></dd>
            <dt class="col-sm-3">Account role</dt>
            <dd class="col-sm-9"><?= $authenticatedUser['role'] === 'admin' ? 'Administrator' : 'Customer' ?></dd>
        </dl>
        <h3 class="h5">Your Permissions</h3>
        <?php if ($authenticatedUser['role'] === 'admin'): ?>
            <p>You can view your account and add, edit, or delete services and specialist profiles.</p>
            <?php if (!isLocalRequest()): ?>
                <p>Management is available when you open this website on the server computer.</p>
            <?php endif; ?>
        <?php else: ?>
            <p>You can explore services, meet our specialists, and view your account. Management is reserved for administrators.</p>
        <?php endif; ?>
        <div class="management-actions mt-4">
            <a href="index.php#items" class="btn btn-primary">Explore Services</a>
            <a href="specialists.php" class="btn btn-outline-primary">Meet Our Specialists</a>
            <?php if ($authenticatedUser['role'] === 'admin' && isLocalRequest()): ?><a href="manage_services.php" class="btn btn-outline-primary">Manage Services</a><?php endif; ?>
            <?php if ($authenticatedUser['role'] === 'admin' && isLocalRequest()): ?><a href="manage_specialists.php" class="btn btn-outline-primary">Manage Specialists</a><?php endif; ?>
            <form method="post" action="logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
                <button type="submit" class="btn btn-outline-secondary">Log Out</button>
            </form>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
