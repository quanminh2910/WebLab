<?php

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../includes/services.php';

function isLocalRequest(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function currentUser(): ?array
{
    startSpaSession();
    $storedId = $_SESSION['user_id'] ?? null;
    $id = (is_string($storedId) || is_int($storedId))
        ? filter_var($storedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
        : false;

    if ($id === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    // Read the current database role each time; deleted accounts lose access immediately.
    $statement = databaseConnection()->prepare(
        'SELECT id, full_name, email, role, created_at FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    if ($user === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function requireAuthenticatedUser(): array
{
    startSpaSession();
    header('Cache-Control: no-store');
    $user = currentUser();

    if ($user === null) {
        header('Location: ../public/login.php', true, 302);
        exit;
    }

    return $user;
}

function requireAdministrator(): array
{
    $user = requireAuthenticatedUser();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        $authenticatedUser = $user;
        require __DIR__ . '/../components/access_denied.php';
        exit;
    }

    return $user;
}

function validateRegistrationInput(array $input): array
{
    $values = ['full_name' => '', 'email' => ''];
    $errors = [];

    foreach ($values as $field => $default) {
        if (!isset($input[$field]) || !is_string($input[$field])) {
            $errors[$field] = 'Enter a single valid value.';
            continue;
        }
        $values[$field] = trim($input[$field]);
        if ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        } elseif (!mb_check_encoding($values[$field], 'UTF-8')) {
            $errors[$field] = 'Enter valid text.';
        }
    }

    $values['email'] = strtolower($values['email']);
    if (!isset($errors['full_name']) && mb_strlen($values['full_name'], 'UTF-8') > 100) {
        $errors['full_name'] = 'Use no more than 100 characters for your name.';
    }
    if (!isset($errors['email'])
        && (strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = 'Enter a valid email address of no more than 254 characters.';
    }

    // Keep passwords separate from values: they must never be repopulated in the form.
    $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
    if (!isset($input['password']) || !is_string($input['password']) || $password === '') {
        $errors['password'] = 'Enter a password.';
    } elseif (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) {
        $errors['password'] = 'Use valid text without null characters for your password.';
    } elseif (mb_strlen($password, 'UTF-8') < 8 || strlen($password) > 72) {
        $errors['password'] = 'Use at least 8 characters and no more than 72 bytes for your password.';
    }

    if (!isset($input['password_confirmation']) || !is_string($input['password_confirmation'])
        || $input['password_confirmation'] === '') {
        $errors['password_confirmation'] = 'Confirm your password.';
    } elseif ($input['password_confirmation'] !== $password) {
        $errors['password_confirmation'] = 'The passwords must match exactly.';
    }

    return ['values' => $values, 'password' => $password, 'errors' => $errors];
}

function findUserByEmail(string $email): ?array
{
    // This result includes the hash for login verification; never render it publicly.
    $statement = databaseConnection()->prepare(
        'SELECT id, full_name, email, password_hash, role, created_at
         FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    return $user === false ? null : $user;
}

function createUser(array $values, string $password, string $role = 'customer'): int
{
    if (!in_array($role, ['admin', 'customer'], true)) {
        throw new RuntimeException('An invalid account role was supplied.');
    }
    if (str_contains($password, "\0")) {
        throw new RuntimeException('A password must not contain null characters.');
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    } catch (Throwable $error) {
        throw new RuntimeException('The password could not be secured. Please try again.', 0, $error);
    }
    if ($hash === false) {
        throw new RuntimeException('The password could not be secured. Please try again.');
    }

    $statement = databaseConnection()->prepare(
        'INSERT INTO users (full_name, email, password_hash, role)
         VALUES (:full_name, :email, :password_hash, :role)'
    );
    $statement->execute([
        'full_name' => $values['full_name'],
        'email' => $values['email'],
        'password_hash' => $hash,
        'role' => $role,
    ]);

    return (int) databaseConnection()->lastInsertId();
}

function beginAuthenticatedSession(array $user): void
{
    startSpaSession();
    $id = isset($user['id']) && (is_string($user['id']) || is_int($user['id']))
        ? filter_var($user['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]])
        : false;
    if ($id === false) {
        throw new RuntimeException('A valid account is required to begin a session.');
    }
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('A secure login session could not be created. Please try again.');
    }

    // Drop pre-login tokens and messages; only the account id identifies the user.
    $_SESSION = ['user_id' => $id];
}

function endAuthenticatedSession(): void
{
    startSpaSession();
    $_SESSION = [];
    $parameters = session_get_cookie_params();
    $cookieExpired = setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $parameters['path'],
        'domain' => $parameters['domain'],
        'secure' => $parameters['secure'],
        'httponly' => $parameters['httponly'],
        'samesite' => $parameters['samesite'] ?? 'Lax',
    ]);
    $sessionDestroyed = session_destroy();

    if (!$cookieExpired || !$sessionDestroyed) {
        throw new RuntimeException('Your session could not be fully closed. Please try again.');
    }
}
