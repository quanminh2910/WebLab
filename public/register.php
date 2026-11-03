<?php
require_once __DIR__ . '/../auth/auth.php';
header('Cache-Control: no-store');
$pageTitle = 'Create an Account';
$isAuthPage = true;
$authenticatedUser = null;
$values = ['full_name' => '', 'email' => ''];
$errors = [];
$errorMessage = null;
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
        $errorMessage = 'Please open the registration page using its navigation link.';
    } else {
        $token = csrfToken();
        if ($method === 'POST') {
            if (!validCsrfToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                $errorMessage = 'Your form expired. Please try submitting it again.';
            } else {
                $validation = validateRegistrationInput($_POST);
                $values = $validation['values'];
                $errors = $validation['errors'];
                if ($errors === [] && findUserByEmail($values['email']) !== null) {
                    $errors['email'] = 'An account with this email already exists. Please log in.';
                }
                if ($errors !== []) {
                    http_response_code(422);
                } else {
                    // Public registration always creates a customer, regardless of submitted fields.
                    createUser($values, $validation['password'], 'customer');
                    setAuthFlash('Registration successful. Please log in.');
                    header('Location: login.php', true, 303);
                    exit;
                }
            }
        }
    }
} catch (PDOException $error) {
    error_log('Spa registration database error: ' . $error->getMessage());
    if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
        http_response_code(422);
        $errors['email'] = 'An account with this email already exists. Please log in.';
    } else {
        http_response_code(503);
        $errorMessage = 'Account registration is temporarily unavailable. Please try again shortly.';
    }
} catch (RuntimeException $error) {
    error_log('Spa registration error: ' . $error->getMessage());
    http_response_code(500);
    $errorMessage = 'We could not complete your request. Please try again shortly.';
}

require __DIR__ . '/../components/management_header.php';
?>
<section class="auth-panel management-panel mx-auto" aria-labelledby="register-heading">
    <h1 id="register-heading">Create an Account</h1>
    <p class="text-muted">Join Spa &amp; Beauty Center and access your personal dashboard.</p>
    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert-danger" role="alert">Please correct the highlighted fields.</div>
    <?php endif; ?>
    <?php if ($token !== null): ?>
        <form method="post" action="register.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml($token) ?>">
            <div class="mb-3">
                <label for="full-name" class="form-label">Full name</label>
                <input type="text" class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>" id="full-name" name="full_name" value="<?= escapeHtml($values['full_name']) ?>" maxlength="100" autocomplete="name" required<?= isset($errors['full_name']) ? ' aria-invalid="true" aria-describedby="full-name-error"' : '' ?>>
                <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback" id="full-name-error"><?= escapeHtml($errors['full_name']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="register-email" class="form-label">Email address</label>
                <input type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" id="register-email" name="email" value="<?= escapeHtml($values['email']) ?>" maxlength="254" autocomplete="email" required<?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="register-email-error"' : '' ?>>
                <?php if (isset($errors['email'])): ?><div class="invalid-feedback" id="register-email-error"><?= escapeHtml($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label for="register-password" class="form-label">Password</label>
                <input type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="register-password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required aria-describedby="password-help<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
                <div class="form-text" id="password-help">Use at least 8 characters.</div>
                <?php if (isset($errors['password'])): ?><div class="invalid-feedback" id="password-error"><?= escapeHtml($errors['password']) ?></div><?php endif; ?>
            </div>
            <div class="mb-4">
                <label for="password-confirmation" class="form-label">Confirm password</label>
                <input type="password" class="form-control<?= isset($errors['password_confirmation']) ? ' is-invalid' : '' ?>" id="password-confirmation" name="password_confirmation" maxlength="72" autocomplete="new-password" required<?= isset($errors['password_confirmation']) ? ' aria-invalid="true" aria-describedby="password-confirmation-error"' : '' ?>>
                <?php if (isset($errors['password_confirmation'])): ?><div class="invalid-feedback" id="password-confirmation-error"><?= escapeHtml($errors['password_confirmation']) ?></div><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary w-100">Create Account</button>
        </form>
    <?php endif; ?>
    <p class="auth-links mb-0 mt-4">Already have an account? <a href="login.php">Log in</a></p>
</section>
<?php require __DIR__ . '/../components/management_footer.php'; ?>
