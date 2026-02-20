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

$postIdRaw = $_GET['id'] ?? '';
$postId = (int) $postIdRaw;
if ($postId <= 0) {
    send_json(400, ['error' => 'Missing or invalid post id']);
}

$config = mysite_load_server_config();
$result = mysite_wp_fetch_post_detail($config, $postId);

if (!(bool) ($result['available'] ?? false)) {
    send_json(404, [
        'available' => false,
        'post' => null,
        'related' => [],
        'errors' => $result['errors'] ?? ['Post not found']
    ]);
}

send_json(200, [
    'available' => true,
    'post' => $result['post'] ?? null,
    'related' => $result['related'] ?? [],
    'errors' => $result['errors'] ?? []
]);
