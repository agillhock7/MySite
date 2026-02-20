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

function seeded_value(string $visitorId, string $salt, int $max): int
{
    if ($max <= 1) {
        return 0;
    }

    $hash = sha1($visitorId . ':' . $salt);
    $segment = substr($hash, 0, 8);
    $numeric = hexdec($segment);
    return (int) ($numeric % $max);
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

function infer_theme_from_intent(array $intent, string $visitorId): array
{
    $vibe = (string) ($intent['vibe'] ?? 'minimal');
    $mode = ($vibe === 'visual') ? 'light' : 'dark';
    if ($vibe === 'playful' && seeded_value($visitorId, 'mode-playful', 3) > 0) {
        $mode = 'light';
    }
    if ($vibe === 'dense' && seeded_value($visitorId, 'mode-dense', 2) > 0) {
        $mode = 'light';
    }
    if ($vibe === 'minimal' && seeded_value($visitorId, 'mode-minimal', 4) === 0) {
        $mode = 'light';
    }

    $accentMap = [
        'minimal' => ['#22c55e', '#14b8a6', '#10b981', '#65a30d'],
        'visual' => ['#0ea5e9', '#2563eb', '#0891b2', '#06b6d4'],
        'dense' => ['#f97316', '#ea580c', '#d97706', '#c2410c'],
        'playful' => ['#ec4899', '#db2777', '#f43f5e', '#7c3aed']
    ];
    $palette = $accentMap[$vibe] ?? $accentMap['minimal'];
    $accent = $palette[seeded_value($visitorId, 'accent-' . $vibe, count($palette))];

    return [
        'mode' => $mode,
        'accent' => $accent
    ];
}

function infer_layout_from_intent(array $intent, string $visitorId): array
{
    $density = (string) ($intent['density'] ?? 'medium');
    if (!in_array($density, ['low', 'medium', 'high'], true)) {
        $density = 'medium';
    }

    $nav = 'top';
    if ($density === 'high') {
        $nav = seeded_value($visitorId, 'nav-high', 3) === 0 ? 'top' : 'side';
    }
    if ($density === 'medium') {
        $nav = seeded_value($visitorId, 'nav-medium', 2) === 0 ? 'top' : 'side';
    }
    if ($density === 'low') {
        $nav = seeded_value($visitorId, 'nav-low', 4) === 0 ? 'top' : 'none';
    }

    return [
        'nav' => $nav,
        'density' => $density
    ];
}

function normalize_module_content_key(string $type, string $contentKey): string
{
    if ($contentKey !== '') {
        return $contentKey;
    }

    if ($type === 'Hero') {
        return 'heroWelcome';
    }
    if ($type === 'ContentGrid') {
        return 'featuredGrid';
    }
    if ($type === 'ContentList') {
        return 'nextStepsList';
    }
    if ($type === 'QuickActions') {
        return 'quickStartActions';
    }

    return 'faqGeneral';
}

function normalize_modules(array $candidateModules): array
{
    $allowedTypes = ['Hero', 'ContentGrid', 'ContentList', 'QuickActions', 'FAQ'];
    $normalized = [];
    $seenIds = [];

    foreach ($candidateModules as $index => $module) {
        if (!is_array($module)) {
            continue;
        }

        $type = (string) ($module['type'] ?? '');
        if (!in_array($type, $allowedTypes, true)) {
            continue;
        }

        $id = trim((string) ($module['id'] ?? ''));
        if ($id === '') {
            $id = strtolower($type) . '-' . ($index + 1);
        }
        if (isset($seenIds[$id])) {
            $id = $id . '-' . ($index + 1);
        }
        $seenIds[$id] = true;

        $props = $module['props'] ?? [];
        if (!is_array($props)) {
            $props = [];
        }

        $contentKeyRaw = trim((string) ($module['contentKey'] ?? ''));
        $contentKey = normalize_module_content_key($type, $contentKeyRaw);

        $normalized[] = [
            'id' => $id,
            'type' => $type,
            'props' => $props,
            'contentKey' => $contentKey
        ];
    }

    if (count($normalized) === 0) {
        return [
            ['id' => 'hero-journey', 'type' => 'Hero', 'props' => [], 'contentKey' => 'heroWelcome'],
            ['id' => 'actions-start', 'type' => 'QuickActions', 'props' => [], 'contentKey' => 'quickStartActions'],
            ['id' => 'grid-highlights', 'type' => 'ContentGrid', 'props' => [], 'contentKey' => 'featuredGrid'],
            ['id' => 'list-next', 'type' => 'ContentList', 'props' => [], 'contentKey' => 'nextStepsList'],
            ['id' => 'faq-trust', 'type' => 'FAQ', 'props' => [], 'contentKey' => 'faqGeneral']
        ];
    }

    return array_slice($normalized, 0, 8);
}

function normalize_shortcuts(array $candidateShortcuts, array $intent, array $snapshot): array
{
    $conversionProfile = mysite_wp_conversion_profile($intent);
    $baseUrl = (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com');

    $defaults = [
        [
            'label' => (string) ($conversionProfile['primaryActionLabel'] ?? 'Start Pro Suite Onboarding'),
            'action' => (string) ($conversionProfile['primaryActionUrl'] ?? 'https://hiops.darkhorsevirtue.io')
        ],
        [
            'label' => (string) ($conversionProfile['secondaryActionLabel'] ?? 'Start Hosting Plan'),
            'action' => (string) ($conversionProfile['secondaryActionUrl'] ?? $baseUrl)
        ],
        ['label' => 'Explore Main Site', 'action' => $baseUrl]
    ];

    $normalized = [];
    foreach ($candidateShortcuts as $shortcut) {
        if (!is_array($shortcut)) {
            continue;
        }

        $label = trim((string) ($shortcut['label'] ?? ''));
        $action = trim((string) ($shortcut['action'] ?? ''));
        if ($label === '' || $action === '') {
            continue;
        }

        $normalized[] = ['label' => $label, 'action' => $action];
        if (count($normalized) >= 6) {
            break;
        }
    }

    foreach ($defaults as $defaultShortcut) {
        $found = false;
        foreach ($normalized as $existing) {
            if ($existing['action'] === $defaultShortcut['action']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $normalized[] = $defaultShortcut;
        }
        if (count($normalized) >= 6) {
            break;
        }
    }

    return $normalized;
}

function reorder_modules_for_visitor(array $modules, string $visitorId): array
{
    if (count($modules) <= 2) {
        return $modules;
    }

    $hero = [];
    $faq = [];
    $middle = [];

    foreach ($modules as $module) {
        $type = (string) ($module['type'] ?? '');
        if ($type === 'Hero') {
            $hero[] = $module;
            continue;
        }
        if ($type === 'FAQ') {
            $faq[] = $module;
            continue;
        }

        $middle[] = $module;
    }

    usort($middle, static function (array $left, array $right) use ($visitorId): int {
        $leftId = (string) ($left['id'] ?? 'module-left');
        $rightId = (string) ($right['id'] ?? 'module-right');
        $leftWeight = seeded_value($visitorId, 'module-weight:' . $leftId, 1000);
        $rightWeight = seeded_value($visitorId, 'module-weight:' . $rightId, 1000);
        return $leftWeight <=> $rightWeight;
    });

    return array_merge($hero, $middle, $faq);
}

function personalize_module_props(array $modules, array $intent, array $snapshot, string $visitorId): array
{
    $goal = trim((string) ($intent['goal'] ?? ''));
    $topics = is_array($intent['primaryTopics'] ?? null) ? $intent['primaryTopics'] : [];
    $firstTopic = isset($topics[0]) ? trim((string) $topics[0]) : 'hosting';
    $secondTopic = isset($topics[1]) ? trim((string) $topics[1]) : 'onboarding';
    $conversion = mysite_wp_conversion_profile($intent);

    $heroKickers = ['Visitor Blueprint', 'Adaptive Journey', 'AI Interface DNA', 'Conversion Narrative'];
    $heroVariants = ['default', 'spotlight', 'split'];
    $gridVariants = ['default', 'magazine'];
    $listVariants = ['default', 'timeline'];
    $gridColumns = [2, 2, 3];

    foreach ($modules as $index => $module) {
        $type = (string) ($module['type'] ?? '');
        $props = $module['props'] ?? [];
        if (!is_array($props)) {
            $props = [];
        }

        if ($type === 'Hero') {
            $props['variant'] = $heroVariants[seeded_value($visitorId, 'hero-variant', count($heroVariants))];
            if (trim((string) ($props['kicker'] ?? '')) === '') {
                $props['kicker'] = $heroKickers[seeded_value($visitorId, 'hero-kicker', count($heroKickers))];
            }
            if (trim((string) ($props['title'] ?? '')) === '') {
                $props['title'] = $goal !== '' ? $goal : 'Adaptive experience for alexanderjgill.com';
            }
            if (trim((string) ($props['subtitle'] ?? '')) === '') {
                $props['subtitle'] = 'This shell prioritizes ' . strtolower((string) ($conversion['primaryActionLabel'] ?? 'the next conversion action')) . '.';
            }
            if (trim((string) ($props['ctaUrl'] ?? '')) === '') {
                $props['ctaUrl'] = (string) ($conversion['primaryActionUrl'] ?? 'https://hiops.darkhorsevirtue.io');
            }
        }

        if ($type === 'ContentGrid') {
            if (trim((string) ($props['title'] ?? '')) === '') {
                $props['title'] = 'Proof around ' . $firstTopic;
            }
            if (trim((string) ($props['intro'] ?? '')) === '') {
                $props['intro'] = 'Live WordPress highlights selected for this visitor journey.';
            }
            $props['variant'] = $gridVariants[seeded_value($visitorId, 'grid-variant:' . $index, count($gridVariants))];
            $props['columns'] = $gridColumns[seeded_value($visitorId, 'grid-columns:' . $index, count($gridColumns))];
        }

        if ($type === 'ContentList') {
            if (trim((string) ($props['title'] ?? '')) === '') {
                $props['title'] = 'Decision path for ' . $secondTopic;
            }
            if (trim((string) ($props['intro'] ?? '')) === '') {
                $props['intro'] = 'Action sequence generated from onboarding + content signals.';
            }
            $props['variant'] = $listVariants[seeded_value($visitorId, 'list-variant:' . $index, count($listVariants))];
        }

        if ($type === 'QuickActions' && trim((string) ($props['title'] ?? '')) === '') {
            $props['title'] = 'Primary Conversion Paths';
        }

        if ($type === 'FAQ' && trim((string) ($props['title'] ?? '')) === '') {
            $props['title'] = 'Trust + Implementation Notes';
        }

        $modules[$index]['props'] = $props;
    }

    return $modules;
}

function normalize_ai_blueprint(array $candidate, array $intent, array $snapshot, string $visitorId): array
{
    $theme = infer_theme_from_intent($intent, $visitorId);
    $layout = infer_layout_from_intent($intent, $visitorId);
    $now = gmdate('c');

    if (is_array($candidate['theme'] ?? null)) {
        $candidateMode = (string) ($candidate['theme']['mode'] ?? '');
        $candidateAccent = trim((string) ($candidate['theme']['accent'] ?? ''));

        if (in_array($candidateMode, ['dark', 'light'], true)) {
            $theme['mode'] = $candidateMode;
        }
        if ($candidateAccent !== '') {
            $theme['accent'] = $candidateAccent;
        }
    }

    if (is_array($candidate['layout'] ?? null)) {
        $candidateNav = (string) ($candidate['layout']['nav'] ?? '');
        $candidateDensity = (string) ($candidate['layout']['density'] ?? '');

        if (in_array($candidateNav, ['side', 'top', 'none'], true)) {
            $layout['nav'] = $candidateNav;
        }
        if (in_array($candidateDensity, ['low', 'medium', 'high'], true)) {
            $layout['density'] = $candidateDensity;
        }
    }

    $candidateModules = is_array($candidate['modules'] ?? null) ? $candidate['modules'] : [];
    $candidateShortcuts = is_array($candidate['shortcuts'] ?? null) ? $candidate['shortcuts'] : [];

    return [
        'version' => 1,
        'theme' => $theme,
        'layout' => $layout,
        'modules' => personalize_module_props(
            reorder_modules_for_visitor(normalize_modules($candidateModules), $visitorId),
            $intent,
            $snapshot,
            $visitorId
        ),
        'shortcuts' => normalize_shortcuts($candidateShortcuts, $intent, $snapshot),
        'createdAt' => (string) ($candidate['createdAt'] ?? $now),
        'updatedAt' => $now
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
$intent = normalize_intent($decodedBody);
$visitorId = normalize_visitor_id($decodedBody);
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
$contentOverrides = mysite_wp_content_bundle($wpSnapshot, $gapSuggestions, $intent);
$wpSummary = mysite_wp_summary_for_prompt($wpSnapshot, $gapSuggestions, $intent);

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
Design a distinctive front-end site experience, not a dashboard.
Treat modules as website sections with intentional hierarchy, flow, and tone.
Primary conversion priorities are:
1) Start a hosting plan
2) Onboard into a Pro Suite hosting account via Dark Horse Virtue HiOps.
Design module ordering and shortcut labels around visitor intent and these conversion paths.
Use existing WordPress content as source-of-truth context and add guidance to fill content gaps.
Each visitor has a design seed. Use it to make the layout feel unique, not generic.
PROMPT;

$visitorSeed = substr(sha1($visitorId), 0, 12);
$userPrompt = "Intent profile:\n" . json_encode($intent, JSON_UNESCAPED_SLASHES) .
    "\nVisitor design seed:\n" . $visitorSeed .
    "\nWordPress snapshot:\n" . json_encode($wpSummary, JSON_UNESCAPED_SLASHES) .
    "\nKnown IA signals include: Home, Work, Lab, Read, Bio, Markets." .
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

$blueprint = normalize_ai_blueprint($blueprint, $intent, $wpSnapshot, $visitorId);

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
