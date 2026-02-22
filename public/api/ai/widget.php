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

function as_array($value): array
{
    return is_array($value) ? $value : [];
}

function clean_text($value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }

    $trimmed = trim($value);
    return $trimmed === '' ? $fallback : $trimmed;
}

function normalize_widget(array $decoded): array
{
    $widget = as_array($decoded['widget'] ?? []);

    $id = clean_text($widget['id'] ?? '');
    $type = strtolower(clean_text($widget['type'] ?? ''));
    $title = clean_text($widget['title'] ?? 'Widget');
    $config = as_array($widget['config'] ?? []);
    $html = clean_text($widget['html'] ?? '');

    if (!in_array($type, ['weather', 'horoscope', 'fashion', 'sports', 'customhtml', 'customHtml'], true)) {
        $type = 'customHtml';
    }

    if ($type === 'customhtml') {
        $type = 'customHtml';
    }

    if ($id === '') {
        $id = 'widget-unknown';
    }

    $cleanConfig = [];
    foreach ($config as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }

        $cleanConfig[$key] = $trimmed;
    }

    return [
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'config' => $cleanConfig,
        'html' => $html
    ];
}

function sanitize_html(string $html): string
{
    $clean = trim($html);

    $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $clean) ?? '';
    $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean) ?? '';
    $clean = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $clean) ?? '';
    $clean = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $clean) ?? '';
    $clean = preg_replace('/<embed\b[^>]*>/is', '', $clean) ?? $clean;
    $clean = preg_replace('/<link\b[^>]*>/is', '', $clean) ?? $clean;
    $clean = preg_replace('/<meta\b[^>]*>/is', '', $clean) ?? $clean;

    $clean = preg_replace('/\son[a-z]+\s*=\s*("|\')(.*?)\1/i', '', $clean) ?? $clean;
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1="#"', $clean) ?? $clean;

    return $clean;
}

function http_get_json(string $url, int $timeoutSeconds): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $result = curl_exec($curl);
    if ($result === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return ['ok' => false, 'status' => 0, 'error' => $error, 'data' => []];
    }

    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $decoded = json_decode((string) $result, true);
    if ($status >= 400 || !is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . (string) $status, 'data' => []];
    }

    return ['ok' => true, 'status' => $status, 'error' => '', 'data' => $decoded];
}

function weather_code_label(int $code): string
{
    $map = [
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Depositing rime fog',
        51 => 'Light drizzle',
        53 => 'Moderate drizzle',
        55 => 'Dense drizzle',
        61 => 'Slight rain',
        63 => 'Moderate rain',
        65 => 'Heavy rain',
        71 => 'Slight snow',
        73 => 'Moderate snow',
        75 => 'Heavy snow',
        80 => 'Rain showers',
        81 => 'Rain showers',
        82 => 'Violent rain showers',
        95 => 'Thunderstorm'
    ];

    return $map[$code] ?? 'Weather update available';
}

function fetch_weather_payload(string $city, int $timeoutSeconds): array
{
    $cityQuery = rawurlencode($city);
    $geo = http_get_json('https://geocoding-api.open-meteo.com/v1/search?name=' . $cityQuery . '&count=1&language=en&format=json', $timeoutSeconds);

    if (!(bool) ($geo['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Unable to locate city for weather lookup.'
        ];
    }

    $results = as_array($geo['data']['results'] ?? []);
    if (count($results) === 0 || !is_array($results[0])) {
        return [
            'ok' => false,
            'error' => 'No weather location found for that city.'
        ];
    }

    $first = $results[0];
    $lat = (float) ($first['latitude'] ?? 0.0);
    $lon = (float) ($first['longitude'] ?? 0.0);
    $resolvedCity = clean_text($first['name'] ?? $city, $city);
    $resolvedCountry = clean_text($first['country'] ?? '', '');

    $forecastUrl = 'https://api.open-meteo.com/v1/forecast?latitude=' . rawurlencode((string) $lat) .
        '&longitude=' . rawurlencode((string) $lon) .
        '&current=temperature_2m,apparent_temperature,weather_code,wind_speed_10m' .
        '&daily=temperature_2m_max,temperature_2m_min&timezone=auto';

    $forecast = http_get_json($forecastUrl, $timeoutSeconds);
    if (!(bool) ($forecast['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Unable to fetch weather forecast right now.'
        ];
    }

    $current = as_array($forecast['data']['current'] ?? []);
    $daily = as_array($forecast['data']['daily'] ?? []);
    $highSeries = isset($daily['temperature_2m_max']) && is_array($daily['temperature_2m_max']) ? $daily['temperature_2m_max'] : [];
    $lowSeries = isset($daily['temperature_2m_min']) && is_array($daily['temperature_2m_min']) ? $daily['temperature_2m_min'] : [];

    $weatherCode = (int) ($current['weather_code'] ?? 0);
    $cityLabel = $resolvedCountry !== '' ? ($resolvedCity . ', ' . $resolvedCountry) : $resolvedCity;

    return [
        'ok' => true,
        'payload' => [
            'city' => $cityLabel,
            'temperatureC' => (float) ($current['temperature_2m'] ?? 0.0),
            'feelsLikeC' => (float) ($current['apparent_temperature'] ?? 0.0),
            'windKph' => (float) ($current['wind_speed_10m'] ?? 0.0),
            'highC' => (float) ($highSeries[0] ?? 0.0),
            'lowC' => (float) ($lowSeries[0] ?? 0.0),
            'condition' => weather_code_label($weatherCode)
        ]
    ];
}

function league_to_espn_path(string $league): array
{
    $normalized = strtoupper(trim($league));
    if ($normalized === 'NFL') {
        return ['sport' => 'football', 'league' => 'nfl', 'label' => 'NFL'];
    }

    if ($normalized === 'NBA') {
        return ['sport' => 'basketball', 'league' => 'nba', 'label' => 'NBA'];
    }

    if ($normalized === 'MLB') {
        return ['sport' => 'baseball', 'league' => 'mlb', 'label' => 'MLB'];
    }

    if ($normalized === 'EPL') {
        return ['sport' => 'soccer', 'league' => 'eng.1', 'label' => 'EPL'];
    }

    return ['sport' => 'hockey', 'league' => 'nhl', 'label' => 'NHL'];
}

function fetch_sports_payload(string $leagueHint, int $timeoutSeconds): array
{
    $path = league_to_espn_path($leagueHint);
    $url = 'https://site.api.espn.com/apis/site/v2/sports/' . $path['sport'] . '/' . $path['league'] . '/scoreboard';
    $response = http_get_json($url, $timeoutSeconds);

    if (!(bool) ($response['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Unable to load sports scoreboard.'
        ];
    }

    $events = as_array($response['data']['events'] ?? []);
    $items = [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $competition = as_array(as_array($event['competitions'] ?? [])[0] ?? []);
        $competitors = as_array($competition['competitors'] ?? []);
        if (count($competitors) < 2) {
            continue;
        }

        $home = as_array($competitors[0]);
        $away = as_array($competitors[1]);

        $homeName = clean_text(as_array($home['team'] ?? [])['displayName'] ?? 'Home', 'Home');
        $awayName = clean_text(as_array($away['team'] ?? [])['displayName'] ?? 'Away', 'Away');
        $homeScore = clean_text($home['score'] ?? '0', '0');
        $awayScore = clean_text($away['score'] ?? '0', '0');

        $status = clean_text(as_array($competition['status'] ?? [])['type']['shortDetail'] ?? 'Scheduled', 'Scheduled');

        $items[] = $awayName . ' ' . $awayScore . ' - ' . $homeScore . ' ' . $homeName . ' (' . $status . ')';

        if (count($items) >= 4) {
            break;
        }
    }

    if (count($items) === 0) {
        $items[] = 'No live games found at the moment.';
    }

    return [
        'ok' => true,
        'payload' => [
            'league' => $path['label'],
            'items' => $items
        ]
    ];
}

function ai_short_text(array $config, string $systemPrompt, string $userPrompt, string $fallback): array
{
    $openAiConfig = as_array($config['openai'] ?? []);
    $enabled = (bool) ($openAiConfig['enabled'] ?? true);
    $apiKey = mysite_resolve_openai_api_key($config);

    if (!$enabled || $apiKey === '') {
        return ['text' => $fallback, 'source' => 'fallback'];
    }

    $model = clean_text($openAiConfig['model'] ?? 'gpt-4o-mini', 'gpt-4o-mini');
    $apiUrl = clean_text($openAiConfig['api_url'] ?? 'https://api.openai.com/v1/chat/completions', 'https://api.openai.com/v1/chat/completions');
    $timeoutSeconds = (int) ($openAiConfig['timeout_seconds'] ?? 30);
    if ($timeoutSeconds < 5) {
        $timeoutSeconds = 5;
    }
    if ($timeoutSeconds > 120) {
        $timeoutSeconds = 120;
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => 0.4,
        'max_tokens' => 110
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
        return ['text' => $fallback, 'source' => 'fallback'];
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($statusCode >= 400) {
        return ['text' => $fallback, 'source' => 'fallback'];
    }

    $response = json_decode((string) $result, true);
    $content = clean_text($response['choices'][0]['message']['content'] ?? '');
    if ($content === '') {
        return ['text' => $fallback, 'source' => 'fallback'];
    }

    return ['text' => $content, 'source' => 'ai'];
}

function fallback_fashion_items(string $signature, string $mood): array
{
    $base = [
        $mood . ': tonal layers + one statement accessory',
        $mood . ': sharp outerwear with relaxed basics',
        $mood . ': monochrome palette with texture contrast',
        $mood . ': utility core with clean silhouettes'
    ];

    $shift = hash('crc32', $signature . ':' . $mood);
    $offset = hexdec(substr($shift, 0, 2)) % count($base);
    return array_slice(array_merge(array_slice($base, $offset), array_slice($base, 0, $offset)), 0, 3);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, ['error' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode($rawBody ?: '{}', true);
if (!is_array($decodedBody)) {
    send_json(400, ['error' => 'Invalid JSON request body']);
}

$widget = normalize_widget($decodedBody);
$signature = clean_text($decodedBody['signature'] ?? 'SIG-LOCAL', 'SIG-LOCAL');
$config = mysite_load_server_config();

$timeoutSeconds = 12;
$wpConfig = as_array($config['wordpress'] ?? []);
if (isset($wpConfig['timeout_seconds'])) {
    $timeoutSeconds = max(4, min(20, (int) $wpConfig['timeout_seconds']));
}

$source = 'fallback';
$payload = [];

if ($widget['type'] === 'weather') {
    $city = clean_text($widget['config']['city'] ?? '', 'New York');
    $weather = fetch_weather_payload($city, $timeoutSeconds);

    if (!(bool) ($weather['ok'] ?? false)) {
        $payload = [
            'city' => $city,
            'temperatureC' => '--',
            'feelsLikeC' => '--',
            'windKph' => '--',
            'highC' => '--',
            'lowC' => '--',
            'condition' => clean_text($weather['error'] ?? 'Weather data unavailable.', 'Weather data unavailable.'),
            'insight' => 'Try refresh in a moment for an updated weather read.'
        ];
        $source = 'fallback';
    } else {
        $payload = as_array($weather['payload'] ?? []);
        $insight = ai_short_text(
            $config,
            'You generate concise weather guidance for a dashboard widget. Keep to one short sentence.',
            'City: ' . clean_text($payload['city'] ?? $city, $city) .
            ', temperature C: ' . (string) ($payload['temperatureC'] ?? '') .
            ', condition: ' . clean_text($payload['condition'] ?? '', '') .
            '. Give one practical insight in under 20 words.',
            'Today looks manageable. Use this to plan your next focused work block.'
        );
        $payload['insight'] = $insight['text'];
        $source = $insight['source'] === 'ai' ? 'ai' : 'external';
    }
} elseif ($widget['type'] === 'sports') {
    $league = clean_text($widget['config']['league'] ?? '', 'NHL');
    $sports = fetch_sports_payload($league, $timeoutSeconds);

    if (!(bool) ($sports['ok'] ?? false)) {
        $payload = [
            'league' => $league,
            'items' => ['Scoreboard unavailable right now.'],
            'text' => 'Try refresh shortly to pull the latest match data.'
        ];
        $source = 'fallback';
    } else {
        $payload = as_array($sports['payload'] ?? []);
        $summary = ai_short_text(
            $config,
            'You summarize sports snapshots in one sentence for a dashboard widget.',
            'League: ' . clean_text($payload['league'] ?? $league, $league) .
            '. Lines: ' . implode(' | ', as_array($payload['items'] ?? [])) .
            '. Write one sentence under 22 words.',
            'Momentum is shifting fast; check one matchup now and use it as your quick pulse update.'
        );
        $payload['text'] = $summary['text'];
        $source = $summary['source'] === 'ai' ? 'ai' : 'external';
    }
} elseif ($widget['type'] === 'fashion') {
    $mood = clean_text($widget['config']['mood'] ?? '', 'street-minimal');
    $items = fallback_fashion_items($signature, $mood);
    $summary = ai_short_text(
        $config,
        'You are a fashion trend briefing assistant. Keep it practical and short.',
        'Mood: ' . $mood . '. Suggest a concise direction sentence for a dashboard widget.',
        'Anchor the look with one strong piece, then keep the rest clean and tonal.'
    );

    $payload = [
        'mood' => $mood,
        'items' => $items,
        'text' => $summary['text']
    ];
    $source = $summary['source'];
} elseif ($widget['type'] === 'horoscope') {
    $sign = strtolower(clean_text($widget['config']['sign'] ?? '', 'aries'));
    $today = gmdate('Y-m-d');
    $summary = ai_short_text(
        $config,
        'You write short motivational horoscope insights for productivity and creativity.',
        'Sign: ' . $sign . ', date: ' . $today . '. Provide one concise insight under 20 words.',
        'Set one clear priority and protect focus windows; momentum builds fast when you avoid context switching.'
    );

    $payload = [
        'sign' => $sign,
        'text' => $summary['text']
    ];
    $source = $summary['source'];
} else {
    $html = sanitize_html(clean_text($widget['html'] ?? '', '<div><p>No HTML provided.</p></div>'));
    $summary = ai_short_text(
        $config,
        'You are an HTML widget UX critic. Return one short improvement suggestion.',
        'Widget title: ' . $widget['title'] . '. HTML: ' . $html . '. Give one concise improvement suggestion.',
        'Keep copy concise and include one clear action line to guide the user.'
    );

    $payload = [
        'html' => $html,
        'text' => $summary['text']
    ];
    $source = $summary['source'];
}

send_json(200, [
    'widgetId' => $widget['id'],
    'type' => $widget['type'],
    'refreshedAt' => gmdate('c'),
    'source' => $source,
    'payload' => $payload
]);
