<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$rawUrl = trim((string) ($_GET['url'] ?? ''));
if ($rawUrl === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Missing image URL'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!preg_match('/^https:\/\//i', $rawUrl)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Only HTTPS image URLs are allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$parts = parse_url($rawUrl);
if (!is_array($parts)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid URL'], JSON_UNESCAPED_SLASHES);
    exit;
}

$host = strtolower(trim((string) ($parts['host'] ?? '')));
$allowedHosts = [
    'image.pollinations.ai',
    'picsum.photos',
    'files.oaiusercontent.com',
    'oaidalleapiprodscus.blob.core.windows.net'
];

if (!in_array($host, $allowedHosts, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Host not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

$curl = curl_init($rawUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
        'Accept: image/*,*/*;q=0.8',
        'User-Agent: MySite-ImageProxy/1.0'
    ]
]);

$binary = curl_exec($curl);
if ($binary === false) {
    curl_close($curl);
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unable to fetch image source'], JSON_UNESCAPED_SLASHES);
    exit;
}

$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
curl_close($curl);

if ($statusCode >= 400 || $binary === '') {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Image source returned an error'], JSON_UNESCAPED_SLASHES);
    exit;
}

$mime = trim(explode(';', $contentType)[0] ?? '');
if ($mime === '' || strpos($mime, 'image/') !== 0) {
    http_response_code(415);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Upstream did not return an image'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $binary;
