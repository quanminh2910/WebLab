<?php

// Creates and removes a uniquely named test service. Existing services must remain unchanged.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This verification script is available from the command line only.');
}
require_once __DIR__ . '/../includes/services.php';
$baseUrl = rtrim(getenv('SPA_TEST_BASE_URL') ?: 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/', '/') . '/';
$checks = 0;
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
$pdo = databaseConnection();
$before = $pdo->query('SELECT * FROM services ORDER BY id')->fetchAll();
$beforeUsers = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
$prefix = 'task3-check-' . bin2hex(random_bytes(8));
$testSlugs = [$prefix, $prefix . '-updated'];
$fixtureId = null;
$adminEmail = $prefix . '@example.test';
$adminId = null;
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
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    check($body !== false, 'HTTP request failed: ' . curl_error($curl));
    check(!preg_match('/SQLSTATE|PDOException|Stack trace:|Fatal error:|Warning:|mysql:host=/i', $body), 'Technical errors appeared in ' . $path);
    return [$status, $body, $headers];
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
    check($status === 200, 'Cannot open form: ' . $path);
    $token = parseHtml($html)->query('//input[@name="csrf_token" and @type="hidden"]')->item(0);
    check($token !== null && strlen($token->getAttribute('value')) >= 32, 'Missing form CSRF token.');
    return $token->getAttribute('value');
}
function findFixture(): ?array
{
    global $pdo, $fixtureId, $testSlugs;
    $query = $fixtureId === null
        ? $pdo->prepare('SELECT * FROM services WHERE slug IN (?, ?)')
        : $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $query->execute($fixtureId === null ? $testSlugs : [$fixtureId]);
    $row = $query->fetch();
    return $row === false ? null : $row;
}
function checkRedirect(array $response, string $action): void
{
    [$status, , $headers] = $response;
    check($status === 303 && str_contains($headers['location'] ?? '', 'manage_services.php'), 'Successful ' . $action . ' must redirect HTTP 303 to service management.');
    [$listStatus, $html] = requestPage('public/manage_services.php');
    check($listStatus === 200 && preg_match('/success|created|added|updated|saved|deleted|removed/i', $html), 'Missing success feedback after ' . $action . '.');
}
try {
    $adminPassword = bin2hex(random_bytes(16));
    $insertAdmin = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (:name, :email, :hash, :role)');
    $insertAdmin->execute(['name' => 'Temporary CRUD verifier', 'email' => $adminEmail, 'hash' => password_hash($adminPassword, PASSWORD_DEFAULT), 'role' => 'admin']);
    $adminId = (int) $pdo->lastInsertId();
    [$loginStatus, , $loginHeaders] = requestPage('public/login.php', ['email' => $adminEmail, 'password' => $adminPassword, 'csrf_token' => getToken('public/login.php')]);
    check($loginStatus === 303 && str_contains($loginHeaders['location'] ?? '', 'dashboard.php'), 'Temporary administrator could not log in.');
    $categories = fetchCategories();
    check($categories !== [] && $before !== [], 'At least one existing category and service are needed for this check.');
    $data = [
        'name' => 'Task 3 Check ' . $prefix . ' <b>"spa"</b>',
        'slug' => $testSlugs[0],
        'category_id' => (string) $categories[0]['id'],
        'short_description' => 'Temporary integration service with "quotes" & symbols.',
        'description' => 'Literal text: <script id="task3-xss">alert("test")</script> & "quotes".',
        'price' => '123000', 'duration_min_minutes' => '45', 'duration_max_minutes' => '60',
        'image' => $before[0]['image'], 'is_featured' => '1',
        'csrf_token' => getToken('public/service_form.php'),
    ];
    [$csrfStatus] = requestPage('public/service_form.php', array_replace($data, ['csrf_token' => 'invalid']));
    check($csrfStatus === 403 && findFixture() === null, 'Invalid CSRF must reject creation without inserting a service.');
    [$requiredStatus, $requiredHtml] = requestPage('public/service_form.php', array_replace($data, ['name' => '']));
    check($requiredStatus === 422 && findFixture() === null && str_contains($requiredHtml, '<form'), 'Required-field validation must retain the form without inserting.');
    checkRedirect(requestPage('public/service_form.php', $data), 'creation');
    $fixture = findFixture();
    check($fixture !== null, 'Created service was not saved to MySQL.');
    $fixtureId = (int) $fixture['id'];
    check($fixture['name'] === $data['name'] && $fixture['price'] === $data['price'] && (int) $fixture['is_featured'] === 1, 'Created database values differ from submitted fields.');
    $editPath = 'public/service_form.php?id=' . $fixtureId;
    $deletePath = 'public/delete_service.php?id=' . $fixtureId;
    foreach (['public/index.php', 'pages/item_details.php?service=' . $testSlugs[0], 'public/manage_services.php'] as $page) {
        [$status, $html] = requestPage($page);
        check($status === 200 && str_contains($html, escapeHtml($data['name'])), 'Created service is missing or unescaped on ' . $page);
        check(!str_contains($html, '<script id="task3-xss">') && !str_contains($html, '<b>"spa"</b>'), 'Stored HTML was rendered as markup.');
    }
    $data['csrf_token'] = getToken($editPath);
    [$editStatus, $editHtml] = requestPage($editPath);
    $editDom = parseHtml($editHtml);
    check($editStatus === 200 && $editDom->query('//input[@name="name"]')->item(0)->getAttribute('value') === $data['name'], 'Edit form did not load the saved service.');
    checkRedirect(requestPage($editPath, $data), 'unchanged edit');
    $data['csrf_token'] = getToken($editPath);
    $unchanged = findFixture();
    $invalidCases = [
        'required name' => ['name' => ''], 'name length' => ['name' => str_repeat('a', 151)],
        'slug format' => ['slug' => 'Invalid Slug'], 'duplicate slug' => ['slug' => $before[0]['slug']],
        'unknown category' => ['category_id' => (string) (max(array_column($categories, 'id')) + 1)],
        'image whitelist' => ['image' => '../' . $before[0]['image']],
        'zero price' => ['price' => '0'], 'decimal price' => ['price' => '12.50'], 'price range' => ['price' => '10000000000'],
        'zero duration' => ['duration_min_minutes' => '0'], 'duration range' => ['duration_max_minutes' => '65536'],
        'duration order' => ['duration_min_minutes' => '61', 'duration_max_minutes' => '60'],
        'short description length' => ['short_description' => str_repeat('a', 256)],
        'description length' => ['description' => str_repeat('a', 10001)], 'array input' => ['name' => ['bad']],
    ];
    foreach ($invalidCases as $label => $changes) {
        [$status, $html] = requestPage($editPath, array_replace($data, $changes));
        check($status === 422 && findFixture() === $unchanged && str_contains($html, '<form'), 'Validation failed for ' . $label . '.');
    }
    [$badCsrf] = requestPage($editPath, array_replace($data, ['csrf_token' => 'wrong']));
    check($badCsrf === 403 && findFixture() === $unchanged, 'Invalid CSRF must not update a service.');
    $data = array_replace($data, [
        'name' => 'Updated ' . $data['name'], 'slug' => $testSlugs[1], 'price' => '234000',
        'duration_min_minutes' => '75', 'duration_max_minutes' => '90',
        'short_description' => 'Updated temporary service.', 'csrf_token' => getToken($editPath),
    ]);
    unset($data['is_featured']);
    checkRedirect(requestPage($editPath, $data), 'update');
    $updated = findFixture();
    check($updated['slug'] === $testSlugs[1] && $updated['price'] === '234000' && (int) $updated['is_featured'] === 0, 'Updates or unchecked featured flag were not saved.');
    foreach (['public/index.php', 'pages/item_details.php?service=' . $testSlugs[1]] as $page) {
        [$status, $html] = requestPage($page);
        check($status === 200 && str_contains($html, escapeHtml($data['name'])) && str_contains($html, '234,000 VND') && str_contains($html, '75–90 min'), 'Updated database values are missing on ' . $page);
    }
    [$oldSlugStatus] = requestPage('pages/item_details.php?service=' . $testSlugs[0]);
    check($oldSlugStatus === 404, 'Old slug must no longer resolve after editing.');
    $unknownId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM services')->fetchColumn();
    foreach (['public/service_form.php', 'public/delete_service.php'] as $route) {
        foreach (['?id=0', '?id=-1', '?id%5B%5D=1', '?id=abc'] as $query) {
            [$status] = requestPage($route . $query);
            check($status === 400, 'Malformed service IDs must return HTTP 400.');
        }
        [$unknownStatus] = requestPage($route . '?id=' . $unknownId);
        check($unknownStatus === 404, 'Unknown service ID must return HTTP 404.');
    }
    $deleteToken = getToken($deletePath);
    check(findFixture() === $updated, 'GET deletion confirmation must not delete or change a service.');
    [$missingConfirm] = requestPage($deletePath, ['csrf_token' => $deleteToken]);
    check($missingConfirm === 422 && findFixture() === $updated, 'Deletion requires explicit confirmation.');
    [$deleteCsrf] = requestPage($deletePath, ['csrf_token' => 'wrong', 'confirm_delete' => '1']);
    check($deleteCsrf === 403 && findFixture() === $updated, 'Invalid CSRF must not delete a service.');
    checkRedirect(requestPage($deletePath, ['csrf_token' => getToken($deletePath), 'confirm_delete' => '1']), 'deletion');
    check(findFixture() === null, 'Confirmed deletion did not remove the service.');
    [$deletedStatus] = requestPage('pages/item_details.php?service=' . $testSlugs[1]);
    check($deletedStatus === 404, 'Deleted service must return HTTP 404.');
    [$homeStatus, $homeHtml] = requestPage('public/index.php');
    check($homeStatus === 200 && !str_contains($homeHtml, escapeHtml($data['name'])), 'Deleted service remains in the public catalog.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    // Never delete by a broad prefix: only this run's exact random slugs and known ID.
    $cleanup = $pdo->prepare('DELETE FROM services WHERE slug IN (:created, :updated)' . ($fixtureId === null ? '' : ' AND id = :id'));
    $parameters = ['created' => $testSlugs[0], 'updated' => $testSlugs[1]];
    if ($fixtureId !== null) {
        $parameters['id'] = $fixtureId;
    }
    try {
        $cleanup->execute($parameters);
        if ($adminId !== null) {
            $removeAdmin = $pdo->prepare('DELETE FROM users WHERE id = :id AND email = :email');
            $removeAdmin->execute(['id' => $adminId, 'email' => $adminEmail]);
        }
        check($pdo->query('SELECT * FROM services ORDER BY id')->fetchAll() === $before, 'Existing service rows changed; investigate before continuing.');
        check($pdo->query('SELECT * FROM users ORDER BY id')->fetchAll() === $beforeUsers, 'Existing account rows changed; investigate before continuing.');
    } catch (Throwable $cleanupError) {
        $failure = new RuntimeException('Cleanup or preservation check failed: ' . $cleanupError->getMessage(), 0, $failure);
    }
    curl_close($curl);
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "PASS: $checks checks; create/read/update/delete, validation, CSRF, escaping, redirects, and confirmation.\n";
echo 'Temporary service and administrator removed; all ' . count($before) . " original services and accounts remain unchanged.\n";
