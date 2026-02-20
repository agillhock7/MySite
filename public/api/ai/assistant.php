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
        'required' => ['assistantMessage', 'suggestions'],
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

$timeoutSeconds = (int) ($openAiConfig['timeout_seconds'] ?? 30);
if ($timeoutSeconds < 5) {
    $timeoutSeconds = 5;
}
if ($timeoutSeconds > 120) {
    $timeoutSeconds = 120;
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

send_json(200, [
    'assistantMessage' => $assistantMessage,
    'suggestions' => $suggestions,
    'source' => 'backend'
]);
