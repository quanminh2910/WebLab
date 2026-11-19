<?php
// Run with PHP CLI against the local Lab database. The test account is rolled back.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../auth/auth.php';

function checkSecurity(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

$db = databaseConnection();
$sessionDir = sys_get_temp_dir() . '/spa-lab3-security-' . bin2hex(random_bytes(12));
if (!mkdir($sessionDir, 0700)) {
    throw new RuntimeException('Could not create the temporary session directory.');
}
session_save_path($sessionDir);
try {
    $db->beginTransaction();
    $name = "An O'Neil & Mai <Guest>";
    $email = "o'neil." . bin2hex(random_bytes(12)) . '@example.test';
    $password = bin2hex(random_bytes(24));
    $id = createUser(['full_name' => $name, 'email' => $email], $password);
    $row = findUserByEmail($email);
    checkSecurity((int) $row['id'] === $id && $row['full_name'] === $name, 'The parameterized account lookup failed.');
    checkSecurity(escapeHtml($name) === 'An O&#039;Neil &amp; Mai &lt;Guest&gt;', 'Display text was not escaped.');
    checkSecurity(password_verify($password, $row['password_hash']), 'The registered password was not hashed correctly.');

    $setHash = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $legacyHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    $setHash->execute([$legacyHash, $id]);
    checkSecurity(authenticateUser($email, 'incorrect') === null
        && findUserByEmail($email)['password_hash'] === $legacyHash, 'A failed login changed the hash.');
    $user = authenticateUser($email, $password);
    $upgraded = findUserByEmail($email)['password_hash'];
    checkSecurity($user !== null && (int) $user['id'] === $id && !isset($user['password_hash'])
        && $upgraded !== $legacyHash && password_verify($password, $upgraded)
        && !password_needs_rehash($upgraded, PASSWORD_DEFAULT), 'Successful login did not upgrade the old hash.');
    authenticateUser($email, $password);
    checkSecurity(findUserByEmail($email)['password_hash'] === $upgraded, 'The current hash was needlessly replaced.');
    if (PASSWORD_DEFAULT === PASSWORD_BCRYPT) {
        $stronger = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_BCRYPT_DEFAULT_COST + 1]);
        $setHash->execute([$stronger, $id]);
        checkSecurity(authenticateUser($email, $password) !== null
            && findUserByEmail($email)['password_hash'] === $stronger, 'A higher-cost hash was downgraded.');
    }

    refreshExistingSpaSession();
    checkSecurity(session_status() === PHP_SESSION_NONE, 'An anonymous AJAX lookup started a session.');
    startSpaSession();
    $guestId = session_id();
    $guestToken = csrfToken();
    beginAuthenticatedSession($user);
    $activeId = session_id();
    $activeToken = csrfToken();
    checkSecurity($activeId !== $guestId && $activeToken !== $guestToken, 'Login must renew the session and its form token.');

    unset($_SESSION['last_activity']); // Session saved by the previous lab.
    session_write_close();
    startSpaSession();
    checkSecurity((int) currentUser()['id'] === $id && isset($_SESSION['last_activity']), 'An existing session did not receive an idle timer.');
    $_SESSION['last_activity'] = time() - SPA_SESSION_IDLE_MINUTES * 60 + 60;
    session_write_close();
    $_COOKIE[SPA_SESSION_NAME] = $activeId;
    refreshExistingSpaSession();
    checkSecurity(session_status() === PHP_SESSION_NONE && session_id() === $activeId
        && $_SESSION['last_activity'] >= time() - 1, 'AJAX activity must refresh the idle timer and release the session lock.');

    startSpaSession();
    $_SESSION['last_activity'] = time() - SPA_SESSION_IDLE_MINUTES * 60;
    session_write_close();
    startSpaSession();
    checkSecurity(currentUser() === null && session_id() !== $activeId
        && !validCsrfToken($activeToken), 'Expiry must remove the account, old session ID and form token.');
    checkSecurity(str_contains(pullAuthFlash() ?? '', 'inactivity'), 'The login page must explain the expired session.');
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    foreach (glob($sessionDir . '/sess_*') as $file) {
        unlink($file);
    }
    rmdir($sessionDir);
}
echo "PASS  Parameterized lookup and HTML escaping preserve a name with punctuation.\n";
echo "PASS  Only a verified login upgrades an old password hash.\n";
echo "PASS  Login renews the session. AJAX activity refreshes it. Expiry invalidates its form token.\n";

// Set the same preview URL as the other HTTP integration tests to include response headers.
$baseUrl = getenv('SPA_TEST_BASE_URL');
if ($baseUrl) {
    $context = stream_context_create(['http' => ['follow_location' => 0, 'timeout' => 5]]);
    $routes = ['public/login.php' => 200, 'public/dashboard.php' => 302,
        'public/search_services.php?q=massage' => 200, 'public/available_times.php' => 400];
    foreach ($routes as $path => $status) {
        $headers = get_headers(rtrim($baseUrl, '/') . '/' . $path, true, $context);
        checkSecurity(is_array($headers) && str_contains($headers[0], " $status "), "Unexpected HTTP result for $path.");
        $headers = array_change_key_case($headers, CASE_LOWER);
        $policy = $headers['content-security-policy'] ?? '';
        checkSecurity(($headers['x-content-type-options'] ?? '') === 'nosniff'
            && str_contains($policy, "script-src 'self' https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js;")
            && str_contains($policy, "form-action 'self'") && str_contains($policy, "frame-ancestors 'none'"),
            "Missing browser policy on $path.");
    }
    echo "PASS  HTML, AJAX, validation errors and protected redirects send the browser policy.\n";
}
