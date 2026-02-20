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
        $goal = 'Create a unique headless frontend for alexanderjgill.com';
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
        $cleanTopics = ['Work', 'Read'];
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

function normalize_transcript(array $decoded): array
{
    $value = $decoded['transcript'] ?? [];
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

    if (count($entries) > 24) {
        $entries = array_slice($entries, -24);
    }

    return $entries;
}

function transcript_prompt_lines(array $transcript): string
{
    if (count($transcript) === 0) {
        return 'No transcript provided.';
    }

    $lines = [];
    foreach ($transcript as $entry) {
        $role = strtoupper((string) ($entry['role'] ?? 'user'));
        $text = trim((string) ($entry['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $lines[] = $role . ': ' . $text;
    }

    if (count($lines) === 0) {
        return 'No transcript provided.';
    }

    return implode("\n", $lines);
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

function thought_loop_pass_config(string $visitorSeedKey, int $passIndex): array
{
    $profilePool = ['orbital', 'editorial', 'kinetic', 'glass', 'neo'];
    $structurePool = ['narrative-arc', 'feature-led', 'mosaic-flow', 'gallery-path', 'signal-first'];
    $tempoPool = ['calm', 'balanced', 'energetic', 'punchy'];
    $contrastPool = ['soft-contrast', 'balanced-contrast', 'high-contrast'];

    $passSeed = $visitorSeedKey . ':thought-pass:' . (string) $passIndex;
    $profile = $profilePool[seeded_value($passSeed, 'profile', count($profilePool))];
    $structure = $structurePool[seeded_value($passSeed, 'structure', count($structurePool))];
    $tempo = $tempoPool[seeded_value($passSeed, 'tempo', count($tempoPool))];
    $contrast = $contrastPool[seeded_value($passSeed, 'contrast', count($contrastPool))];
    $temperature = 0.28 + (seeded_value($passSeed, 'temperature', 40) / 100.0);

    return [
        'passIndex' => $passIndex,
        'seed' => substr(sha1($passSeed), 0, 10),
        'profile' => $profile,
        'structure' => $structure,
        'tempo' => $tempo,
        'contrast' => $contrast,
        'temperature' => $temperature
    ];
}

function design_signature(string $visitorSeedKey, int $passIndex, string $profile): string
{
    return strtoupper(substr(sha1($visitorSeedKey . ':pass:' . (string) $passIndex . ':' . $profile), 0, 8));
}

function score_blueprint_candidate(array $blueprint, array $intent, array $transcript): float
{
    $modules = is_array($blueprint['modules'] ?? null) ? $blueprint['modules'] : [];
    $shortcuts = is_array($blueprint['shortcuts'] ?? null) ? $blueprint['shortcuts'] : [];
    $moduleCount = count($modules);
    $typeSet = [];
    $variantCount = 0;
    $topicMatches = 0;

    $topics = is_array($intent['primaryTopics'] ?? null) ? $intent['primaryTopics'] : [];
    $topicTerms = [];
    foreach ($topics as $topic) {
        if (!is_string($topic)) {
            continue;
        }
        $term = strtolower(trim($topic));
        if ($term !== '') {
            $topicTerms[] = $term;
        }
    }

    foreach ($modules as $module) {
        if (!is_array($module)) {
            continue;
        }

        $type = (string) ($module['type'] ?? '');
        if ($type !== '') {
            $typeSet[$type] = true;
        }

        $props = is_array($module['props'] ?? null) ? $module['props'] : [];
        $variant = trim((string) ($props['variant'] ?? ''));
        if ($variant !== '' && $variant !== 'default') {
            $variantCount += 1;
        }

        $textBlob = strtolower(
            trim((string) ($props['title'] ?? '')) . ' ' .
            trim((string) ($props['subtitle'] ?? '')) . ' ' .
            trim((string) ($props['intro'] ?? ''))
        );

        foreach ($topicTerms as $term) {
            if ($term !== '' && strpos($textBlob, $term) !== false) {
                $topicMatches += 1;
            }
        }
    }

    $transcriptSignal = 0;
    foreach ($transcript as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = (string) ($entry['role'] ?? '');
        if ($role !== 'user') {
            continue;
        }
        $text = trim((string) ($entry['text'] ?? ''));
        if ($text !== '') {
            $transcriptSignal += 1;
        }
    }

    $typeDiversity = (float) count($typeSet);
    $shortcutsScore = min(6, count($shortcuts));
    $moduleDepth = min(8, $moduleCount);
    $topicAlignment = min(8, $topicMatches);
    $conversationDepth = min(6, $transcriptSignal);

    return
        ($moduleDepth * 1.8) +
        ($typeDiversity * 2.1) +
        ($variantCount * 1.3) +
        ($shortcutsScore * 0.6) +
        ($topicAlignment * 1.2) +
        ($conversationDepth * 0.4);
}

function request_openai_blueprint(
    string $apiUrl,
    string $apiKey,
    string $model,
    int $timeoutSeconds,
    string $systemPrompt,
    string $userPrompt,
    float $temperature
): array {
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => $temperature,
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
        return [
            'ok' => false,
            'error' => 'OpenAI request failed: ' . $error
        ];
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $responseJson = json_decode((string) $result, true);
    if ($statusCode >= 400) {
        $summary = is_array($responseJson) ? json_encode($responseJson, JSON_UNESCAPED_SLASHES) : 'Unknown error';
        return [
            'ok' => false,
            'error' => 'OpenAI API error ' . (string) $statusCode . ': ' . (string) $summary
        ];
    }

    $content = $responseJson['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return [
            'ok' => false,
            'error' => 'OpenAI returned empty content'
        ];
    }

    $blueprint = extract_json_object($content);
    if (!is_array($blueprint)) {
        return [
            'ok' => false,
            'error' => 'OpenAI output was not valid JSON'
        ];
    }

    return [
        'ok' => true,
        'blueprint' => $blueprint
    ];
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

function known_action_urls(array $snapshot): array
{
    $known = [];

    $baseUrl = mysite_wp_normalize_url((string) ($snapshot['baseUrl'] ?? ''));
    if ($baseUrl !== '') {
        $known[$baseUrl] = $baseUrl;
    }

    $posts = $snapshot['posts'] ?? [];
    if (is_array($posts)) {
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }

            $link = mysite_wp_normalize_url((string) ($post['link'] ?? ''));
            if ($link !== '') {
                $known[$link] = $link;
            }
        }
    }

    return $known;
}

function sanitize_action_url(string $candidate, array $snapshot, string $fallback): string
{
    $known = known_action_urls($snapshot);

    $normalizedCandidate = mysite_wp_normalize_url($candidate);
    if ($normalizedCandidate !== '' && isset($known[$normalizedCandidate])) {
        return $known[$normalizedCandidate];
    }

    $normalizedFallback = mysite_wp_normalize_url($fallback);
    if ($normalizedFallback !== '' && isset($known[$normalizedFallback])) {
        return $known[$normalizedFallback];
    }

    if ($normalizedFallback !== '') {
        return $normalizedFallback;
    }

    foreach ($known as $url) {
        return $url;
    }

    return '';
}

function normalize_shortcuts(array $candidateShortcuts, array $intent, array $snapshot, string $visitorId): array
{
    $conversionProfile = mysite_wp_conversion_profile($intent, $snapshot);
    $baseUrl = (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com');

    $defaults = [
        [
            'label' => (string) ($conversionProfile['primaryActionLabel'] ?? 'Explore Main Site'),
            'action' => sanitize_action_url(
                (string) ($conversionProfile['primaryActionUrl'] ?? $baseUrl),
                $snapshot,
                $baseUrl
            )
        ],
        [
            'label' => (string) ($conversionProfile['secondaryActionLabel'] ?? 'Read Latest Insights'),
            'action' => sanitize_action_url(
                (string) ($conversionProfile['secondaryActionUrl'] ?? $baseUrl),
                $snapshot,
                $baseUrl
            )
        ],
        ['label' => 'Explore Main Site', 'action' => sanitize_action_url($baseUrl, $snapshot, $baseUrl)]
    ];

    $normalized = [];
    foreach ($candidateShortcuts as $shortcut) {
        if (!is_array($shortcut)) {
            continue;
        }

        $label = trim((string) ($shortcut['label'] ?? ''));
        $action = sanitize_action_url(
            (string) ($shortcut['action'] ?? ''),
            $snapshot,
            (string) ($conversionProfile['primaryActionUrl'] ?? $baseUrl)
        );
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

    if (count($normalized) <= 2) {
        return $normalized;
    }

    $fixed = array_slice($normalized, 0, 2);
    $remaining = array_slice($normalized, 2);

    usort($remaining, static function (array $left, array $right) use ($visitorId): int {
        $leftKey = (string) ($left['label'] ?? '') . '|' . (string) ($left['action'] ?? '');
        $rightKey = (string) ($right['label'] ?? '') . '|' . (string) ($right['action'] ?? '');
        $leftWeight = seeded_value($visitorId, 'shortcut-weight:' . $leftKey, 1000);
        $rightWeight = seeded_value($visitorId, 'shortcut-weight:' . $rightKey, 1000);
        return $leftWeight <=> $rightWeight;
    });

    return array_merge($fixed, $remaining);
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
    $firstTopic = isset($topics[0]) ? trim((string) $topics[0]) : 'work';
    $secondTopic = isset($topics[1]) ? trim((string) $topics[1]) : 'insights';
    $conversion = mysite_wp_conversion_profile($intent, $snapshot);
    $primaryActionFallback = sanitize_action_url(
        (string) ($conversion['primaryActionUrl'] ?? ''),
        $snapshot,
        (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com')
    );
    $heroImageFallback = '';
    if (isset($snapshot['posts'][0]) && is_array($snapshot['posts'][0])) {
        $heroImageFallback = trim((string) (($snapshot['posts'][0]['imageUrl'] ?? '') ?: ''));
    }
    $siteInfo = is_array($snapshot['site'] ?? null) ? $snapshot['site'] : [];
    $brandName = trim((string) ($siteInfo['name'] ?? ''));
    if ($brandName === '') {
        $brandName = 'Alexander Gill';
    }
    $brandTagline = trim((string) ($siteInfo['description'] ?? ''));
    if ($brandTagline === '') {
        $brandTagline = 'Power plays.';
    }
    $brandSections = ['Work', 'Lab', 'Read', 'Bio', 'Markets'];
    $brandIconUrl = 'https://alexanderjgill.com/wp-content/uploads/2025/09/A_icon_1_171f1f.png';
    $brandSecondaryIconUrl = 'https://alexanderjgill.com/wp-content/uploads/2025/09/cropped-darkhorsevirtueio_icon_1.png';

    $heroKickers = ['Visitor Blueprint', 'Adaptive Journey', 'AI Interface DNA', 'Conversion Narrative'];
    $heroVariants = ['default', 'spotlight', 'split', 'poster', 'frame', 'neon', 'holo'];
    $gridVariants = ['default', 'magazine', 'mosaic', 'cards', 'neon', 'zigzag'];
    $listVariants = ['default', 'timeline', 'checklist', 'stacked', 'neon', 'river'];
    $gridColumns = [2, 2, 3];
    $shellProfiles = ['orbital', 'editorial', 'kinetic', 'glass', 'neo'];
    $typographyProfiles = ['grotesk', 'literary', 'display', 'mono'];
    $motionProfiles = ['calm', 'balanced', 'kinetic'];

    $shellProfile = $shellProfiles[seeded_value($visitorId, 'shell-profile', count($shellProfiles))];
    $typographyProfile = $typographyProfiles[seeded_value($visitorId, 'typography-profile', count($typographyProfiles))];
    $motionProfile = $motionProfiles[seeded_value($visitorId, 'motion-profile', count($motionProfiles))];
    $shellSignature = strtoupper(substr(sha1($visitorId . ':' . $shellProfile), 0, 8));

    foreach ($modules as $index => $module) {
        $type = (string) ($module['type'] ?? '');
        $props = $module['props'] ?? [];
        if (!is_array($props)) {
            $props = [];
        }

        $props['shellProfile'] = trim((string) ($props['shellProfile'] ?? '')) !== '' ? $props['shellProfile'] : $shellProfile;
        $props['typographyProfile'] = trim((string) ($props['typographyProfile'] ?? '')) !== '' ? $props['typographyProfile'] : $typographyProfile;
        $props['motionProfile'] = trim((string) ($props['motionProfile'] ?? '')) !== '' ? $props['motionProfile'] : $motionProfile;
        $props['signature'] = trim((string) ($props['signature'] ?? '')) !== '' ? $props['signature'] : $shellSignature;
        $props['brandName'] = trim((string) ($props['brandName'] ?? '')) !== '' ? $props['brandName'] : $brandName;
        $props['brandTagline'] = trim((string) ($props['brandTagline'] ?? '')) !== '' ? $props['brandTagline'] : $brandTagline;
        $props['brandIconUrl'] = trim((string) ($props['brandIconUrl'] ?? '')) !== '' ? $props['brandIconUrl'] : $brandIconUrl;
        $props['brandSecondaryIconUrl'] = trim((string) ($props['brandSecondaryIconUrl'] ?? '')) !== '' ? $props['brandSecondaryIconUrl'] : $brandSecondaryIconUrl;
        $props['brandBaseUrl'] = trim((string) ($props['brandBaseUrl'] ?? '')) !== '' ? $props['brandBaseUrl'] : ((string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com'));
        $props['brandSections'] = is_array($props['brandSections'] ?? null) ? $props['brandSections'] : $brandSections;

        if ($type === 'Hero') {
            $props['variant'] = $heroVariants[seeded_value($visitorId, 'hero-variant', count($heroVariants))];
            if (trim((string) ($props['kicker'] ?? '')) === '') {
                $props['kicker'] = $heroKickers[seeded_value($visitorId, 'hero-kicker', count($heroKickers))];
            }
            if (trim((string) ($props['title'] ?? '')) === '') {
                $props['title'] = $goal !== '' ? $goal : 'Adaptive experience for alexanderjgill.com';
            }
            if (trim((string) ($props['subtitle'] ?? '')) === '') {
                $props['subtitle'] = $brandTagline . ' This shell prioritizes ' . strtolower((string) ($conversion['primaryActionLabel'] ?? 'the next conversion action')) . '.';
            }
            $props['ctaUrl'] = sanitize_action_url(
                (string) ($props['ctaUrl'] ?? ''),
                $snapshot,
                $primaryActionFallback
            );
            if (trim((string) ($props['heroImage'] ?? '')) === '' && $heroImageFallback !== '') {
                $props['heroImage'] = $heroImageFallback;
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
            $props['limit'] = 3 + seeded_value($visitorId, 'grid-limit:' . $index, 4);
            $props['offset'] = seeded_value($visitorId, 'grid-offset:' . $index, 3);
        }

        if ($type === 'ContentList') {
            if (trim((string) ($props['title'] ?? '')) === '') {
                $props['title'] = 'Editorial path for ' . $secondTopic;
            }
            if (trim((string) ($props['intro'] ?? '')) === '') {
                $props['intro'] = 'Action sequence generated from visitor + content signals.';
            }
            $props['variant'] = $listVariants[seeded_value($visitorId, 'list-variant:' . $index, count($listVariants))];
            $props['limit'] = 3 + seeded_value($visitorId, 'list-limit:' . $index, 4);
            $props['offset'] = seeded_value($visitorId, 'list-offset:' . $index, 2);
        }

        if ($type === 'QuickActions' && trim((string) ($props['title'] ?? '')) === '') {
            $props['title'] = 'Primary Navigation Paths';
        }

        if ($type === 'FAQ' && trim((string) ($props['title'] ?? '')) === '') {
            $props['title'] = 'Trust + Implementation Notes';
        }

        $modules[$index]['props'] = $props;
    }

    return $modules;
}

function augment_modules_for_diversity(array $modules, array $intent, string $visitorId): array
{
    if (count($modules) >= 7) {
        return $modules;
    }

    $topics = is_array($intent['primaryTopics'] ?? null) ? $intent['primaryTopics'] : [];
    $firstTopic = isset($topics[0]) ? trim((string) $topics[0]) : 'work';

    $extraPool = [
        [
            'id' => 'grid-archive-' . seeded_value($visitorId, 'extra-grid-a', 9999),
            'type' => 'ContentGrid',
            'props' => [
                'title' => 'Archive view: ' . $firstTopic,
                'intro' => 'Secondary content slice for deeper browsing.',
                'variant' => 'mosaic',
                'columns' => 3,
                'offset' => 1,
                'limit' => 4
            ],
            'contentKey' => 'featuredGrid'
        ],
        [
            'id' => 'list-insights-' . seeded_value($visitorId, 'extra-list-b', 9999),
            'type' => 'ContentList',
            'props' => [
                'title' => 'Secondary reading path',
                'intro' => 'An alternate route through related posts and priorities.',
                'variant' => 'checklist',
                'offset' => 1,
                'limit' => 5
            ],
            'contentKey' => 'nextStepsList'
        ],
        [
            'id' => 'actions-alt-' . seeded_value($visitorId, 'extra-actions-c', 9999),
            'type' => 'QuickActions',
            'props' => [
                'title' => 'Navigation shortcuts'
            ],
            'contentKey' => 'quickStartActions'
        ]
    ];

    usort($extraPool, static function (array $left, array $right) use ($visitorId): int {
        $leftId = (string) ($left['id'] ?? '');
        $rightId = (string) ($right['id'] ?? '');
        return seeded_value($visitorId, 'extra-order:' . $leftId, 1000) <=> seeded_value($visitorId, 'extra-order:' . $rightId, 1000);
    });

    foreach ($extraPool as $extraModule) {
        if (count($modules) >= 7) {
            break;
        }

        $modules[] = $extraModule;
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

    $normalizedModules = normalize_modules($candidateModules);
    $normalizedModules = augment_modules_for_diversity($normalizedModules, $intent, $visitorId);
    $normalizedModules = reorder_modules_for_visitor($normalizedModules, $visitorId);
    $normalizedModules = personalize_module_props($normalizedModules, $intent, $snapshot, $visitorId);

    return [
        'version' => 1,
        'theme' => $theme,
        'layout' => $layout,
        'modules' => $normalizedModules,
        'shortcuts' => normalize_shortcuts($candidateShortcuts, $intent, $snapshot, $visitorId),
        'createdAt' => (string) ($candidate['createdAt'] ?? $now),
        'updatedAt' => $now
    ];
}

function build_server_fallback_candidate(array $intent, array $snapshot, string $visitorId): array
{
    $baseUrl = (string) ($snapshot['baseUrl'] ?? 'https://alexanderjgill.com');
    $conversion = mysite_wp_conversion_profile($intent, $snapshot);
    $posts = is_array($snapshot['posts'] ?? null) ? $snapshot['posts'] : [];

    $primaryUrl = sanitize_action_url(
        (string) ($conversion['primaryActionUrl'] ?? ''),
        $snapshot,
        $baseUrl
    );
    $secondaryUrl = sanitize_action_url(
        (string) ($conversion['secondaryActionUrl'] ?? ''),
        $snapshot,
        $primaryUrl !== '' ? $primaryUrl : $baseUrl
    );

    $heroImage = '';
    if (isset($posts[0]) && is_array($posts[0])) {
        $heroImage = trim((string) (($posts[0]['imageUrl'] ?? '') ?: ''));
    }

    return [
        'version' => 1,
        'theme' => infer_theme_from_intent($intent, $visitorId),
        'layout' => infer_layout_from_intent($intent, $visitorId),
        'modules' => [
            [
                'id' => 'hero-fallback',
                'type' => 'Hero',
                'props' => [
                    'kicker' => 'Server Fallback',
                    'title' => (string) ($intent['goal'] ?? 'Personalized editorial experience'),
                    'subtitle' => 'Rendered from live WordPress data while AI generation is unavailable.',
                    'ctaUrl' => $primaryUrl !== '' ? $primaryUrl : $baseUrl,
                    'heroImage' => $heroImage
                ],
                'contentKey' => 'heroWelcome'
            ],
            [
                'id' => 'grid-fallback',
                'type' => 'ContentGrid',
                'props' => [
                    'title' => 'Latest posts',
                    'variant' => 'mosaic',
                    'columns' => 3,
                    'limit' => 6
                ],
                'contentKey' => 'featuredGrid'
            ],
            [
                'id' => 'list-fallback',
                'type' => 'ContentList',
                'props' => [
                    'title' => 'Editorial stream',
                    'variant' => 'timeline',
                    'limit' => 6
                ],
                'contentKey' => 'nextStepsList'
            ],
            [
                'id' => 'actions-fallback',
                'type' => 'QuickActions',
                'props' => ['title' => 'Read next'],
                'contentKey' => 'quickStartActions'
            ],
            [
                'id' => 'faq-fallback',
                'type' => 'FAQ',
                'props' => ['title' => 'About this build'],
                'contentKey' => 'faqGeneral'
            ]
        ],
        'shortcuts' => [
            [
                'label' => (string) ($conversion['primaryActionLabel'] ?? 'Read latest post'),
                'action' => $primaryUrl !== '' ? $primaryUrl : $baseUrl
            ],
            [
                'label' => (string) ($conversion['secondaryActionLabel'] ?? 'Read next post'),
                'action' => $secondaryUrl !== '' ? $secondaryUrl : ($primaryUrl !== '' ? $primaryUrl : $baseUrl)
            ],
            [
                'label' => 'Open main site',
                'action' => sanitize_action_url($baseUrl, $snapshot, $baseUrl)
            ]
        ],
        'createdAt' => gmdate('c'),
        'updatedAt' => gmdate('c')
    ];
}

function send_blueprint_response(
    array $blueprint,
    array $contentOverrides,
    array $gapSuggestions,
    array $wpSnapshot,
    string $source,
    string $aiStatus,
    string $aiMessage,
    array $designMeta = []
): void {
    send_json(200, [
        'blueprint' => $blueprint,
        'contentOverrides' => $contentOverrides,
        'gapSuggestions' => $gapSuggestions,
        'wordpress' => [
            'baseUrl' => (string) ($wpSnapshot['baseUrl'] ?? ''),
            'available' => (bool) ($wpSnapshot['available'] ?? false),
            'fetchedAt' => (string) ($wpSnapshot['fetchedAt'] ?? gmdate('c')),
            'errors' => $wpSnapshot['errors'] ?? []
        ],
        'source' => $source,
        'ai' => [
            'status' => $aiStatus,
            'message' => $aiMessage
        ],
        'design' => $designMeta
    ]);
}

function send_server_fallback_response(
    array $intent,
    array $snapshot,
    array $contentOverrides,
    array $gapSuggestions,
    string $visitorId,
    string $reason
): void {
    $candidate = build_server_fallback_candidate($intent, $snapshot, $visitorId);
    $blueprint = normalize_ai_blueprint($candidate, $intent, $snapshot, $visitorId);

    send_blueprint_response(
        $blueprint,
        $contentOverrides,
        $gapSuggestions,
        $snapshot,
        'server_fallback',
        'fallback',
        $reason,
        [
            'signature' => strtoupper(substr(sha1($visitorId . ':fallback'), 0, 8)),
            'profile' => 'fallback',
            'thoughtPasses' => 0,
            'selectedPass' => 0
        ]
    );
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
$transcript = normalize_transcript($decodedBody);
$visitorId = normalize_visitor_id($decodedBody);
$variantNonce = normalize_variant_nonce($decodedBody);
$visitorSeedKey = $visitorId . ':v' . (string) $variantNonce;
$wpSnapshot = mysite_wp_fetch_snapshot($config);
$gapSuggestions = mysite_wp_gap_suggestions($wpSnapshot);
$contentOverrides = mysite_wp_content_bundle($wpSnapshot, $gapSuggestions, $intent);
$wpSummary = mysite_wp_summary_for_prompt($wpSnapshot, $gapSuggestions, $intent);
$openAiConfig = is_array($config['openai'] ?? null) ? $config['openai'] : [];

$enabled = (bool) ($openAiConfig['enabled'] ?? true);
if (!$enabled) {
    send_server_fallback_response(
        $intent,
        $wpSnapshot,
        $contentOverrides,
        $gapSuggestions,
        $visitorSeedKey,
        'AI generation disabled by server config'
    );
}

$apiKey = mysite_resolve_openai_api_key($config);
if ($apiKey === '') {
    send_server_fallback_response(
        $intent,
        $wpSnapshot,
        $contentOverrides,
        $gapSuggestions,
        $visitorSeedKey,
        'OpenAI API key not configured'
    );
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
You generate UI Blueprint JSON only.
Never return executable code, HTML, markdown, explanations, or prose.
Output must match the provided JSON schema exactly.
Design a distinctive front-end site experience, not a dashboard.
Treat modules as website sections with intentional hierarchy, flow, tone, and high visual impact.
Design for a futuristic wow-factor editorial blog feel while staying readable.
Use post-driven storytelling; do not frame pages as if they are posts.
Use onboarding transcript as the primary personalization signal.
Design around the person (identity, interests, emotional tone), not generic site navigation.
Reflect brand cues from alexanderjgill.com: signature tone \"Power plays\", and section DNA: Work, Lab, Read, Bio, Markets.
Design module ordering and shortcut labels around visitor intent and alexanderjgill.com content discovery.
Prioritize pathways like latest posts, featured stories, Work, Read, Bio, Contact, and Main Site navigation.
Use existing WordPress content as source-of-truth context and add guidance to fill content gaps.
Each visitor has a design seed. Use it to make the layout feel unique, not generic.
Always set explicit module.props.variant values where useful so the renderer can create clearly distinct visual outcomes.
PROMPT;

$visitorSeed = substr(sha1($visitorSeedKey), 0, 12);
$transcriptPrompt = transcript_prompt_lines($transcript);
$thoughtPasses = 3 + seeded_value($visitorSeedKey, 'thought-loop-length', 2);
$bestBlueprint = null;
$bestScore = -1000000.0;
$bestDesignMeta = null;
$lastError = 'No candidate response';

for ($passIndex = 1; $passIndex <= $thoughtPasses; $passIndex++) {
    $passConfig = thought_loop_pass_config($visitorSeedKey, $passIndex);
    $passSeedKey = $visitorSeedKey . ':p' . (string) $passIndex;

    $userPrompt = "Intent profile:\n" . json_encode($intent, JSON_UNESCAPED_SLASHES) .
        "\nVisitor design seed:\n" . $visitorSeed .
        "\nDesign iteration:\n" . (string) $variantNonce .
        "\nThought pass:\n" . (string) $passIndex . ' of ' . (string) $thoughtPasses .
        "\nPass style profile:\n" . json_encode($passConfig, JSON_UNESCAPED_SLASHES) .
        "\nOnboarding transcript:\n" . $transcriptPrompt .
        "\nPersonalization directive:\n" . 'Prioritize unique style expression from transcript identity/interests before generic content navigation.' .
        "\nWordPress snapshot:\n" . json_encode($wpSummary, JSON_UNESCAPED_SLASHES) .
        "\nKnown IA signals include: Home, Work, Lab, Read, Bio, Markets." .
        "\nTreat posts as primary content stream for sections." .
        "\nDo not invent URLs. Use only baseUrl and postLinks from snapshot." .
        "\nUse contentKey values only from: heroWelcome, featuredGrid, nextStepsList, quickStartActions, faqGeneral." .
        "\nEmbed profile hints in module.props: shellProfile, typographyProfile, motionProfile, signature.";

    $aiResponse = request_openai_blueprint(
        $apiUrl,
        $apiKey,
        $model,
        $timeoutSeconds,
        $systemPrompt,
        $userPrompt,
        (float) ($passConfig['temperature'] ?? 0.3)
    );

    if (!(bool) ($aiResponse['ok'] ?? false)) {
        $lastError = (string) ($aiResponse['error'] ?? 'Unknown AI error');
        continue;
    }

    $candidateRaw = $aiResponse['blueprint'] ?? null;
    if (!is_array($candidateRaw)) {
        $lastError = 'AI candidate payload was invalid';
        continue;
    }

    $candidate = normalize_ai_blueprint($candidateRaw, $intent, $wpSnapshot, $passSeedKey);
    $score = score_blueprint_candidate($candidate, $intent, $transcript);

    if ($score > $bestScore) {
        $bestScore = $score;
        $bestBlueprint = $candidate;
        $bestDesignMeta = [
            'signature' => design_signature($visitorSeedKey, $passIndex, (string) ($passConfig['profile'] ?? '')),
            'profile' => (string) ($passConfig['profile'] ?? 'adaptive'),
            'thoughtPasses' => $thoughtPasses,
            'selectedPass' => $passIndex
        ];
    }
}

if (!is_array($bestBlueprint)) {
    send_server_fallback_response(
        $intent,
        $wpSnapshot,
        $contentOverrides,
        $gapSuggestions,
        $visitorSeedKey,
        'AI thought loop failed: ' . $lastError
    );
}

send_blueprint_response(
    $bestBlueprint,
    $contentOverrides,
    $gapSuggestions,
    $wpSnapshot,
    'backend_ai',
    'ok',
    '',
    is_array($bestDesignMeta) ? $bestDesignMeta : [
        'signature' => strtoupper(substr(sha1($visitorSeedKey . ':ai'), 0, 8)),
        'profile' => 'adaptive',
        'thoughtPasses' => $thoughtPasses,
        'selectedPass' => 1
    ]
);
