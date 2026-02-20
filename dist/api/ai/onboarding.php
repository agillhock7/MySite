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

function default_intent_profile(): array
{
    return [
        'goal' => '',
        'vibe' => 'minimal',
        'density' => 'medium',
        'primaryTopics' => []
    ];
}

function normalize_intent_profile($value): array
{
    $intent = default_intent_profile();
    if (!is_array($value)) {
        return $intent;
    }

    $goal = trim((string) ($value['goal'] ?? ''));
    $vibe = (string) ($value['vibe'] ?? 'minimal');
    $density = (string) ($value['density'] ?? 'medium');
    $topics = $value['primaryTopics'] ?? [];

    if ($goal !== '') {
        $intent['goal'] = $goal;
    }

    if (in_array($vibe, ['minimal', 'visual', 'dense', 'playful'], true)) {
        $intent['vibe'] = $vibe;
    }

    if (in_array($density, ['low', 'medium', 'high'], true)) {
        $intent['density'] = $density;
    }

    if (is_array($topics)) {
        $clean = [];
        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                continue;
            }

            $topicValue = trim($topic);
            if ($topicValue !== '') {
                $clean[] = $topicValue;
            }

            if (count($clean) >= 4) {
                break;
            }
        }

        $intent['primaryTopics'] = $clean;
    }

    return $intent;
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
        $text = trim((string) ($item['text'] ?? ''));

        if (!in_array($role, ['system', 'assistant', 'user'], true)) {
            $role = 'user';
        }

        if ($text === '') {
            continue;
        }

        $entries[] = [
            'role' => $role,
            'text' => $text
        ];
    }

    if (count($entries) > 12) {
        $entries = array_slice($entries, -12);
    }

    return $entries;
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

function onboarding_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['assistantMessage', 'intentProfile', 'isComplete', 'confidence'],
        'properties' => [
            'assistantMessage' => ['type' => 'string'],
            'intentProfile' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['goal', 'vibe', 'density', 'primaryTopics'],
                'properties' => [
                    'goal' => ['type' => 'string'],
                    'vibe' => ['type' => 'string', 'enum' => ['minimal', 'visual', 'dense', 'playful']],
                    'density' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                    'primaryTopics' => [
                        'type' => 'array',
                        'items' => ['type' => 'string']
                    ]
                ]
            ],
            'isComplete' => ['type' => 'boolean'],
            'confidence' => ['type' => 'number']
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

$config = mysite_load_server_config();
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];
$enabled = (bool) ($openAiConfig['enabled'] ?? true);
if (!$enabled) {
    send_json(503, ['error' => 'AI generation is disabled by server config']);
}

$apiKey = mysite_resolve_openai_api_key($config);
if ($apiKey === '') {
    send_json(503, ['error' => 'OpenAI API key is not configured']);
}

$transcript = normalize_transcript($decodedBody['transcript'] ?? []);
$currentIntent = normalize_intent_profile($decodedBody['currentIntent'] ?? []);
$visitorId = normalize_visitor_id($decodedBody);
$variantNonce = normalize_variant_nonce($decodedBody);
$visitorSeed = substr(sha1($visitorId . ':v' . (string) $variantNonce), 0, 10);

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
You are an onboarding assistant for a headless frontend of alexanderjgill.com.
Have a natural conversational tone, not robotic.
Goal: gather enough signal to fill intentProfile:
- goal (string)
- vibe (minimal|visual|dense|playful)
- density (low|medium|high)
- primaryTopics (2-4 strings)

Rules:
- Ask only one concise follow-up question at a time when needed.
- Mirror the user's language and clarify confusing terms plainly.
- Ask about the person first: interests, personality, preferred interaction style, emotional tone.
- Do not ask the user to choose site navigation or page menus.
- Translate personal answers into intentProfile fields.
- If the user seems confused, reframe using plain language and quick examples.
- If user already provided enough detail, set isComplete=true.
- Keep assistantMessage under 40 words.
- Never output code, markdown, or explanations outside the JSON object.
Use the visitor seed to vary your voice subtly so chats feel unique per visitor.
PROMPT;

$userPrompt = "Current intent draft:\n" . json_encode($currentIntent, JSON_UNESCAPED_SLASHES) .
    "\nVisitor seed:\n" . $visitorSeed .
    "\nDesign iteration:\n" . (string) $variantNonce .
    "\nRecent transcript:\n" . json_encode($transcript, JSON_UNESCAPED_SLASHES);

$payload = [
    'model' => $model,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt]
    ],
    'temperature' => 0.35,
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'onboarding_turn',
            'strict' => true,
            'schema' => onboarding_schema()
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

$parsed = extract_json_object($content);
if ($parsed === null) {
    send_json(502, ['error' => 'OpenAI output was not valid JSON']);
}

$assistantMessage = trim((string) ($parsed['assistantMessage'] ?? ''));
if ($assistantMessage === '') {
    $assistantMessage = 'Tell me your main outcome and I will tailor the experience.';
}

$intentProfile = normalize_intent_profile($parsed['intentProfile'] ?? []);
$isComplete = (bool) ($parsed['isComplete'] ?? false);
$confidence = (float) ($parsed['confidence'] ?? 0.0);
if ($confidence < 0) {
    $confidence = 0.0;
}
if ($confidence > 1) {
    $confidence = 1.0;
}

send_json(200, [
    'assistantMessage' => $assistantMessage,
    'intentProfile' => $intentProfile,
    'isComplete' => $isComplete,
    'confidence' => $confidence
]);
