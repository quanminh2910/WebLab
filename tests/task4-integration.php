<?php

// CLI only: uses unique disposable accounts; existing users and services must not change.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This verification script is available from the command line only.');
}
require_once __DIR__ . '/../includes/services.php';
$baseUrl = rtrim(getenv('SPA_TEST_BASE_URL') ?: 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/', '/') . '/';
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
$pdo = databaseConnection();
$beforeUsers = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
$beforeServices = $pdo->query('SELECT * FROM services ORDER BY id')->fetchAll();
$prefix = 'task4-check-' . bin2hex(random_bytes(8));
$customerEmail = $prefix . '@example.test';
$adminEmail = $prefix . '-admin@example.test';
$fixtureIds = [];
$fixtureEmails = [];
$checks = 0;
$failure = null;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function requestPage(string $path, ?array $post = null): array
{
    global $curl, $baseUrl;
    $headers = [];
    curl_setopt($curl, CURLOPT_URL, $baseUrl . $path);
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$headers) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return strlen($line);
    });
    if ($post === null) {
        curl_setopt($curl, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $html = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    check($html !== false, 'HTTP request failed: ' . curl_error($curl));
    check(!preg_match('/SQLSTATE|PDOException|Stack trace:|Fatal error:|Warning:|mysql:host=/i', $html), 'Technical errors appeared in ' . $path);
    return [$status, $html, $headers];
}
function parseHtml(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
function getToken(string $path): string
{
    [$status, $html] = requestPage($path);
    check($status === 200, 'Cannot open authentication form: ' . $path);
    $token = parseHtml($html)->query('//input[@name="csrf_token" and @type="hidden"]')->item(0);
    check($token !== null && strlen($token->getAttribute('value')) >= 32, 'Missing CSRF token.');
    return $token->getAttribute('value');
}
function sessionId(): string
{
    global $curl;
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) {
        $fields = explode("\t", $cookie);
        if (($fields[5] ?? '') === 'spa_session') {
            return $fields[6];
        }
    }
    return '';
}
function findUser(string $email): ?array
{
    global $pdo;
    $statement = $pdo->prepare('SELECT * FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();
    return $user === false ? null : $user;
}
function checkNoPasswords(string $html, string $password): void
{
    check(!str_contains($html, $password), 'A response exposed the submitted password.');
    foreach (parseHtml($html)->query('//input[@type="password"]') as $input) {
        check($input->getAttribute('value') === '', 'A password form input was repopulated.');
    }
}
function checkLoginRedirect(array $response): void
{
    [$status, , $headers] = $response;
    check(in_array($status, [302, 303], true) && str_contains($headers['location'] ?? '', 'login.php'), 'Anonymous protected access must redirect to login.');
}
function errorMessages(string $html): array
{
    $messages = [];
    foreach (parseHtml($html)->query('//*[@role="alert"]') as $alert) {
        $messages[] = trim($alert->textContent);
    }
    return $messages;
}
try {
    $invalidEmail = $prefix . '-invalid';
    $longEmail = $prefix . str_repeat('x', 250) . '@x.test';
    foreach ([$customerEmail, $adminEmail, $invalidEmail, $longEmail] as $email) {
        check(findUser($email) === null, 'Random fixture email unexpectedly exists; refusing to use it.');
    }
    $fixtureEmails = [$customerEmail, $adminEmail, $invalidEmail, $longEmail];
    [$initialStatus, , $initialHeaders] = requestPage('public/register.php');
    check($initialStatus === 200, 'The registration page must open in a fresh browser session.');
    $sessionCookie = $initialHeaders['set-cookie'] ?? '';
    check(str_starts_with($sessionCookie, 'spa_session='), 'A fresh visit must create the named session cookie.');
    foreach (['HttpOnly', 'SameSite=Lax', 'Path=/'] as $attribute) {
        check((bool) preg_match('/(?:^|;\s*)' . preg_quote($attribute, '/') . '(?:;|$)/i', $sessionCookie), 'Session cookie is missing ' . $attribute . '.');
    }
    check(!preg_match('/(?:^|;\s*)(?:Expires|Max-Age)=/i', $sessionCookie), 'Authentication cookie must have browser-session lifetime.');
    $https = parse_url($baseUrl, PHP_URL_SCHEME) === 'https';
    check((bool) preg_match('/(?:^|;\s*)Secure(?:;|$)/i', $sessionCookie) === $https, 'Session cookie Secure flag must match HTTPS.');
    $themeCookie = implode("\t", [parse_url($baseUrl, PHP_URL_HOST), 'FALSE', '/', $https ? 'TRUE' : 'FALSE', (string) (time() + 2592000), 'spa_theme', 'dark']);
    curl_setopt($curl, CURLOPT_COOKIELIST, $themeCookie);
    checkLoginRedirect(requestPage('public/dashboard.php'));
    checkLoginRedirect(requestPage('public/manage_services.php'));
    $password = 'Spa-' . bin2hex(random_bytes(12));
    $registration = [
        'full_name' => 'Test <b>"Customer"</b>', 'email' => $customerEmail,
        'password' => $password, 'password_confirmation' => $password, 'role' => 'admin',
        'csrf_token' => getToken('public/register.php'),
    ];
    [$badCsrf] = requestPage('public/register.php', array_replace($registration, ['csrf_token' => 'wrong']));
    check($badCsrf === 403 && findUser($customerEmail) === null, 'Invalid registration CSRF must not create an account.');
    $invalidCases = [
        'required name' => ['full_name' => ''], 'name length' => ['full_name' => str_repeat('x', 101)],
        'invalid email' => ['email' => $invalidEmail], 'email length' => ['email' => $longEmail],
        'short password' => ['password' => '1234567', 'password_confirmation' => '1234567'],
        'long password' => ['password' => str_repeat('x', 73), 'password_confirmation' => str_repeat('x', 73)],
        'password bytes' => ['password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)],
        'password NUL' => ['password' => "password\0test", 'password_confirmation' => "password\0test"],
        'confirmation' => ['password_confirmation' => 'different-password'], 'array input' => ['full_name' => ['invalid']],
    ];
    foreach ($invalidCases as $label => $changes) {
        [$status, $html] = requestPage('public/register.php', array_replace($registration, $changes));
        check($status === 422 && findUser($customerEmail) === null, 'Registration validation failed: ' . $label);
        checkNoPasswords($html, $password);
    }
    [$registered, $html, $headers] = requestPage('public/register.php', $registration);
    check($registered === 303 && str_contains($headers['location'] ?? '', 'login.php'), 'Registration must redirect to login.');
    $customer = findUser($customerEmail);
    check($customer !== null, 'Registration did not save an account.');
    $fixtureIds[$customerEmail] = (int) $customer['id'];
    check($customer['role'] === 'customer' && $customer['full_name'] === $registration['full_name'], 'Registration trusted a submitted role or changed the name.');
    check($customer['password_hash'] !== $password && password_verify($password, $customer['password_hash']), 'Password was not stored as a valid hash.');
    checkLoginRedirect(requestPage('public/dashboard.php'));
    [$duplicate, $duplicateHtml] = requestPage('public/register.php', array_replace($registration, ['csrf_token' => getToken('public/register.php')]));
    check($duplicate === 422 && findUser($customerEmail) === $customer, 'Duplicate registration must be rejected without changing the account.');
    checkNoPasswords($duplicateHtml, $password);
    $login = ['email' => $customerEmail, 'password' => $password, 'csrf_token' => getToken('public/login.php')];
    $preLoginSession = sessionId();
    [$loginCsrf] = requestPage('public/login.php', array_replace($login, ['csrf_token' => 'wrong']));
    check($loginCsrf === 403, 'Invalid login CSRF must return HTTP 403.');
    [$wrongStatus, $wrongHtml] = requestPage('public/login.php', array_replace($login, ['password' => 'wrong-password']));
    [$unknownStatus, $unknownHtml] = requestPage('public/login.php', array_replace($login, ['email' => $prefix . '-missing@example.test']));
    check($wrongStatus === 401 && $unknownStatus === 401 && errorMessages($wrongHtml) !== [] && errorMessages($wrongHtml) === errorMessages($unknownHtml), 'Login errors must be generic for wrong and unknown credentials.');
    checkNoPasswords($wrongHtml, 'wrong-password');
    [$loggedIn, , $loginHeaders] = requestPage('public/login.php', $login);
    check($loggedIn === 303 && str_contains($loginHeaders['location'] ?? '', 'dashboard.php'), 'Valid credentials must redirect to dashboard.');
    $authenticatedSession = sessionId();
    check($preLoginSession !== '' && $authenticatedSession !== '' && $preLoginSession !== $authenticatedSession, 'Login must rotate the spa_session ID.');
    [$dashboard, $dashboardHtml, $dashboardHeaders] = requestPage('public/dashboard.php');
    check($dashboard === 200 && str_contains($dashboardHtml, escapeHtml($registration['full_name'])) && str_contains($dashboardHtml, escapeHtml($customerEmail)), 'Dashboard must show escaped account details.');
    check(str_contains(strtolower($dashboardHeaders['cache-control'] ?? ''), 'no-store'), 'Authenticated dashboard must disable caching.');
    check(!str_contains($dashboardHtml, $customer['password_hash']) && !str_contains($dashboardHtml, '<b>"Customer"</b>'), 'Dashboard exposed a hash or rendered stored HTML.');
    checkNoPasswords($dashboardHtml, $password);
    foreach (['public/manage_services.php', 'public/service_form.php', 'public/delete_service.php?id=1'] as $route) {
        [$forbidden] = requestPage($route);
        check($forbidden === 403, 'Customer accessed administrator route: ' . $route);
    }
    $logoutToken = getToken('public/logout.php');
    [$stillLoggedIn] = requestPage('public/dashboard.php');
    check($stillLoggedIn === 200, 'GET logout must retain the session until confirmed.');
    [$logoutCsrf] = requestPage('public/logout.php', ['csrf_token' => 'wrong']);
    [$stillLoggedIn] = requestPage('public/dashboard.php');
    check($logoutCsrf === 403 && $stillLoggedIn === 200, 'Invalid logout CSRF must preserve authentication.');
    [$loggedOut, , $logoutHeaders] = requestPage('public/logout.php', ['csrf_token' => $logoutToken]);
    check($loggedOut === 303 && str_contains($logoutHeaders['location'] ?? '', 'login.php'), 'Confirmed logout must redirect to login.');
    [$nextLoginStatus, $nextLoginHtml] = requestPage('public/login.php');
    check($nextLoginStatus === 200 && parseHtml($nextLoginHtml)->query('//html')->item(0)->getAttribute('data-theme') === 'dark', 'Theme preference must survive logout on the next login page.');
    $themeRemains = false;
    foreach (curl_getinfo($curl, CURLINFO_COOKIELIST) as $cookie) {
        $fields = explode("\t", $cookie);
        if (($fields[5] ?? '') === 'spa_theme' && ($fields[6] ?? '') === 'dark') {
            $themeRemains = true;
        }
    }
    check($themeRemains, 'Logout must preserve the non-sensitive theme cookie.');
    checkLoginRedirect(requestPage('public/dashboard.php'));
    curl_setopt($curl, CURLOPT_COOKIELIST, 'ALL');
    curl_setopt($curl, CURLOPT_COOKIE, 'spa_session=' . $authenticatedSession);
    checkLoginRedirect(requestPage('public/dashboard.php'));
    curl_setopt($curl, CURLOPT_COOKIE, '');
    curl_setopt($curl, CURLOPT_COOKIELIST, 'ALL');
    $adminPassword = 'Spa-admin-' . bin2hex(random_bytes(12));
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/create-admin.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    check(is_resource($process), 'Administrator onboarding tool could not be started.');
    fwrite($pipes[0], json_encode(['full_name' => 'Temporary auth verifier', 'email' => $adminEmail, 'password' => $adminPassword, 'password_confirmation' => $adminPassword], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $toolOutput = stream_get_contents($pipes[1]);
    $toolError = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Administrator onboarding tool failed.');
    check(str_contains($toolOutput, $adminEmail) && !str_contains($toolOutput . $toolError, $adminPassword), 'Administrator onboarding output is missing its email or exposes its password.');
    $admin = findUser($adminEmail);
    check($admin !== null && $admin['role'] === 'admin' && password_verify($adminPassword, $admin['password_hash']), 'Administrator onboarding did not save a usable administrator.');
    $fixtureIds[$adminEmail] = (int) $admin['id'];
    [$adminLogin] = requestPage('public/login.php', ['email' => $adminEmail, 'password' => $adminPassword, 'csrf_token' => getToken('public/login.php')]);
    check($adminLogin === 303, 'Temporary administrator could not log in.');
    foreach (['public/dashboard.php', 'public/manage_services.php', 'public/service_form.php'] as $route) {
        [$allowed] = requestPage($route);
        check($allowed === 200, 'Administrator could not open ' . $route);
    }
    $revoke = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id AND email = :email');
    $revoke->execute(['role' => 'customer', 'id' => $fixtureIds[$adminEmail], 'email' => $adminEmail]);
    [$revoked] = requestPage('public/manage_services.php');
    check($revoked === 403, 'Server must recheck the current database role.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        foreach ($fixtureEmails as $email) {
            $fixture = findUser($email);
            if ($fixture !== null) {
                $expectedId = $fixtureIds[$email] ?? (int) $fixture['id'];
                $delete = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email');
                $delete->execute(['id' => $expectedId, 'email' => $email]);
            }
        }
        check($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll() === $beforeUsers, 'Existing accounts changed during the auth test.');
        check($pdo->query('SELECT * FROM services ORDER BY id')->fetchAll() === $beforeServices, 'Existing services changed during the auth test.');
    } catch (Throwable $cleanupError) {
        $failure = new RuntimeException('Cleanup/preservation failed: ' . $cleanupError->getMessage(), 0, $failure);
    }
    curl_close($curl);
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "PASS: $checks checks; registration, hashes, login, sessions, roles, dashboard, and logout.\n";
echo "Temporary accounts removed; all original accounts and services remain unchanged.\n";
