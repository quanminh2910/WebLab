<?php
// Read-only checks against the running Lab 3 website.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function expectPage(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readPage(string $path, int $expectedStatus = 200): DOMXPath
{
    $base = rtrim(getenv('SPA_TEST_BASE_URL') ?: 'http://localhost/Spa%20%26%20Beauty%20Center%20Website/', '/');
    $request = curl_init($base . '/' . $path);
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $html = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    expectPage($html !== false && $status === $expectedStatus, "$path returned HTTP $status.");
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($document);
}

$home = readPage('public/index.php');
expectPage(str_contains($home->evaluate('string(//title)'), 'Massage')
    && $home->evaluate('string(//meta[@name="description"]/@content)') !== ''
    && $home->query('//meta[@name="robots" and contains(@content,"noindex")]')->length === 0, 'Catalog metadata is missing or excluded from indexing.');
expectPage($home->query('//main//h1')->length === 1 && $home->query('//section[@id="items"]//h2')->length === 1,
    'Catalog heading hierarchy is incomplete.');
expectPage($home->query('//nav//a[normalize-space()="Services"]')->length === 1
    && $home->query('//nav//a[@href="#contact"]')->length === 1
    && $home->query('//footer[@id="contact"]')->length === 1, 'Catalog navigation targets are missing.');

$cards = $home->query('//section[@id="items"]//article');
expectPage($cards->length > 0, 'This check needs a service in the catalog.');
foreach ($cards as $card) {
    $name = $home->evaluate('normalize-space(.//h3)', $card);
    $link = $home->query('.//a', $card)->item(0);
    expectPage($link !== null && $name !== '' && str_contains(trim($link->textContent), $name),
        'A service link needs its visible treatment name.');
    $href = $link->getAttribute('href');
    expectPage(str_starts_with($href, '../pages/item_details.php?service='), "Missing local details link for $name.");
    $detail = readPage(substr($href, 3));
    $title = $detail->evaluate('string(//title)');
    $description = $detail->evaluate('string(//meta[@name="description"]/@content)');
    $summary = $home->evaluate('string(.//p[@class="card-text"])', $card);
    $price = $home->evaluate('string(.//p[@class="fw-bold"])', $card);
    expectPage($detail->query('//main//h1')->length === 1 && $detail->evaluate('string(//main//h1)') === $name
        && str_contains($title, $name) && str_contains($title, 'min'), "Incorrect title or main heading for $name.");
    expectPage($summary !== '' && $price !== '' && str_contains($description, $name)
        && str_contains($description, $summary) && str_contains($description, $price), "Description differs from the catalog for $name.");
    expectPage($detail->query('//meta[@name="robots" and contains(@content,"noindex")]')->length === 0,
        "The public service $name should be indexable.");
}

$empty = readPage('public/index.php?q=' . bin2hex(random_bytes(16)));
expectPage($empty->query('//form[@id="search-recovery" and @method="get" and not(@hidden)]//button')->length === 1,
    'The server-rendered empty search needs a reset action.');
$missing = readPage('pages/item_details.php?service=missing-' . bin2hex(random_bytes(10)), 404);
$login = readPage('public/login.php');
foreach ([$empty, $missing, $login] as $page) {
    expectPage($page->query('//meta[@name="robots" and @content="noindex"]')->length === 1,
        'Filtered, missing-service and account pages need their indexing directive.');
}
foreach ([$home, $login, $missing] as $page) {
    expectPage($page->query('//a[@href="#main-content"]')->length === 1
        && $page->query('//main[@id="main-content" and @tabindex="-1"]')->length === 1, 'The skip link must reach the main landmark.');
}
echo "PASS  Catalog and {$cards->length} treatment pages have descriptive metadata, headings and named links.\n";
echo "PASS  Native search recovery, skip links, indexing directives and the missing-service response.\n";
