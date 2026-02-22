<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function send_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function clean_text($value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }

    $trimmed = trim($value);
    return $trimmed === '' ? $fallback : $trimmed;
}

function build_fallback_data_uri(string $prompt): string
{
    $title = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
    if ($title === '') {
        $title = 'Image preview unavailable';
    }
    if (strlen($title) > 80) {
        $title = substr($title, 0, 77) . '...';
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">' .
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#03151f"/><stop offset="100%" stop-color="#0f172a"/></linearGradient></defs>' .
        '<rect width="1280" height="720" fill="url(#g)"/>' .
        '<circle cx="120" cy="130" r="180" fill="rgba(6,182,212,0.25)"/>' .
        '<circle cx="1080" cy="620" r="240" fill="rgba(16,185,129,0.2)"/>' .
        '<rect x="86" y="90" width="1108" height="540" rx="24" fill="rgba(2,6,23,0.58)" stroke="rgba(110,231,255,0.45)" stroke-width="2"/>' .
        '<text x="130" y="205" fill="#99f6e4" font-family="monospace" font-size="32">MULTIMODAL IMAGE FALLBACK</text>' .
        '<text x="130" y="295" fill="#d1fae5" font-family="monospace" font-size="38">' . $safeTitle . '</text>' .
        '<text x="130" y="375" fill="#67e8f9" font-family="monospace" font-size="25">OpenAI image provider unavailable or timed out.</text>' .
        '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

function fetch_binary_image_data_uri(string $url, int $timeoutSeconds): string
{
    if (!preg_match('/^https?:\/\//i', $url)) {
        return '';
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSeconds),
        CURLOPT_HTTPHEADER => [
            'Accept: image/*,*/*;q=0.8',
            'User-Agent: MySite-ImageEndpoint/1.0'
        ]
    ]);

    $binary = curl_exec($curl);
    if ($binary === false) {
        curl_close($curl);
        return '';
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    curl_close($curl);

    if ($statusCode >= 400 || $binary === '') {
        return '';
    }

    $mime = trim(explode(';', $contentType)[0] ?? '');
    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        $mime = 'image/png';
    }

    $encoded = base64_encode((string) $binary);
    if ($encoded === '') {
        return '';
    }

    return 'data:' . $mime . ';base64,' . $encoded;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody ?: '{}', true);
if (!is_array($decoded)) {
    send_json(400, ['error' => 'Invalid JSON request body']);
}

$prompt = clean_text($decoded['prompt'] ?? '');
if ($prompt === '') {
    send_json(400, ['error' => 'Missing prompt']);
}

$config = mysite_load_server_config();
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];
$enabled = (bool) ($openAiConfig['enabled'] ?? true);
$apiKey = mysite_resolve_openai_api_key($config);

$timeoutSeconds = (int) ($openAiConfig['timeout_seconds'] ?? 30);
if ($timeoutSeconds < 8) {
    $timeoutSeconds = 8;
}
if ($timeoutSeconds > 120) {
    $timeoutSeconds = 120;
}

if (!$enabled || $apiKey === '') {
    send_json(503, [
        'ok' => false,
        'error' => 'OpenAI image provider is not configured.',
        'imageDataUrl' => build_fallback_data_uri($prompt),
        'provider' => 'fallback',
        'model' => 'placeholder'
    ]);
}

$imageModel = clean_text($openAiConfig['image_model'] ?? 'gpt-image-1', 'gpt-image-1');
$imageApiUrl = clean_text($openAiConfig['images_api_url'] ?? 'https://api.openai.com/v1/images/generations', 'https://api.openai.com/v1/images/generations');

$payload = [
    'model' => $imageModel,
    'prompt' => $prompt,
    'size' => '1024x1024'
];

$curl = curl_init($imageApiUrl);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => $timeoutSeconds
]);

$result = curl_exec($curl);
if ($result === false) {
    curl_close($curl);
    send_json(502, [
        'ok' => false,
        'error' => 'Image generation provider request failed.',
        'imageDataUrl' => build_fallback_data_uri($prompt),
        'provider' => 'fallback',
        'model' => 'placeholder'
    ]);
}

$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($statusCode >= 400) {
    send_json(502, [
        'ok' => false,
        'error' => 'Image generation provider returned an error.',
        'imageDataUrl' => build_fallback_data_uri($prompt),
        'provider' => 'fallback',
        'model' => 'placeholder'
    ]);
}

$decodedResult = json_decode((string) $result, true);
$data = is_array($decodedResult['data'] ?? null) ? $decodedResult['data'] : [];
$first = is_array($data[0] ?? null) ? $data[0] : [];
$url = clean_text($first['url'] ?? '');
$b64 = clean_text($first['b64_json'] ?? '');

$imageDataUrl = '';
if ($b64 !== '') {
    $imageDataUrl = 'data:image/png;base64,' . $b64;
} elseif ($url !== '') {
    $imageDataUrl = fetch_binary_image_data_uri($url, $timeoutSeconds);
}

if ($imageDataUrl === '') {
    send_json(502, [
        'ok' => false,
        'error' => 'Image provider returned no usable image payload.',
        'imageDataUrl' => build_fallback_data_uri($prompt),
        'provider' => 'fallback',
        'model' => 'placeholder'
    ]);
}

send_json(200, [
    'ok' => true,
    'imageDataUrl' => $imageDataUrl,
    'provider' => 'openai',
    'model' => $imageModel
]);
