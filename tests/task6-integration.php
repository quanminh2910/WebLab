<?php
// Role checks use only disposable accounts/services; no existing record may change.
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
$prefix = 'task6-check-' . bin2hex(random_bytes(8));
$emails = [$prefix . '-customer@example.test', $prefix . '-admin@example.test'];
$slugs = [$prefix, $prefix . '-blocked'];
$ownedEmails = $ownedSlugs = $fixtureIds = $passwords = [];
$checks = 0;
$failure = null;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function requestPage(string $path, ?array $post = null): array
{
    global $curl, $baseUrl, $passwords;
    $headers = [];
    curl_setopt($curl, CURLOPT_URL, $baseUrl . $path);
    curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$headers) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return strlen($line);
    });
    if ($post === null) curl_setopt($curl, CURLOPT_HTTPGET, true);
    else {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $html = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    check($html !== false, 'HTTP request failed: ' . curl_error($curl));
    check(!preg_match('/SQLSTATE|PDOException|Stack trace:|Fatal error:|Warning:|mysql:host=/i', $html), 'A public response disclosed a technical error.');
    foreach ($passwords as $password) check(!str_contains($html, $password), 'A response disclosed a test password.');
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
    check($status === 200, 'Could not open a permitted form: ' . $path);
    $input = parseHtml($html)->query('//input[@name="csrf_token"]')->item(0);
    check($input !== null && strlen($input->getAttribute('value')) >= 32, 'Missing CSRF token.');
    return $input->getAttribute('value');
}
function rowBy(string $table, string $column, string $value): ?array
{
    global $pdo;
    $statement = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $column . ' = :value');
    $statement->execute(['value' => $value]);
    return ($row = $statement->fetch()) === false ? null : $row;
}
function deniedRoutes(array $routes, array $payload, ?int $expectedStatus): void
{
    global $pdo;
    $snapshot = $pdo->query('SELECT * FROM services ORDER BY id')->fetchAll();
    foreach ($routes as $route) {
        foreach ([null, $payload] as $post) {
            [$status, , $headers] = requestPage($route, $post);
            check($expectedStatus === null
                ? in_array($status, [302, 303], true) && str_contains($headers['location'] ?? '', 'login.php')
                : $status === $expectedStatus, 'Protected route did not enforce the current role: ' . $route);
            check($pdo->query('SELECT * FROM services ORDER BY id')->fetchAll() === $snapshot, 'A denied request changed service records.');
        }
    }
}
try {
    foreach ($emails as $email) check(rowBy('users', 'email', $email) === null, 'Fixture email already exists.');
    foreach ($slugs as $slug) check(rowBy('services', 'slug', $slug) === null, 'Fixture slug already exists.');
    $ownedEmails = $emails;
    $ownedSlugs = $slugs;
    $categories = fetchCategories();
    check($categories !== [] && $beforeServices !== [], 'Existing category and image are required.');
    $service = ['name' => 'Temporary RBAC verifier', 'slug' => $slugs[0], 'category_id' => (string) $categories[0]['id'],
        'short_description' => 'Temporary permission test.', 'description' => 'Temporary permission test service.',
        'price' => '99000', 'duration_min_minutes' => '30', 'duration_max_minutes' => '30', 'image' => $beforeServices[0]['image']];
    $insert = $pdo->prepare('INSERT INTO services (name, slug, category_id, short_description, description, price, duration_min_minutes, duration_max_minutes, image, is_featured) VALUES (:name, :slug, :category_id, :short_description, :description, :price, :duration_min_minutes, :duration_max_minutes, :image, 0)');
    $insert->execute($service);
    $fixtureId = (int) $pdo->lastInsertId();
    $editPath = 'public/service_form.php?id=' . $fixtureId;
    $deletePath = 'public/delete_service.php?id=' . $fixtureId;
    $routes = ['public/manage_services.php', 'public/service_form.php', $editPath, $deletePath];
    $passwords = ['Customer-' . bin2hex(random_bytes(12)), 'Admin-' . bin2hex(random_bytes(12))];
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/create-admin.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    check(is_resource($process), 'Administrator onboarding could not start.');
    fwrite($pipes[0], json_encode(['full_name' => 'Temporary RBAC administrator', 'email' => $emails[1], 'password' => $passwords[1], 'password_confirmation' => $passwords[1]], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $toolOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && !str_contains($toolOutput, $passwords[1]), 'Administrator onboarding failed or disclosed its password.');
    $admin = rowBy('users', 'email', $emails[1]);
    check($admin !== null && $admin['role'] === 'admin', 'CLI onboarding did not create an administrator.');
    $fixtureIds[$emails[1]] = (int) $admin['id'];
    $forged = ['role' => 'admin', 'user_id' => $admin['id'], 'admin_id' => $admin['id']];
    $forgedRoutes = array_map(fn($route) => $route . (str_contains($route, '?') ? '&' : '?') . http_build_query($forged), $routes);
    curl_setopt($curl, CURLOPT_COOKIELIST, implode("\t", [parse_url($baseUrl, PHP_URL_HOST), 'FALSE', '/', 'FALSE', '0', 'role', 'admin']));
    $anonymousToken = getToken('public/register.php');
    $attempt = array_merge($service, $forged, ['slug' => $slugs[1], 'csrf_token' => $anonymousToken, 'confirm_delete' => '1']);
    deniedRoutes($forgedRoutes, $attempt, null);
    [$registered, , $registeredHeaders] = requestPage('public/register.php', ['full_name' => 'Temporary RBAC customer', 'email' => $emails[0], 'password' => $passwords[0], 'password_confirmation' => $passwords[0], 'csrf_token' => $anonymousToken, 'role' => 'admin']);
    check($registered === 303 && str_contains($registeredHeaders['location'] ?? '', 'login.php'), 'Customer registration failed.');
    $customer = rowBy('users', 'email', $emails[0]);
    check($customer !== null && $customer['role'] === 'customer' && password_verify($passwords[0], $customer['password_hash']), 'Public registration allowed role escalation or invalid credentials.');
    $fixtureIds[$emails[0]] = (int) $customer['id'];
    [$login] = requestPage('public/login.php', ['email' => $emails[0], 'password' => $passwords[0], 'csrf_token' => getToken('public/login.php')]);
    check($login === 303, 'Customer could not log in.');
    [$dashboard, $html] = requestPage('public/dashboard.php');
    check($dashboard === 200 && parseHtml($html)->query('//a[contains(@href,"manage_services.php")]')->length === 0, 'Customer dashboard must be available without management navigation.');
    $attempt['csrf_token'] = getToken('public/dashboard.php');
    deniedRoutes($forgedRoutes, $attempt, 403);
    [$loggedOut] = requestPage('public/logout.php', ['csrf_token' => getToken('public/logout.php')]);
    check($loggedOut === 303, 'Customer logout failed.');
    [$login] = requestPage('public/login.php', ['email' => $emails[1], 'password' => $passwords[1], 'csrf_token' => getToken('public/login.php')]);
    check($login === 303, 'Administrator could not log in.');
    [$dashboard, $html] = requestPage('public/dashboard.php');
    check($dashboard === 200 && parseHtml($html)->query('//a[contains(@href,"manage_services.php")]')->length > 0, 'Administrator management navigation is missing.');
    foreach ($routes as $route) { [$status] = requestPage($route); check($status === 200, 'Administrator was blocked from ' . $route); }
    $service['csrf_token'] = getToken($editPath);
    [$unchangedEdit] = requestPage($editPath, $service);
    check($unchangedEdit === 303, 'Administrator no-op edit must succeed.');
    $service['price'] = '111000';
    [$saved] = requestPage($editPath, $service);
    check($saved === 303 && rowBy('services', 'slug', $slugs[0])['price'] === '111000', 'Administrator could not save an actual update.');
    $changeRole = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id AND email = :email');
    $changeRole->execute(['role' => 'customer', 'id' => $admin['id'], 'email' => $emails[1]]);
    deniedRoutes($forgedRoutes, array_merge($service, $forged, ['confirm_delete' => '1']), 403);
    $changeRole->execute(['role' => 'admin', 'id' => $admin['id'], 'email' => $emails[1]]);
    [$deleted] = requestPage($deletePath, ['csrf_token' => getToken($deletePath), 'confirm_delete' => '1']);
    check($deleted === 303 && rowBy('services', 'slug', $slugs[0]) === null, 'Restored administrator could not delete its temporary service.');
    $deleteAccount = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    $deleteAccount->execute(['id' => $admin['id'], 'email' => $emails[1]]);
    deniedRoutes($forgedRoutes, array_merge($service, $forged, ['confirm_delete' => '1']), null);
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        foreach ($ownedSlugs as $slug) {
            if (($row = rowBy('services', 'slug', $slug)) !== null) {
                $delete = $pdo->prepare('DELETE FROM services WHERE id = :id AND slug = :slug');
                $delete->execute(['id' => $row['id'], 'slug' => $slug]);
            }
        }
        foreach ($ownedEmails as $email) {
            if (($row = rowBy('users', 'email', $email)) !== null) {
                $delete = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email');
                $delete->execute(['id' => $fixtureIds[$email] ?? $row['id'], 'email' => $email]);
            }
        }
        check($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll() === $beforeUsers, 'Original user records changed.');
        check($pdo->query('SELECT * FROM services ORDER BY id')->fetchAll() === $beforeServices, 'Original service records changed.');
    } catch (Throwable $cleanupError) {
        $failure = new RuntimeException('Cleanup/preservation failed: ' . $cleanupError->getMessage(), 0, $failure);
    }
    curl_close($curl);
}
if ($failure !== null) { fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n"); exit(1); }
echo "PASS: $checks checks; anonymous/customer denials, forged roles, administrator writes, role revocation, and deleted-account invalidation.\n";
echo "Disposable accounts/services removed; all original users and services remain unchanged.\n";
