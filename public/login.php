<?php
require_once __DIR__ . '/../auth/auth.php';
header('Cache-Control: no-store');
$pageTitle = 'Log In';
$isAuthPage = true;
$authenticatedUser = null;
$email = '';
$errors = [];
$errorMessage = null;
$flashMessage = null;
$token = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    startSpaSession();
    $authenticatedUser = currentUser();
    if ($authenticatedUser !== null) {
        header('Location: dashboard.php', true, 303);
        exit;
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        $errorMessage = 'Please open the login page using its navigation link.';
    } else {
        $token = csrfToken();
        $flashMessage = pullAuthFlash();
        if ($method === 'GET' && ($_GET['logged_out'] ?? null) === '1') {
            $flashMessage = 'You have been logged out.';
        }
        if ($method === 'POST') {
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errorMessage = 'Your form expired. Please try submitting it again.';
            } else {
                $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Enter a valid email address.';
                }
                if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")
                    || !mb_check_encoding($password, 'UTF-8')) {
                    $errors['password'] = 'Enter a valid password of no more than 72 bytes.';
                }
                if ($errors !== []) {
                    http_response_code(422);
                } else {
                    $user = findUserByEmail($email);
                    // The dummy hash keeps unknown-email password verification comparable.
                    $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
                    $passwordMatches = password_verify($password, $hash);
                    if ($user === null || !$passwordMatches) {
                        http_response_code(401);
                        $errorMessage = 'Invalid email or password.';
                    } else {
                        beginAuthenticatedSession($user);
                        setAuthFlash('You are now logged in.');
                        header('Location: dashboard.php', true, 303);
                        exit;
                    }
                }
            }
        }
    }
} catch (PDOException $error) {
    error_log('Spa login database error: ' . $error->getMessage());
    http_response_code(503);
    $errorMessage = 'Login is temporarily unavailable. Please try again shortly.';
} catch (RuntimeException $error) {
    error_log('Spa login error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'We could not complete your request. Please try again shortly.';
}

require __DIR__ . '/../components/management_header.php';
?>
<section class="auth-panel management-panel mx-auto" aria-labelledby="login-heading">
    <h1 id="login-heading">Log In</h1>
    <p class="text-muted">Welcome back to Spa &amp; Beauty Center.</p>
    <?php if ($flashMessage !== null): ?><div class="alert alert-success" role="status"><?= escapeHtml($flashMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div><?php endif; ?>
    <?php if ($token !== null): ?>
        <form method="post" action="login.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
            <div class="mb-3">
                <label for="login-email" class="form-label">Email address</label>
                <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="login-email" name="email" value="<?= escapeHtml($email) ?>" maxlength="254" autocomplete="username" required<?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="login-email-error"' : '' ?>>
                <?php if (isset($errors['email'])): ?><div class="invalid-feedback" id="login-email-error"><?= escapeHtml($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="mb-4">
                <label for="login-password" class="form-label">Password</label>
                <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="login-password" name="password" maxlength="72" autocomplete="current-password" required<?= isset($errors['password']) ? ' aria-invalid="true" aria-describedby="login-password-error"' : '' ?>>
                <?php if (isset($errors['password'])): ?><div class="invalid-feedback" id="login-password-error"><?= escapeHtml($errors['password']) ?></div><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log In</button>
        </form>
    <?php endif; ?>
    <p class="auth-links mb-0 mt-4">New here? <a href="register.php">Create an account</a></p>
</section>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
