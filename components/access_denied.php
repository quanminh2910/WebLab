<?php
// This template is included by the role guard, not opened as a standalone page.
if (!isset($authenticatedUser) || !is_array($authenticatedUser)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Access denied.');
}

$pageTitle = 'Access Denied';
$isAuthPage = true;
require __DIR__ . '/management_header.php';
?>
<section class="auth-panel management-panel mx-auto" aria-labelledby="access-denied-heading">
    <h1 id="access-denied-heading">Access Denied</h1>
    <p role="alert">Administrator access is required to manage services.</p>
    <p>You can still explore our treatments and view your account.</p>
    <div class="management-actions mt-4">
        <a href="dashboard.php" class="btn btn-primary">My Account</a>
        <a href="index.php#items" class="btn btn-outline-secondary">Explore Services</a>
    </div>
</section>
<?php require __DIR__ . '/management_footer.php'; ?>
