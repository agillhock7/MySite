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

function normalize_attachments($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $kindRaw = strtolower(clean_text($item['kind'] ?? '', 'file'));
        $name = clean_text($item['name'] ?? '');
        $mimeType = clean_text($item['mimeType'] ?? '', 'application/octet-stream');
        $sizeBytes = (int) ($item['sizeBytes'] ?? 0);
        $dataUrl = clean_text($item['dataUrl'] ?? '');
        $textPreview = clean_text($item['textPreview'] ?? '');

        if ($name === '' || $sizeBytes < 0) {
            continue;
        }

        $kind = 'file';
        if ($kindRaw === 'image' || strpos($mimeType, 'image/') === 0) {
            $kind = 'image';
        } elseif ($kindRaw === 'text' || strpos($mimeType, 'text/') === 0) {
            $kind = 'text';
        }

        if ($dataUrl !== '' && strpos($dataUrl, 'data:image/') !== 0) {
            $dataUrl = '';
        }
        if (strlen($dataUrl) > 900000) {
            $dataUrl = substr($dataUrl, 0, 900000);
        }
        if (strlen($textPreview) > 1200) {
            $textPreview = substr($textPreview, 0, 1200);
        }

        $normalized[] = [
            'kind' => $kind,
            'name' => substr($name, 0, 100),
            'mimeType' => substr($mimeType, 0, 80),
            'sizeBytes' => min($sizeBytes, 50000000),
            'dataUrl' => $dataUrl,
            'textPreview' => $textPreview
        ];

        if (count($normalized) >= 3) {
            break;
        }
    }

    return $normalized;
}

function clamp_float($value, float $minimum, float $maximum, float $fallback): float
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    $numeric = (float) $value;
    if ($numeric < $minimum) {
        return $minimum;
    }
    if ($numeric > $maximum) {
        return $maximum;
    }

    return $numeric;
}

function clamp_int($value, int $minimum, int $maximum, int $fallback): int
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    $numeric = (int) $value;
    if ($numeric < $minimum) {
        return $minimum;
    }
    if ($numeric > $maximum) {
        return $maximum;
    }

    return $numeric;
}

function normalize_chat_content($content): string
{
    if (is_string($content)) {
        return trim($content);
    }

    if (!is_array($content)) {
        return '';
    }

    $parts = [];
    foreach ($content as $item) {
        if (!is_array($item)) {
            continue;
        }

        $text = '';
        if (isset($item['text']) && is_string($item['text'])) {
            $text = $item['text'];
        } elseif (isset($item['content']) && is_string($item['content'])) {
            $text = $item['content'];
        }

        $text = trim($text);
        if ($text !== '') {
            $parts[] = $text;
        }
    }

    return trim(implode("\n", $parts));
}

function openai_chat_request(string $apiUrl, string $apiKey, array $payload, int $timeoutSeconds): array
{
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
        return ['ok' => false, 'status' => 0, 'body' => null];
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($statusCode >= 400) {
        return ['ok' => false, 'status' => $statusCode, 'body' => null];
    }

    $decoded = json_decode((string) $result, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $statusCode, 'body' => null];
    }

    return ['ok' => true, 'status' => $statusCode, 'body' => $decoded];
}

function is_image_request(string $message): bool
{
    return preg_match('/\b(image|illustration|render|draw|logo|poster|photo|artwork|cover art)\b/i', $message) === 1;
}

function is_image_revision_request(string $message): bool
{
    return preg_match('/\b(make it|make this|do a better|better one|try again|again|regenerate|variation|variant|version|restyle|style|angle|lighting|mood|photorealistic|realistic|cinematic|more detail|less detail|looks nothing like|not what i uploaded|use my upload|based on my upload|match the upload|fix this image)\b/i', $message) === 1;
}

function transcript_has_image_context(array $transcript): bool
{
    if (count($transcript) === 0) {
        return false;
    }

    $recent = array_slice($transcript, -8);
    foreach ($recent as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $text = trim((string) ($entry['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        if (is_image_request($text)) {
            return true;
        }
    }

    return false;
}

function is_hosting_request(string $message): bool
{
    return preg_match('/\b(host|hosting|server|domain|deploy|deployment|vps|cloud|pro suite|dark horse|whmcs)\b/i', $message) === 1;
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

function local_assistant_fallback(string $userMessage, array $attachments = []): array
{
    $normalized = strtolower($userMessage);

    if (count($attachments) > 0) {
        return [
            'assistantMessage' => 'I received your file upload. Tell me what you want from it and I will guide a practical next step.',
            'suggestions' => [
                ['label' => 'Summarize Upload', 'action' => 'ask-ai-access'],
                ['label' => 'Build Widget', 'action' => '/widget build'],
                ['label' => 'Open Pro Suite', 'action' => 'https://hiops.darkhorsevirtue.io']
            ],
            'media' => [],
            'source' => 'local'
        ];
    }

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
$attachments = normalize_attachments($decodedBody['attachments'] ?? []);
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
    send_json(200, local_assistant_fallback($userMessage, $attachments));
}

$apiUrl = trim((string) ($openAiConfig['api_url'] ?? 'https://api.openai.com/v1/chat/completions'));
if ($apiUrl === '') {
    $apiUrl = 'https://api.openai.com/v1/chat/completions';
}

$assistantModel = trim((string) ($openAiConfig['assistant_model'] ?? ''));
$defaultModel = trim((string) ($openAiConfig['model'] ?? 'gpt-4o-mini'));
if ($defaultModel === '') {
    $defaultModel = 'gpt-4o-mini';
}
if ($assistantModel === '') {
    $assistantModel = $defaultModel;
}
$assistantTemperature = clamp_float($openAiConfig['assistant_temperature'] ?? 0.62, 0.1, 1.2, 0.62);
$assistantMaxTokens = clamp_int($openAiConfig['assistant_max_tokens'] ?? 520, 120, 1200, 520);

$systemPrompt = <<<PROMPT
You are an embedded assistant inside a personalized headless blog experience.
You are conversational, practical, and adaptive.
Keep responses natural and fluid like a modern premium LLM chat experience.
Primary jobs:
- Help users complete tasks inside this workspace.
- Provide concrete, step-by-step guidance when asked.
- Offer hosting via Dark Horse Virtue Pro Suite only when relevant to the user's intent.

Rules:
- Return JSON only.
- assistantMessage should usually be 1 short paragraph (about 40-140 words), unless user asks for short output.
- Provide 1-3 suggestions with label/action.
- Return media as an array (empty if none). For image responses, include media item with type=image, url, alt.
- Suggestion actions may be a safe URL, /onboarding?force=1, ask-hosting, ask-ai-access, or reopen-onboarding.
- Never output code blocks or markdown.
- Do not force sales CTAs in every response.
- If user asks about hosting/deployment, include https://hiops.darkhorsevirtue.io as the first recommendation.
- If user provided files, acknowledge them and provide the next practical action.
PROMPT;

$attachmentSummaryParts = [];
foreach ($attachments as $attachment) {
    $part = ($attachment['kind'] ?? 'file') . ':' . ($attachment['name'] ?? 'file') . ' (' . (string) ($attachment['sizeBytes'] ?? 0) . ' bytes)';
    if (($attachment['textPreview'] ?? '') !== '') {
        $part .= ' preview="' . substr((string) $attachment['textPreview'], 0, 220) . '"';
    }
    $attachmentSummaryParts[] = $part;
}
$attachmentSummary = count($attachmentSummaryParts) > 0 ? implode('; ', $attachmentSummaryParts) : 'none';

$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];

$normalizedTranscriptMessages = [];
foreach ($transcript as $entry) {
    $role = (string) ($entry['role'] ?? '');
    if (!in_array($role, ['assistant', 'user'], true)) {
        continue;
    }

    $text = trim((string) ($entry['text'] ?? ''));
    if ($text === '') {
        continue;
    }

    $normalizedTranscriptMessages[] = [
        'role' => $role,
        'content' => substr($text, 0, 2000)
    ];
}
if (count($normalizedTranscriptMessages) > 14) {
    $normalizedTranscriptMessages = array_slice($normalizedTranscriptMessages, -14);
}
foreach ($normalizedTranscriptMessages as $entry) {
    $messages[] = $entry;
}

$lastTranscriptUser = '';
for ($i = count($normalizedTranscriptMessages) - 1; $i >= 0; $i--) {
    if (($normalizedTranscriptMessages[$i]['role'] ?? '') === 'user') {
        $lastTranscriptUser = trim((string) ($normalizedTranscriptMessages[$i]['content'] ?? ''));
        break;
    }
}

$attachmentContext = "Visitor seed: " . $visitorSeed . "\nAttachments: " . $attachmentSummary;
$userPrompt = $attachmentContext . "\nLatest user request: " . $userMessage;
$dedupeUserMessage = trim($userMessage);
if ($lastTranscriptUser !== '' && strcasecmp($lastTranscriptUser, $dedupeUserMessage) === 0) {
    $userPrompt = $attachmentContext . "\nThe latest user request is the same as the last chat turn. Respond to it with continuity.";
}

$userContent = $userPrompt;
$imageAttachments = array_values(array_filter($attachments, static function (array $attachment): bool {
    return ($attachment['kind'] ?? '') === 'image' && strpos((string) ($attachment['dataUrl'] ?? ''), 'data:image/') === 0;
}));
if (count($imageAttachments) > 0) {
    $contentParts = [
        ['type' => 'text', 'text' => $userPrompt]
    ];
    foreach ($imageAttachments as $attachment) {
        $contentParts[] = [
            'type' => 'image_url',
            'image_url' => ['url' => (string) $attachment['dataUrl']]
        ];
    }
    $userContent = $contentParts;
}

$messages[] = ['role' => 'user', 'content' => $userContent];

$modelCandidates = array_values(array_unique(array_filter([
    $assistantModel,
    $defaultModel,
    'gpt-4o-mini'
], static function ($candidate): bool {
    return is_string($candidate) && trim($candidate) !== '';
})));

$responseJson = null;
foreach ($modelCandidates as $candidateModel) {
    $basePayload = [
        'model' => $candidateModel,
        'messages' => $messages,
        'temperature' => $assistantTemperature,
        'max_tokens' => $assistantMaxTokens
    ];

    $schemaPayload = $basePayload;
    $schemaPayload['response_format'] = [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'assistant_turn',
            'strict' => true,
            'schema' => assistant_schema()
        ]
    ];
    $schemaResult = openai_chat_request($apiUrl, $apiKey, $schemaPayload, $timeoutSeconds);
    if (($schemaResult['ok'] ?? false) === true && is_array($schemaResult['body'] ?? null)) {
        $responseJson = $schemaResult['body'];
        break;
    }

    $jsonObjectPayload = $basePayload;
    $jsonObjectPayload['response_format'] = ['type' => 'json_object'];
    $jsonObjectResult = openai_chat_request($apiUrl, $apiKey, $jsonObjectPayload, $timeoutSeconds);
    if (($jsonObjectResult['ok'] ?? false) === true && is_array($jsonObjectResult['body'] ?? null)) {
        $responseJson = $jsonObjectResult['body'];
        break;
    }

    $plainResult = openai_chat_request($apiUrl, $apiKey, $basePayload, $timeoutSeconds);
    if (($plainResult['ok'] ?? false) === true && is_array($plainResult['body'] ?? null)) {
        $responseJson = $plainResult['body'];
        break;
    }
}

if (!is_array($responseJson)) {
    send_json(200, local_assistant_fallback($userMessage, $attachments));
}

$content = normalize_chat_content($responseJson['choices'][0]['message']['content'] ?? null);
if ($content === '') {
    send_json(200, local_assistant_fallback($userMessage, $attachments));
}

$parsed = extract_json_object($content);
if (!is_array($parsed)) {
    $parsed = [
        'assistantMessage' => $content,
        'suggestions' => [],
        'media' => []
    ];
}

$assistantMessage = trim((string) ($parsed['assistantMessage'] ?? ''));
if ($assistantMessage === '') {
    send_json(200, local_assistant_fallback($userMessage, $attachments));
}

$imageTurnRequested = is_image_request($userMessage)
    || is_image_revision_request($userMessage)
    || transcript_has_image_context($transcript);
$denialPattern = '/\b(can(?:not|\'t)|unable|currently can\'t|do not)\b[\s\S]{0,90}\b(edit|improve|generate|create)\b[\s\S]{0,90}\b(image|visual|photo|render)\b/i';
if ($imageTurnRequested && preg_match($denialPattern, $assistantMessage) === 1) {
    $assistantMessage = 'Image request captured. I am generating a new in-thread variation now. Tell me the exact style, angle, lighting, or realism level and I will iterate.';
}

$suggestions = normalize_suggestions($parsed['suggestions'] ?? []);
if (count($suggestions) === 0) {
    $suggestions = local_assistant_fallback($userMessage, $attachments)['suggestions'];
}
$media = normalize_media($parsed['media'] ?? []);

if ($imageTurnRequested && count($suggestions) === 0) {
    $suggestions = [
        ['label' => 'More Photorealistic', 'action' => 'ask-ai-access'],
        ['label' => 'Try New Angle', 'action' => 'ask-ai-access']
    ];
}

if (is_hosting_request($userMessage)) {
    $hasHiopsSuggestion = false;
    foreach ($suggestions as $entry) {
        if (strpos((string) ($entry['action'] ?? ''), 'https://hiops.darkhorsevirtue.io') === 0) {
            $hasHiopsSuggestion = true;
            break;
        }
    }
    if (!$hasHiopsSuggestion) {
        array_unshift($suggestions, ['label' => 'Open Pro Suite', 'action' => 'https://hiops.darkhorsevirtue.io']);
        $suggestions = array_slice($suggestions, 0, 4);
    }
    if (stripos($assistantMessage, 'hiops.darkhorsevirtue.io') === false && stripos($assistantMessage, 'pro suite') === false) {
        $assistantMessage = rtrim($assistantMessage, ". \n\t") . '. Start here: https://hiops.darkhorsevirtue.io';
    }
}

send_json(200, [
    'assistantMessage' => $assistantMessage,
    'suggestions' => $suggestions,
    'media' => $media,
    'source' => 'backend'
]);
