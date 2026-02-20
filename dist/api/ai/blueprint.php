<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/wordpress.php';

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

function normalize_intent(array $decoded): array
{
    $intent = $decoded['intentProfile'] ?? null;
    if (!is_array($intent)) {
        send_json(400, ['error' => 'Missing intentProfile object']);
    }

    $goal = trim((string) ($intent['goal'] ?? ''));
    $vibe = (string) ($intent['vibe'] ?? 'minimal');
    $density = (string) ($intent['density'] ?? 'medium');
    $topics = $intent['primaryTopics'] ?? [];

    $validVibes = ['minimal', 'visual', 'dense', 'playful'];
    $validDensity = ['low', 'medium', 'high'];

    if ($goal === '') {
        $goal = 'Create a practical productivity workspace';
    }

    if (!in_array($vibe, $validVibes, true)) {
        $vibe = 'minimal';
    }

    if (!in_array($density, $validDensity, true)) {
        $density = 'medium';
    }

    if (!is_array($topics)) {
        $topics = [];
    }

    $cleanTopics = [];
    foreach ($topics as $topic) {
        if (!is_string($topic)) {
            continue;
        }
        $value = trim($topic);
        if ($value !== '') {
            $cleanTopics[] = $value;
        }
        if (count($cleanTopics) >= 4) {
            break;
        }
    }

    if (count($cleanTopics) === 0) {
        $cleanTopics = ['Planning', 'Execution'];
    }

    return [
        'goal' => $goal,
        'vibe' => $vibe,
        'density' => $density,
        'primaryTopics' => $cleanTopics
    ];
}

function blueprint_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['version', 'theme', 'layout', 'modules', 'shortcuts', 'createdAt', 'updatedAt'],
        'properties' => [
            'version' => ['type' => 'number'],
            'theme' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['mode', 'accent'],
                'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['dark', 'light']],
                    'accent' => ['type' => 'string']
                ]
            ],
            'layout' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['nav', 'density'],
                'properties' => [
                    'nav' => ['type' => 'string', 'enum' => ['side', 'top', 'none']],
                    'density' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']]
                ]
            ],
            'modules' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['id', 'type', 'props'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['Hero', 'ContentGrid', 'ContentList', 'QuickActions', 'FAQ']
                        ],
                        'props' => ['type' => 'object', 'additionalProperties' => true],
                        'contentKey' => ['type' => 'string']
                    ]
                ]
            ],
            'shortcuts' => [
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
            'createdAt' => ['type' => 'string'],
            'updatedAt' => ['type' => 'string']
        ]
    ];
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode($rawBody ?: '{}', true);
if (!is_array($decodedBody)) {
    send_json(400, ['error' => 'Invalid JSON request body']);
}

$config = mysite_load_server_config();
$intent = normalize_intent($decodedBody);
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];

$enabled = (bool) ($openAiConfig['enabled'] ?? true);
if (!$enabled) {
    send_json(503, ['error' => 'AI generation is disabled by server config']);
}

$apiKey = mysite_resolve_openai_api_key($config);
if ($apiKey === '') {
    send_json(503, [
        'error' => 'OpenAI API key is not configured',
        'hint' => 'Set in config.php or ~/.config/mysite/<host>.php'
    ]);
}

$wpSnapshot = mysite_wp_fetch_snapshot($config);
$gapSuggestions = mysite_wp_gap_suggestions($wpSnapshot);
$contentOverrides = mysite_wp_content_bundle($wpSnapshot, $gapSuggestions, $intent, $config);
$wpSummary = mysite_wp_summary_for_prompt($wpSnapshot, $gapSuggestions, $intent, $config);

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
You generate UI Blueprint JSON only.
Never return executable code, HTML, markdown, explanations, or prose.
Output must match the provided JSON schema exactly.
Primary conversion priorities are:
1) Start a hosting plan
2) Onboard into a Pro Suite hosting account via Dark Horse Virtue HiOps.
Design module ordering and shortcut labels around visitor intent and these conversion paths.
Use existing WordPress content as source-of-truth context and add guidance to fill content gaps.
PROMPT;

$userPrompt = "Intent profile:\n" . json_encode($intent, JSON_UNESCAPED_SLASHES) .
    "\nWordPress snapshot:\n" . json_encode($wpSummary, JSON_UNESCAPED_SLASHES) .
    "\nUse contentKey values only from: heroWelcome, featuredGrid, nextStepsList, quickStartActions, faqGeneral.";

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ],
    'temperature' => 0.2,
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'ui_blueprint',
            'strict' => true,
            'schema' => blueprint_schema()
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
    $error = curl_error($curl);
    curl_close($curl);
    send_json(502, ['error' => 'OpenAI request failed', 'details' => $error]);
}

$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$responseJson = json_decode((string) $result, true);
if ($statusCode >= 400) {
    send_json(502, [
        'error' => 'OpenAI API error',
        'status' => $statusCode,
        'details' => $responseJson
    ]);
}

$content = $responseJson['choices'][0]['message']['content'] ?? null;
if (!is_string($content) || trim($content) === '') {
    send_json(502, ['error' => 'OpenAI returned empty content']);
}

$blueprint = extract_json_object($content);
if ($blueprint === null) {
    send_json(502, ['error' => 'OpenAI output was not valid JSON']);
}

send_json(200, [
    'blueprint' => $blueprint,
    'contentOverrides' => $contentOverrides,
    'gapSuggestions' => $gapSuggestions,
    'wordpress' => [
        'baseUrl' => (string) ($wpSnapshot['baseUrl'] ?? ''),
        'available' => (bool) ($wpSnapshot['available'] ?? false),
        'fetchedAt' => (string) ($wpSnapshot['fetchedAt'] ?? gmdate('c')),
        'errors' => $wpSnapshot['errors'] ?? []
    ]
]);
