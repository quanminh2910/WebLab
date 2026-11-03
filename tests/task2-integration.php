<?php

// Read-only by default. --check-live-update briefly changes one price and restores it.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This verification script is available from the command line only.');
}
require_once __DIR__ . '/../includes/services.php';
$baseUrl = rtrim(getenv('SPA_TEST_BASE_URL') ?: 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/', '/') . '/';
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function checkSafe(string $html): void
{
    check(!preg_match('/SQLSTATE|PDOException|Stack trace:|Fatal error:|Warning:|mysql:host=/i', $html), 'Technical errors leaked into a public response.');
}
function requestPage(string $path): array
{
    global $baseUrl;
    $curl = curl_init($baseUrl . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
    $html = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    check($html !== false, 'HTTP request failed: ' . $error);
    checkSafe($html);
    return [$status, $html];
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
function checkValues(DOMNode $node, array $values): void
{
    foreach ($values as $value) {
        check(str_contains($node->textContent, $value), 'Missing displayed database value: ' . $value);
    }
}
function guardedPriceChange(PDO $pdo, array $expected, string $price): void
{
    $guards = [];
    $parameters = ['replacement_price' => $price, 'replacement_updated' => $expected['updated_at']];
    foreach ($expected as $column => $value) {
        $guards[] = '`' . $column . '` <=> :guard_' . $column;
        $parameters['guard_' . $column] = $value;
    }
    $statement = $pdo->prepare('UPDATE services SET price = :replacement_price, updated_at = :replacement_updated WHERE ' . implode(' AND ', $guards));
    $statement->execute($parameters);
    check($statement->rowCount() === 1, 'A concurrent database edit prevented the guarded price change/restoration. Inspect the Swedish Massage row.');
}
try {
    $services = fetchServices();
    $categories = fetchCategories();
    [$status, $homeHtml] = requestPage('public/index.php');
    check($status === 200, 'Homepage must return HTTP 200.');
    $home = parseHtml($homeHtml);
    $cards = $home->query('//*[contains(concat(" ", normalize-space(@class), " "), " service-card ")]');
    check($cards->length === count($services), 'Homepage card count differs from the database.');
    $filters = $home->query('//button[contains(concat(" ", normalize-space(@class), " "), " category-filter ")]');
    $filterSlugs = array_map(fn($button) => $button->getAttribute('data-category'), iterator_to_array($filters));
    check($filterSlugs === ($services ? array_merge(['all'], array_column($categories, 'slug')) : []), 'Category controls differ from the database.');
    foreach ($services as $index => $service) {
        $card = $cards->item($index);
        checkValues($card, [$service['name'], $service['short_description'], formatServicePrice($service), formatServiceDuration($service)]);
        check($card->getAttribute('data-category') === $service['category_slug'], 'Wrong category on a service card.');
        check($home->query('.//img', $card)->item(0)->getAttribute('src') === 'images/' . rawurlencode(basename($service['image'])), 'Wrong service-card image.');
        [$detailStatus, $detailHtml] = requestPage('pages/item_details.php?service=' . rawurlencode($service['slug']));
        check($detailStatus === 200, 'Service details must return HTTP 200: ' . $service['slug']);
        $detail = parseHtml($detailHtml);
        checkValues($detail->query('//main')->item(0), [$service['name'], $service['category_name'], $service['description'], formatServicePrice($service), formatServiceDuration($service)]);
        check($detail->query('//main//img')->item(0)->getAttribute('src') === '../public/images/' . rawurlencode(basename($service['image'])), 'Wrong details image.');
    }
    $featured = array_values(array_filter($services, fn($service) => (int) $service['is_featured'] === 1));
    $featuredTitle = $home->query('//*[@id="featured-service-title"]');
    check($featuredTitle->length === ($featured ? 1 : 0), 'Featured section must follow the database featured flag.');
    if ($featured) {
        check(trim($featuredTitle->item(0)->textContent) === $featured[0]['name'], 'Wrong featured service.');
    }
    [$defaultStatus] = requestPage('pages/item_details.php');
    check($defaultStatus === (fetchServiceBySlug('swedish-massage') ? 200 : 404), 'Default details route must preserve Swedish Massage compatibility.');
    foreach (['?service=', '?service%5B%5D=x', '?service=' . rawurlencode("' OR 1=1 --"), '?service=' . rawurlencode('<script>alert(1)</script>'), '?service=' . str_repeat('x', 151)] as $query) {
        [$badStatus] = requestPage('pages/item_details.php' . $query);
        check(in_array($badStatus, [400, 404], true), 'Malformed service requests must return HTTP 400 or 404.');
    }
    [$unknownStatus] = requestPage('pages/item_details.php?service=unknown-' . bin2hex(random_bytes(8)));
    check($unknownStatus === 404, 'Unknown services must return HTTP 404.');
    foreach (['public/index.php', 'pages/item_details.php'] as $page) {
        $childCode = 'putenv(' . var_export('DB_NAME=spa_task2_missing_' . bin2hex(random_bytes(8)), true) . '); '
            . '$_SERVER["SCRIPT_NAME"] = ' . var_export('/' . $page, true) . '; $_GET = ["service" => "swedish-massage"]; '
            . 'ob_start(); require ' . var_export(dirname(__DIR__) . '/' . $page, true) . '; $body = ob_get_clean(); echo http_response_code(), "\n", $body;';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $childCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        check(is_resource($process), 'Could not launch the isolated database-outage check.');
        $result = stream_get_contents($pipes[1]);
        $technicalLog = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'Outage check process failed.');
        [$outageStatus, $outageHtml] = array_pad(explode("\n", $result, 2), 2, '');
        check($outageStatus === '503', 'Database outage must render HTTP 503: ' . $page);
        checkSafe($outageHtml);
        check(strlen(strip_tags($outageHtml)) > 30, 'Database outage page needs a helpful message.');
    }
    if (in_array('--check-live-update', $argv, true)) {
        $pdo = databaseConnection();
        $select = $pdo->prepare('SELECT * FROM services WHERE slug = :slug');
        $select->execute(['slug' => 'swedish-massage']);
        $original = $select->fetch();
        check($original !== false, 'Swedish Massage is required for the optional live-update check.');
        $testPrice = (string) ((int) $original['price'] + 1000);
        guardedPriceChange($pdo, $original, $testPrice);
        $expected = array_replace($original, ['price' => $testPrice]);
        try {
            foreach (['public/index.php', 'pages/item_details.php?service=swedish-massage'] as $page) {
                [$liveStatus, $liveHtml] = requestPage($page);
                check($liveStatus === 200 && str_contains($liveHtml, formatServicePrice($expected)), 'Database price edit was not reflected on ' . $page);
            }
        } finally {
            guardedPriceChange($pdo, $expected, (string) $original['price']);
            $select->execute(['slug' => 'swedish-massage']);
            check($select->fetch() === $original, 'The original service row was not restored exactly.');
        }
        echo "Live database update reflected on homepage and details; original row restored exactly.\n";
    }
    echo "PASS: $checks checks; " . count($services) . " services; " . count($categories) . " categories.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
