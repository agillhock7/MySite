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

function normalize_transcript($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $entries = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $role = (string) ($item['role'] ?? 'user');
        if (!in_array($role, ['system', 'assistant', 'user'], true)) {
            $role = 'user';
        }

        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $entries[] = [
            'role' => $role,
            'text' => $text
        ];
    }

    if (count($entries) > 16) {
        $entries = array_slice($entries, -16);
    }

    return $entries;
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

function assistant_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['assistantMessage', 'suggestions', 'media'],
        'properties' => [
            'assistantMessage' => ['type' => 'string'],
            'suggestions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['label', 'action'],
                    'properties' => [
                        'label' => ['type' => 'string'],
                        'action' => ['type' => 'string']
                    ]
                ]
            ],
            'media' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['type', 'url', 'alt'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['image']],
                        'url' => ['type' => 'string'],
                        'alt' => ['type' => 'string']
                    ]
                ]
            ]
        ]
    ];
}

function sanitize_action(string $action): string
{
    $value = trim($action);
    if ($value === '') {
        return '';
    }

    if (strpos($value, '/') === 0) {
        return $value;
    }

    $inlineActions = ['ask-hosting', 'ask-ai-access', 'reopen-onboarding'];
    if (in_array($value, $inlineActions, true)) {
        return $value;
    }

    if (!preg_match('/^https?:\/\//i', $value)) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $allowedHosts = [
        'hiops.darkhorsevirtue.io',
        'alexanderjgill.com',
        'my.alexanderjgill.com'
    ];

    if (!in_array($host, $allowedHosts, true)) {
        return '';
    }

    return $value;
}

function normalize_suggestions($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string) ($item['label'] ?? ''));
        $action = sanitize_action((string) ($item['action'] ?? ''));
        if ($label === '' || $action === '') {
            continue;
        }

        $normalized[] = [
            'label' => $label,
            'action' => $action
        ];

        if (count($normalized) >= 4) {
            break;
        }
    }

    return $normalized;
}

function normalize_media($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = strtolower(clean_text($item['type'] ?? ''));
        $url = clean_text($item['url'] ?? '');
        $alt = clean_text($item['alt'] ?? '', 'Generated image');
        if ($type !== 'image' || $url === '') {
            continue;
        }

        if (!preg_match('/^https?:\/\//i', $url) && strpos($url, 'data:image/') !== 0) {
            continue;
        }

        $normalized[] = [
            'type' => 'image',
            'url' => $url,
            'alt' => $alt
        ];

        if (count($normalized) >= 2) {
            break;
        }
    }

    return $normalized;
}

function is_image_request(string $message): bool
{
    return preg_match('/\b(image|illustration|render|draw|logo|poster|photo|artwork|cover art)\b/i', $message) === 1;
}

function assistant_image_placeholder_data_uri(string $prompt): string
{
    $title = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
    if ($title === '') {
        $title = 'Image pending';
    }
    if (strlen($title) > 80) {
        $title = substr($title, 0, 77) . '...';
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">' .
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#031b14"/><stop offset="100%" stop-color="#0f172a"/></linearGradient></defs>' .
        '<rect width="1280" height="720" fill="url(#g)"/>' .
        '<circle cx="120" cy="130" r="170" fill="rgba(16,185,129,0.24)"/>' .
        '<circle cx="1060" cy="620" r="240" fill="rgba(34,197,94,0.18)"/>' .
        '<rect x="86" y="90" width="1108" height="540" rx="24" fill="rgba(2,6,23,0.54)" stroke="rgba(110,231,183,0.45)" stroke-width="2"/>' .
        '<text x="130" y="205" fill="#a7f3d0" font-family="monospace" font-size="32">MULTIMODAL IMAGE PREVIEW</text>' .
        '<text x="130" y="295" fill="#d1fae5" font-family="monospace" font-size="40">' . $safeTitle . '</text>' .
        '<text x="130" y="375" fill="#86efac" font-family="monospace" font-size="26">Live generation unavailable: using fallback preview.</text>' .
        '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

function assistant_external_image_url(string $prompt): string
{
    $normalizedPrompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
    if ($normalizedPrompt === '') {
        $normalizedPrompt = 'futuristic abstract composition';
    }

    $seed = (string) time() . '-' . substr(sha1($normalizedPrompt), 0, 8);
    return 'https://image.pollinations.ai/prompt/' . rawurlencode($normalizedPrompt)
        . '?width=1024&height=1024&nologo=true&enhance=true&seed=' . rawurlencode($seed);
}

function assistant_fetch_image_as_data_uri(string $url, int $timeoutSeconds): string
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
            'User-Agent: MySite-Assistant/1.0'
        ]
    ]);

    $binary = curl_exec($curl);
    if ($binary === false) {
        curl_close($curl);
        return '';
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
    curl_close($curl);

    if ($statusCode >= 400 || $binary === '') {
        return '';
    }

    $mime = strtolower(explode(';', $contentType)[0] ?? '');
    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        $mime = 'image/jpeg';
    }

    $encoded = base64_encode((string) $binary);
    if ($encoded === '') {
        return '';
    }

    return 'data:' . $mime . ';base64,' . $encoded;
}

function assistant_generate_image(string $prompt, array $config, int $timeoutSeconds): array
{
    $openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];
    $enabled = (bool) ($openAiConfig['enabled'] ?? true);
    $apiKey = mysite_resolve_openai_api_key($config);

    if (!$enabled || $apiKey === '') {
        $externalUrl = assistant_external_image_url($prompt);
        $externalDataUri = assistant_fetch_image_as_data_uri($externalUrl, $timeoutSeconds);
        return [
            'url' => $externalDataUri !== '' ? $externalDataUri : assistant_image_placeholder_data_uri($prompt),
            'source' => $externalDataUri !== '' ? 'external' : 'local',
            'provider' => $externalDataUri !== '' ? 'pollinations' : 'fallback',
            'model' => $externalDataUri !== '' ? 'pollinations' : 'placeholder'
        ];
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
        $externalUrl = assistant_external_image_url($prompt);
        $externalDataUri = assistant_fetch_image_as_data_uri($externalUrl, $timeoutSeconds);
        return [
            'url' => $externalDataUri !== '' ? $externalDataUri : assistant_image_placeholder_data_uri($prompt),
            'source' => $externalDataUri !== '' ? 'external' : 'local',
            'provider' => $externalDataUri !== '' ? 'pollinations' : 'fallback',
            'model' => $externalDataUri !== '' ? 'pollinations' : 'placeholder'
        ];
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($statusCode >= 400) {
        $externalUrl = assistant_external_image_url($prompt);
        $externalDataUri = assistant_fetch_image_as_data_uri($externalUrl, $timeoutSeconds);
        return [
            'url' => $externalDataUri !== '' ? $externalDataUri : assistant_image_placeholder_data_uri($prompt),
            'source' => $externalDataUri !== '' ? 'external' : 'local',
            'provider' => $externalDataUri !== '' ? 'pollinations' : 'fallback',
            'model' => $externalDataUri !== '' ? 'pollinations' : 'placeholder'
        ];
    }

    $decoded = json_decode((string) $result, true);
    $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $first = is_array($data[0] ?? null) ? $data[0] : [];
    $url = clean_text($first['url'] ?? '');
    $b64 = clean_text($first['b64_json'] ?? '');

    if ($url === '' && $b64 !== '') {
        $url = 'data:image/png;base64,' . $b64;
    }
    if ($url === '') {
        $externalUrl = assistant_external_image_url($prompt);
        $externalDataUri = assistant_fetch_image_as_data_uri($externalUrl, $timeoutSeconds);
        return [
            'url' => $externalDataUri !== '' ? $externalDataUri : assistant_image_placeholder_data_uri($prompt),
            'source' => $externalDataUri !== '' ? 'external' : 'local',
            'provider' => $externalDataUri !== '' ? 'pollinations' : 'fallback',
            'model' => $externalDataUri !== '' ? 'pollinations' : 'placeholder'
        ];
    }

    return [
        'url' => $url,
        'source' => 'backend',
        'provider' => 'openai',
        'model' => $imageModel
    ];
}

function local_assistant_fallback(string $userMessage): array
{
    $normalized = strtolower($userMessage);

    if (preg_match('/host|hosting|server|domain|pro suite|dark horse|whmcs/', $normalized) === 1) {
        return [
            'assistantMessage' => 'For managed hosting and onboarding, Dark Horse Virtue Pro Suite is the fastest path. I can walk you through setup, migration, and next steps.',
            'suggestions' => [
                ['label' => 'Open Pro Suite', 'action' => 'https://hiops.darkhorsevirtue.io'],
                ['label' => 'Ask AI Access', 'action' => 'ask-ai-access'],
                ['label' => 'Refine Experience', 'action' => '/onboarding?force=1']
            ],
            'media' => [],
            'source' => 'local'
        ];
    }

    if (preg_match('/ai|automation|assistant|agent|prompt/', $normalized) === 1) {
        return [
            'assistantMessage' => 'I can help map an AI access plan by use-case, rollout order, and budget. If you want, we can pair it with hosting setup in Pro Suite.',
            'suggestions' => [
                ['label' => 'Open Pro Suite', 'action' => 'https://hiops.darkhorsevirtue.io'],
                ['label' => 'Ask Hosting', 'action' => 'ask-hosting'],
                ['label' => 'Open Main Site', 'action' => 'https://alexanderjgill.com']
            ],
            'media' => [],
            'source' => 'local'
        ];
    }

    return [
        'assistantMessage' => 'Share your goal and I will route you to hosting, AI access, or a UX refinement path.',
        'suggestions' => [
            ['label' => 'Dark Horse Virtue', 'action' => 'https://hiops.darkhorsevirtue.io'],
            ['label' => 'Open Main Site', 'action' => 'https://alexanderjgill.com'],
            ['label' => 'Refine Experience', 'action' => '/onboarding?force=1']
        ],
        'media' => [],
        'source' => 'local'
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

$userMessage = trim((string) ($decodedBody['userMessage'] ?? ''));
if ($userMessage === '') {
    send_json(400, ['error' => 'Missing userMessage']);
}

$transcript = normalize_transcript($decodedBody['transcript'] ?? []);
$visitorId = normalize_visitor_id($decodedBody);
$variantNonce = normalize_variant_nonce($decodedBody);
$visitorSeed = substr(sha1($visitorId . ':v' . (string) $variantNonce), 0, 10);

$config = mysite_load_server_config();
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];
$timeoutSeconds = (int) ($openAiConfig['timeout_seconds'] ?? 30);
if ($timeoutSeconds < 5) {
    $timeoutSeconds = 5;
}
if ($timeoutSeconds > 120) {
    $timeoutSeconds = 120;
}

if (is_image_request($userMessage)) {
    $image = assistant_generate_image($userMessage, $config, $timeoutSeconds);
    $assistantMessage = 'Image generated in-thread. Ask for edits, styles, or a new variation.';
    if (($image['source'] ?? '') === 'external') {
        $assistantMessage = 'Image generated via external runtime in-thread. Ask for style, angle, lighting, or mood changes.';
    } elseif (($image['source'] ?? '') !== 'backend') {
        $assistantMessage = 'Image preview generated in fallback mode. Configure OpenAI image access for first-party renders.';
    }

    send_json(200, [
        'assistantMessage' => $assistantMessage,
        'suggestions' => [
            ['label' => 'Refine Image Prompt', 'action' => 'ask-ai-access'],
            ['label' => 'Open Main Site', 'action' => 'https://alexanderjgill.com'],
            ['label' => 'Open Pro Suite', 'action' => 'https://hiops.darkhorsevirtue.io']
        ],
        'media' => [
            [
                'type' => 'image',
                'url' => (string) $image['url'],
                'alt' => 'Generated image preview'
            ]
        ],
        'source' => (string) $image['source']
    ]);
}

$enabled = (bool) ($openAiConfig['enabled'] ?? true);
$apiKey = mysite_resolve_openai_api_key($config);

if (!$enabled || $apiKey === '') {
    send_json(200, local_assistant_fallback($userMessage));
}

$model = trim((string) ($openAiConfig['model'] ?? 'gpt-4o-mini'));
if ($model === '') {
    $model = 'gpt-4o-mini';
}

$apiUrl = trim((string) ($openAiConfig['api_url'] ?? 'https://api.openai.com/v1/chat/completions'));
if ($apiUrl === '') {
    $apiUrl = 'https://api.openai.com/v1/chat/completions';
}

$systemPrompt = <<<PROMPT
You are an embedded assistant inside a personalized headless blog experience.
Be concise, practical, and calm.
Prioritize helping users with:
- website hosting onboarding via Dark Horse Virtue Pro Suite (https://hiops.darkhorsevirtue.io)
- AI access and practical next steps
- UX refinement guidance in this app

Rules:
- Return JSON only.
- Keep assistantMessage under 70 words.
- Provide 1-3 suggestions with label/action.
- Return media as an array (empty if none). For image responses, include media item with type=image, url, alt.
- Suggestion actions may be a safe URL, /onboarding?force=1, ask-hosting, ask-ai-access, or reopen-onboarding.
- Never output code blocks or markdown.
PROMPT;

$userPrompt = "Visitor seed: " . $visitorSeed .
    "\nRecent transcript: " . json_encode($transcript, JSON_UNESCAPED_SLASHES) .
    "\nUser message: " . $userMessage;

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ],
    'temperature' => 0.4,
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'assistant_turn',
            'strict' => true,
            'schema' => assistant_schema()
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
    send_json(200, local_assistant_fallback($userMessage));
}

$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($statusCode >= 400) {
    send_json(200, local_assistant_fallback($userMessage));
}

$responseJson = json_decode((string) $result, true);
$content = $responseJson['choices'][0]['message']['content'] ?? null;
if (!is_string($content) || trim($content) === '') {
    send_json(200, local_assistant_fallback($userMessage));
}

$parsed = extract_json_object($content);
if (!is_array($parsed)) {
    send_json(200, local_assistant_fallback($userMessage));
}

$assistantMessage = trim((string) ($parsed['assistantMessage'] ?? ''));
if ($assistantMessage === '') {
    send_json(200, local_assistant_fallback($userMessage));
}

$suggestions = normalize_suggestions($parsed['suggestions'] ?? []);
if (count($suggestions) === 0) {
    $suggestions = local_assistant_fallback($userMessage)['suggestions'];
}
$media = normalize_media($parsed['media'] ?? []);

send_json(200, [
    'assistantMessage' => $assistantMessage,
    'suggestions' => $suggestions,
    'media' => $media,
    'source' => 'backend'
]);
