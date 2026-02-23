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

function normalize_visitor_id(array $decoded): string
{
    $raw = trim((string) ($decoded['visitorId'] ?? ''));
    if ($raw === '') {
        return 'visitor-anonymous';
    }

    $sanitized = preg_replace('/[^a-zA-Z0-9._:-]/', '', $raw) ?? '';
    if ($sanitized === '') {
        return 'visitor-anonymous';
    }

    return substr($sanitized, 0, 80);
}

function normalize_variant_nonce(array $decoded): int
{
    $raw = $decoded['variantNonce'] ?? 0;
    if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
        return 0;
    }

    $value = (int) $raw;
    if ($value < 0) {
        return 0;
    }

    if ($value > 1000000) {
        return 1000000;
    }

    return $value;
}

function normalize_topics($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $topics = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $topic = trim($item);
        if ($topic === '') {
            continue;
        }

        $topics[] = substr($topic, 0, 40);
        if (count($topics) >= 4) {
            break;
        }
    }

    return $topics;
}

function extract_json_object(string $input): ?array
{
    $trimmed = trim($input);
    if (substr($trimmed, 0, 3) === '```') {
        $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        $trimmed = trim($trimmed);
    }

    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($trimmed, '{');
    $end = strrpos($trimmed, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $slice = substr($trimmed, $start, $end - $start + 1);
    $decoded = json_decode($slice, true);
    return is_array($decoded) ? $decoded : null;
}

function hash32(string $value): int
{
    $hash = 2166136261;
    $length = strlen($value);
    for ($index = 0; $index < $length; $index++) {
        $hash ^= ord($value[$index]);
        $hash = ($hash * 16777619) & 0xffffffff;
    }
    return $hash;
}

function local_prompt_fallback(string $seed, array $topics): string
{
    $subjects = [
        'desert skyline',
        'floating observatory district',
        'futuristic city canyon',
        'orbital market avenue',
        'holographic innovation campus',
        'cinematic mountain metropolis',
        'coastal cyberpunk boardwalk',
        'solar-lit dune outpost'
    ];
    $styles = [
        'cinematic',
        'ultra-detailed',
        'editorial wide-angle',
        'photorealistic concept art',
        'dynamic high-contrast'
    ];
    $moods = [
        'sunrise glow',
        'storm-lit horizon',
        'moonlit atmosphere',
        'neon twilight',
        'golden hour haze',
        'aurora dusk'
    ];
    $details = [
        'volumetric lighting',
        'atmospheric depth',
        'sharp foreground detail',
        'dramatic cloud formations',
        'reflective surfaces and haze'
    ];

    $topicHint = count($topics) > 0 ? implode(', ', array_slice($topics, 0, 2)) : 'creative exploration';
    $hash = hash32($seed . ':' . microtime(true));
    $subject = $subjects[$hash % count($subjects)];
    $style = $styles[($hash >> 4) % count($styles)];
    $mood = $moods[($hash >> 8) % count($moods)];
    $detail = $details[($hash >> 12) % count($details)];

    return trim($style . ' ' . $subject . ' at ' . $mood . ', inspired by ' . $topicHint . ', ' . $detail . ', 8k composition');
}

function clean_prompt_output(string $value): string
{
    $cleaned = preg_replace('/\s+/', ' ', $value) ?? '';
    $cleaned = trim($cleaned, " \t\n\r\0\x0B\"'");
    if ($cleaned === '') {
        return '';
    }
    return substr($cleaned, 0, 220);
}

function prompt_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['prompt'],
        'properties' => [
            'prompt' => ['type' => 'string']
        ]
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode($rawBody ?: '{}', true);
if (!is_array($decodedBody)) {
    send_json(400, ['error' => 'Invalid JSON request body']);
}

$visitorId = normalize_visitor_id($decodedBody);
$variantNonce = normalize_variant_nonce($decodedBody);
$topics = normalize_topics($decodedBody['topics'] ?? []);
$seed = $visitorId . ':v' . (string) $variantNonce . ':' . implode('|', $topics);

$config = mysite_load_server_config();
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];
$enabled = (bool) ($openAiConfig['enabled'] ?? true);
$apiKey = mysite_resolve_openai_api_key($config);

if (!$enabled || $apiKey === '') {
    send_json(200, [
        'prompt' => local_prompt_fallback($seed, $topics),
        'source' => 'local'
    ]);
}

$model = clean_text($openAiConfig['model'] ?? 'gpt-4o-mini', 'gpt-4o-mini');
$apiUrl = clean_text($openAiConfig['api_url'] ?? 'https://api.openai.com/v1/chat/completions', 'https://api.openai.com/v1/chat/completions');
$timeoutSeconds = (int) ($openAiConfig['timeout_seconds'] ?? 20);
if ($timeoutSeconds < 8) {
    $timeoutSeconds = 8;
}
if ($timeoutSeconds > 60) {
    $timeoutSeconds = 60;
}

$systemPrompt = <<<PROMPT
You generate one image prompt for a multimodal assistant.
Output JSON only: {"prompt":"..."}.
Rules:
- Keep it one sentence, 12-28 words.
- Make it vivid, specific, and visually strong.
- Include composition/style words and lighting/mood.
- No explanations, no markdown, no list.
PROMPT;

$userPrompt = "Visitor seed: " . $seed .
    "\nTopics: " . json_encode($topics, JSON_UNESCAPED_SLASHES) .
    "\nCreate a unique image prompt now.";

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ],
    'temperature' => 0.9,
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'image_prompt_response',
            'strict' => true,
            'schema' => prompt_schema()
        ]
    ]
];

$curl = curl_init($apiUrl);
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
    send_json(200, [
        'prompt' => local_prompt_fallback($seed, $topics),
        'source' => 'local'
    ]);
}

$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if ($statusCode >= 400) {
    send_json(200, [
        'prompt' => local_prompt_fallback($seed, $topics),
        'source' => 'local'
    ]);
}

$responseJson = json_decode((string) $result, true);
$content = $responseJson['choices'][0]['message']['content'] ?? null;
if (!is_string($content) || trim($content) === '') {
    send_json(200, [
        'prompt' => local_prompt_fallback($seed, $topics),
        'source' => 'local'
    ]);
}

$parsed = extract_json_object($content);
$prompt = clean_prompt_output((string) ($parsed['prompt'] ?? ''));
if ($prompt === '') {
    send_json(200, [
        'prompt' => local_prompt_fallback($seed, $topics),
        'source' => 'local'
    ]);
}

send_json(200, [
    'prompt' => $prompt,
    'source' => 'backend'
]);
