<?php

// Run against the assignment database and a local server; only disposable fixtures are written.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This verification script is available from the command line only.');
}
require_once __DIR__ . '/../includes/specialist-management.php';

$baseUrl = rtrim(getenv('SPA_TEST_BASE_URL') ?: 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/', '/') . '/';
$url = parse_url($baseUrl);
if (!in_array($url['scheme'] ?? '', ['http', 'https'], true)
    || !in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)) {
    fwrite(STDERR, "Use a local SPA_TEST_BASE_URL.\n");
    exit(1);
}
$pdo = databaseConnection();
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'spa_beauty_center_assignment' && !str_ends_with($database, '_test')) {
    fwrite(STDERR, "Run this check against the assignment database or a database ending in _test.\n");
    exit(1);
}

$checks = 0;
$failure = null;
$fixtureId = null;
$ownedUsers = [];
$prefix = 'specialist-check-' . bin2hex(random_bytes(8));
$fixtureNames = [$prefix, $prefix . '-updated', $prefix . '-rollback'];
$before = specialistSnapshot();
$curl = curl_init();
curl_setopt_array($curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function specialistSnapshot(): array
{
    global $pdo;
    $result = [];
    foreach (['users', 'categories', 'services', 'staff'] as $table) {
        $result[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
    }
    $result['staff_services'] = $pdo->query('SELECT * FROM staff_services ORDER BY staff_id, service_id')->fetchAll();
    return $result;
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
    check($html !== false, 'HTTP request failed: ' . curl_error($curl));
    check(!preg_match('/SQLSTATE|PDOException|Stack trace:|Fatal error:|Warning:|mysql:host=/i', $html), 'Technical error disclosed by ' . $path);
    return [(int) curl_getinfo($curl, CURLINFO_HTTP_CODE), $html, $headers];
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
    $input = parseHtml($html)->query('//input[@name="csrf_token"]')->item(0);
    check($input !== null && strlen($input->getAttribute('value')) >= 32, 'Missing CSRF token.');
    return $input->getAttribute('value');
}

function checkSaved(array $response, string $action): void
{
    [$status, , $headers] = $response;
    check($status === 303 && str_contains($headers['location'] ?? '', 'manage_specialists.php'), $action . ' must redirect to specialist management with HTTP 303.');
}

function findFixture(): ?array
{
    global $pdo, $fixtureId, $fixtureNames;
    if ($fixtureId !== null) {
        return fetchSpecialistById($fixtureId);
    }
    $query = $pdo->prepare('SELECT id FROM staff WHERE name = ?');
    $query->execute([$fixtureNames[0]]);
    $id = $query->fetchColumn();
    return $id === false ? null : fetchSpecialistById((int) $id);
}

function serviceIds(array $profile): array
{
    $ids = array_map('strval', array_column($profile['services'], 'id'));
    sort($ids);
    return $ids;
}

try {
    $services = fetchServices();
    check(count($services) >= 2, 'At least two services are needed for reassignment checks.');
    $firstId = (string) $services[0]['id'];
    $secondId = (string) $services[1]['id'];
    $badServiceId = (string) ((int) $pdo->query('SELECT MAX(id) FROM services')->fetchColumn() + 1);
    $routes = ['public/manage_specialists.php', 'public/specialist_form.php', 'public/specialist_form.php?id=1', 'public/delete_specialist.php?id=1'];
    [$status, $html] = requestPage('public/specialists.php');
    check($status === 200 && str_contains($html, 'Specialists'), 'Public specialist page is unavailable.');
    foreach ($routes as $route) {
        foreach ([null, ['name' => $fixtureNames[0], 'confirm_delete' => '1']] as $payload) {
            [$status, , $headers] = requestPage($route, $payload);
            check(in_array($status, [302, 303], true) && str_contains($headers['location'] ?? '', 'login.php'), 'Anonymous request reached protected route: ' . $route);
        }
    }

    $password = 'Verify-' . bin2hex(random_bytes(16));
    foreach (['customer', 'admin'] as $role) {
        $email = $prefix . '-' . $role . '@example.test';
        $id = createUser(['full_name' => 'Temporary specialist verifier', 'email' => $email], $password, $role);
        $ownedUsers[$id] = $email;
        [$status] = requestPage('public/login.php', ['email' => $email, 'password' => $password, 'csrf_token' => getToken('public/login.php')]);
        check($status === 303, 'Temporary ' . $role . ' could not log in.');
        if ($role === 'customer') {
            foreach ($routes as $route) {
                foreach ([null, ['name' => $fixtureNames[0], 'confirm_delete' => '1']] as $payload) {
                    [$status] = requestPage($route, $payload);
                    check($status === 403, 'Customer reached protected route: ' . $route);
                }
            }
            [$status] = requestPage('public/logout.php', ['csrf_token' => getToken('public/logout.php')]);
            check($status === 303, 'Temporary customer could not log out.');
        }
    }

    $data = ['name' => $fixtureNames[0], 'specialization' => 'Massage & wellness',
        'biography' => 'Literal text: <script id="specialist-xss">alert("test")</script> & "quotes".',
        'photo' => 'default-portrait.svg', 'service_ids' => [$firstId, $secondId], 'csrf_token' => getToken('public/specialist_form.php')];
    [$status] = requestPage('public/specialist_form.php', array_replace($data, ['csrf_token' => 'invalid']));
    check($status === 403 && findFixture() === null, 'Invalid CSRF created a specialist.');
    checkSaved(requestPage('public/specialist_form.php', $data), 'Creation');
    $created = findFixture();
    check($created !== null && $created['biography'] === $data['biography'], 'Created profile was not stored correctly.');
    $fixtureId = (int) $created['id'];
    $expectedIds = [$firstId, $secondId];
    sort($expectedIds);
    check(serviceIds($created) === $expectedIds, 'Creation did not save both service assignments.');
    $editPath = 'public/specialist_form.php?id=' . $fixtureId;
    $deletePath = 'public/delete_specialist.php?id=' . $fixtureId;
    foreach (['public/specialists.php', 'public/manage_specialists.php', $editPath] as $path) {
        [$status, $html] = requestPage($path);
        check($status === 200 && str_contains($html, escapeHtml($data['name'])), 'Saved profile is missing from ' . $path);
        check(!str_contains($html, '<script id="specialist-xss">'), 'Stored biography executed as HTML.');
    }
    [$status, $html] = requestPage('public/specialists.php');
    check(str_contains($html, escapeHtml($data['biography'])) && str_contains($html, 'item_details.php?service=' . $services[0]['slug']), 'Public biography or service link is missing.');
    $data['csrf_token'] = getToken($editPath);
    checkSaved(requestPage($editPath, $data), 'Unchanged edit');
    $unchanged = findFixture();
    foreach ([
        'required name' => ['name' => ''], 'long name' => ['name' => str_repeat('a', 101)],
        'required specialization' => ['specialization' => ''], 'long specialization' => ['specialization' => str_repeat('a', 101)],
        'required biography' => ['biography' => ''], 'long biography' => ['biography' => str_repeat('a', 1001)],
        'photo traversal' => ['photo' => '../logo.png'], 'unknown photo' => ['photo' => 'not-a-portrait.png'],
        'no services' => ['service_ids' => []], 'unknown service' => ['service_ids' => [$badServiceId]],
        'scalar services' => ['service_ids' => $firstId], 'nested service' => ['service_ids' => [[$firstId]]],
        'array name' => ['name' => ['bad']], 'array photo' => ['photo' => ['bad']],
    ] as $label => $change) {
        [$status, $html] = requestPage($editPath, array_replace($data, $change));
        check($status === 422 && str_contains($html, '<form') && findFixture() === $unchanged, 'Validation failed for ' . $label . '.');
    }
    [$status] = requestPage($editPath, array_replace($data, ['csrf_token' => 'wrong']));
    check($status === 403 && findFixture() === $unchanged, 'Invalid CSRF changed a specialist.');

    $data = array_replace($data, ['name' => $fixtureNames[1], 'biography' => 'Updated biography.', 'service_ids' => [$secondId], 'csrf_token' => getToken($editPath)]);
    checkSaved(requestPage($editPath, $data), 'Update');
    $updated = findFixture();
    check($updated['name'] === $data['name'] && $updated['biography'] === $data['biography'] && serviceIds($updated) === [$secondId], 'Update failed to replace profile fields and service assignments.');
    check(specialistPhotoUrl('missing-' . $prefix . '.jpg') === specialistPhotoUrl('default-portrait.svg'), 'Missing portrait did not use the default.');
    check(specialistPhotoUrl('../logo.png') === specialistPhotoUrl('default-portrait.svg'), 'Portrait traversal did not use the default.');

    // Foreign-key failures must roll back both the profile and its assignments.
    $rollbackData = array_replace($data, ['name' => $fixtureNames[2], 'service_ids' => [$firstId, $badServiceId]]);
    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
    try {
        createSpecialist($rollbackData);
        throw new RuntimeException('Create unexpectedly accepted an unavailable service.');
    } catch (PDOException $error) {
        check(!$pdo->inTransaction() && (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn() === $countBefore, 'Failed create left a profile or transaction behind.');
    }
    try {
        updateSpecialist($fixtureId, $rollbackData);
        throw new RuntimeException('Update unexpectedly accepted an unavailable service.');
    } catch (PDOException $error) {
        check(!$pdo->inTransaction() && findFixture() === $updated, 'Failed update did not restore the original profile and assignments.');
    }

    $unknownId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM staff')->fetchColumn();
    foreach (['public/specialist_form.php', 'public/delete_specialist.php'] as $route) {
        foreach (['?id=0', '?id=-1', '?id=abc', '?id%5B%5D=1'] as $query) {
            [$status] = requestPage($route . $query);
            check($status === 400, 'Malformed specialist ID was accepted.');
        }
        [$status] = requestPage($route . '?id=' . $unknownId);
        check($status === 404, 'Unknown specialist ID must return HTTP 404.');
    }
    $token = getToken($deletePath);
    check(findFixture() === $updated, 'Opening confirmation changed the profile.');
    [$status] = requestPage($deletePath, ['csrf_token' => $token]);
    check($status === 422 && findFixture() === $updated, 'Deletion did not require confirmation.');
    [$status] = requestPage($deletePath, ['csrf_token' => 'wrong', 'confirm_delete' => '1']);
    check($status === 403 && findFixture() === $updated, 'Deletion did not verify CSRF.');
    checkSaved(requestPage($deletePath, ['csrf_token' => getToken($deletePath), 'confirm_delete' => '1']), 'Deletion');
    check(findFixture() === null, 'Confirmed deletion left the profile in the database.');
    $links = $pdo->prepare('SELECT COUNT(*) FROM staff_services WHERE staff_id = ?');
    $links->execute([$fixtureId]);
    check((int) $links->fetchColumn() === 0, 'Deletion left specialist service assignments behind.');
    [$status, $html] = requestPage('public/specialists.php');
    check($status === 200 && !str_contains($html, escapeHtml($data['name'])), 'Deleted specialist remains publicly visible.');
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Exact random fixture names and account IDs only; never remove existing records.
        $removeProfiles = $pdo->prepare('DELETE FROM staff WHERE name IN (?, ?, ?)');
        $removeProfiles->execute($fixtureNames);
        $removeUser = $pdo->prepare('DELETE FROM users WHERE id = ? AND email = ?');
        foreach ($ownedUsers as $id => $email) {
            $removeUser->execute([$id, $email]);
        }
        check(specialistSnapshot() === $before, 'Original accounts, services, specialists, or assignments changed.');
    } catch (Throwable $cleanupError) {
        $failure = new RuntimeException('Cleanup/preservation failed: ' . $cleanupError->getMessage(), 0, $failure);
    }
    curl_close($curl);
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "PASS: $checks checks; specialist CRUD, service assignments, permissions, validation, CSRF, escaping, portrait fallback, and transaction rollback.\n";
echo "Disposable fixtures removed; all original database records remain unchanged.\n";
