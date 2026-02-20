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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(405, ['error' => 'Method not allowed']);
}

$config = mysite_load_server_config();
$wpSnapshot = mysite_wp_fetch_snapshot($config);
$gapSuggestions = mysite_wp_gap_suggestions($wpSnapshot);
$defaultIntent = [
    'goal' => 'Create a personalized headless front-end experience for alexanderjgill.com',
    'vibe' => 'minimal',
    'density' => 'medium',
    'primaryTopics' => ['Work', 'Read', 'Bio']
];
$contentOverrides = mysite_wp_content_bundle($wpSnapshot, $gapSuggestions, $defaultIntent);

send_json(200, [
    'contentOverrides' => $contentOverrides,
    'gapSuggestions' => $gapSuggestions,
    'wordpress' => [
        'baseUrl' => (string) ($wpSnapshot['baseUrl'] ?? ''),
        'available' => (bool) ($wpSnapshot['available'] ?? false),
        'fetchedAt' => (string) ($wpSnapshot['fetchedAt'] ?? gmdate('c')),
        'errors' => $wpSnapshot['errors'] ?? []
    ]
]);
