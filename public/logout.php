<?php
require_once __DIR__ . '/../auth/auth.php';
header('Cache-Control: no-store');
$pageTitle = 'Log Out';
$isAuthPage = true;
$authenticatedUser = null;
$errorMessage = null;
$token = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    startSpaSession();
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        $errorMessage = 'Please use the logout confirmation form.';
    } else {
        $token = csrfToken();
        if ($method === 'POST') {
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errorMessage = 'Your form expired. Please confirm logout again.';
            } else {
                endAuthenticatedSession();
                header('Location: login.php?logged_out=1', true, 303);
                exit;
            }
        }
        // Session logout must still work when the database is unavailable.
        $authenticatedUser = currentUser();
    }
} catch (PDOException $error) {
    error_log('Spa logout database error: ' . $error->getMessage());
    http_response_code(503);
    $errorMessage = 'Account services are temporarily unavailable. Please try again shortly.';
} catch (RuntimeException $error) {
    error_log('Spa logout error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'We could not complete your request. Please try again shortly.';
}

require __DIR__ . '/../components/management_header.php';
?>
<section class="auth-panel management-panel mx-auto" aria-labelledby="logout-heading">
    <h1 id="logout-heading">Log Out</h1>
    <?php if ($errorMessage !== null): ?><div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <?php if ($authenticatedUser !== null && $token !== null): ?>
        <p>Are you sure you want to log out, <?= escapeHtml($authenticatedUser['full_name']) ?>?</p>
        <form method="post" action="logout.php" class="management-actions">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
            <button type="submit" class="btn btn-primary">Log Out</button>
            <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    <?php elseif ($errorMessage === null): ?>
        <p>You are currently logged out.</p>
        <a href="login.php" class="btn btn-primary">Log In</a>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
