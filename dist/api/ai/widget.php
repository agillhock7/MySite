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
            'Accept: application/json',
            'User-Agent: MySiteWidgetRuntime/1.0'
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

function celsius_to_fahrenheit(float $valueC): float
{
    return ($valueC * 9 / 5) + 32;
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

function fallback_prompt_widget_answer(string $prompt): string
{
    $trimmed = preg_replace('/\s+/', ' ', trim($prompt)) ?? '';
    if ($trimmed === '') {
        return 'Add a clearer prompt and refresh this widget.';
    }

    if (strlen($trimmed) > 170) {
        $trimmed = substr($trimmed, 0, 167) . '...';
    }

    return 'Focus on this now: ' . $trimmed;
}

function extract_weather_location_from_prompt(string $prompt): string
{
    $text = trim($prompt);
    if ($text === '') {
        return '';
    }

    if (preg_match('/(?:weather|forecast|temperature)\s+(?:in|for)\s+([a-zA-Z][a-zA-Z\s\.\-]{1,70})/i', $text, $matches) === 1) {
        $raw = trim($matches[1]);
        $raw = preg_replace('/\b(with|and|using|from)\b.*$/i', '', $raw) ?? $raw;
        return trim($raw, " \t\n\r\0\x0B.,;:!?");
    }

    return '';
}

function detect_crypto_symbol_from_prompt(string $prompt): string
{
    $normalized = strtolower($prompt);
    if (strpos($normalized, 'bitcoin') !== false || preg_match('/\bbtc\b/i', $normalized) === 1) {
        return 'bitcoin';
    }

    if (strpos($normalized, 'ethereum') !== false || preg_match('/\beth\b/i', $normalized) === 1) {
        return 'ethereum';
    }

    return '';
}

function fetch_crypto_price_payload(string $coinId, int $timeoutSeconds): array
{
    $supported = ['bitcoin', 'ethereum'];
    if (!in_array($coinId, $supported, true)) {
        $coinId = 'bitcoin';
    }

    $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($coinId) . '&vs_currencies=usd&include_24hr_change=true&include_last_updated_at=true';
    $response = http_get_json($url, $timeoutSeconds);
    if (!(bool) ($response['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'Unable to fetch crypto pricing right now.'
        ];
    }

    $root = as_array($response['data'][$coinId] ?? []);
    if (count($root) === 0) {
        return [
            'ok' => false,
            'error' => 'No crypto pricing data returned.'
        ];
    }

    $priceUsd = isset($root['usd']) ? (float) $root['usd'] : 0.0;
    $change24 = isset($root['usd_24h_change']) ? (float) $root['usd_24h_change'] : 0.0;
    $updatedAtUnix = isset($root['last_updated_at']) ? (int) $root['last_updated_at'] : 0;
    $updatedAtIso = $updatedAtUnix > 0 ? gmdate('c', $updatedAtUnix) : gmdate('c');

    return [
        'ok' => true,
        'payload' => [
            'coinId' => $coinId,
            'symbol' => $coinId === 'bitcoin' ? 'BTC' : 'ETH',
            'priceUsd' => $priceUsd,
            'change24hPct' => $change24,
            'updatedAt' => $updatedAtIso
        ]
    ];
}

function is_image_prompt(string $prompt): bool
{
    return preg_match('/\b(image|illustration|render|draw|logo|poster|photo|artwork|cover art)\b/i', $prompt) === 1;
}

function prompt_svg_placeholder_data_uri(string $prompt): string
{
    $title = trim(preg_replace('/\s+/', ' ', $prompt) ?? '');
    if ($title === '') {
        $title = 'Image pending';
    }

    if (strlen($title) > 80) {
        $title = substr($title, 0, 77) . '...';
    }

    $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">' .
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#022c22"/><stop offset="100%" stop-color="#0f172a"/></linearGradient></defs>' .
        '<rect width="1280" height="720" fill="url(#g)"/>' .
        '<circle cx="180" cy="130" r="160" fill="rgba(16,185,129,0.24)"/>' .
        '<circle cx="1080" cy="620" r="220" fill="rgba(22,163,74,0.18)"/>' .
        '<rect x="86" y="90" width="1108" height="540" rx="26" fill="rgba(2,6,23,0.56)" stroke="rgba(110,231,183,0.45)" stroke-width="2"/>' .
        '<text x="130" y="215" fill="#a7f3d0" font-family="monospace" font-size="32">AI IMAGE RUNTIME</text>' .
        '<text x="130" y="300" fill="#d1fae5" font-family="monospace" font-size="42">' . $escapedTitle . '</text>' .
        '<text x="130" y="380" fill="#86efac" font-family="monospace" font-size="28">Prompt processed via multimodal pipeline</text>' .
        '</svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

function fetch_generated_image_payload(string $prompt, array $config, int $timeoutSeconds): array
{
    $openAiConfig = as_array($config['openai'] ?? []);
    $enabled = (bool) ($openAiConfig['enabled'] ?? true);
    $apiKey = mysite_resolve_openai_api_key($config);

    if (!$enabled || $apiKey === '') {
        return [
            'ok' => true,
            'source' => 'fallback',
            'payload' => [
                'imageUrl' => prompt_svg_placeholder_data_uri($prompt),
                'provider' => 'fallback',
                'model' => 'placeholder'
            ]
        ];
    }

    $imageModel = clean_text($openAiConfig['image_model'] ?? 'gpt-image-1', 'gpt-image-1');
    $imageApiUrl = clean_text($openAiConfig['images_api_url'] ?? 'https://api.openai.com/v1/images/generations', 'https://api.openai.com/v1/images/generations');

    $requestPayload = [
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
        CURLOPT_POSTFIELDS => json_encode($requestPayload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeoutSeconds
    ]);

    $result = curl_exec($curl);
    if ($result === false) {
        curl_close($curl);
        return [
            'ok' => true,
            'source' => 'fallback',
            'payload' => [
                'imageUrl' => prompt_svg_placeholder_data_uri($prompt),
                'provider' => 'fallback',
                'model' => 'placeholder'
            ]
        ];
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($statusCode >= 400) {
        return [
            'ok' => true,
            'source' => 'fallback',
            'payload' => [
                'imageUrl' => prompt_svg_placeholder_data_uri($prompt),
                'provider' => 'fallback',
                'model' => 'placeholder'
            ]
        ];
    }

    $decoded = json_decode((string) $result, true);
    $imageData = as_array(as_array($decoded['data'] ?? [])[0] ?? []);
    $url = clean_text($imageData['url'] ?? '');
    $b64 = clean_text($imageData['b64_json'] ?? '');

    if ($url === '' && $b64 !== '') {
        $url = 'data:image/png;base64,' . $b64;
    }

    if ($url === '') {
        $url = prompt_svg_placeholder_data_uri($prompt);
        $source = 'fallback';
        $provider = 'fallback';
        $imageModel = 'placeholder';
    } else {
        $source = 'ai';
        $provider = 'openai';
    }

    return [
        'ok' => true,
        'source' => $source,
        'payload' => [
            'imageUrl' => $url,
            'provider' => $provider,
            'model' => $imageModel
        ]
    ];
}

function extract_time_location_from_prompt(string $prompt): string
{
    $text = trim($prompt);
    if ($text === '') {
        return '';
    }

    if (preg_match('/(?:current|local)?\s*time\s+(?:in|for)\s+([a-zA-Z][a-zA-Z\s\.\-]{1,70})/i', $text, $matches) === 1) {
        $raw = trim($matches[1]);
        $raw = preg_replace('/\b(with|and|using|from)\b.*$/i', '', $raw) ?? $raw;
        return trim($raw, " \t\n\r\0\x0B.,;:!?");
    }

    return '';
}

function resolve_timezone_from_location(string $location, int $timeoutSeconds): array
{
    $normalized = strtolower(trim($location));
    if ($normalized === '') {
        return ['ok' => false, 'timezone' => '', 'label' => '', 'warning' => 'Location missing.'];
    }

    $aliases = [
        'saudi arabia' => ['timezone' => 'Asia/Riyadh', 'label' => 'Saudi Arabia'],
        'ksa' => ['timezone' => 'Asia/Riyadh', 'label' => 'Saudi Arabia'],
        'riyadh' => ['timezone' => 'Asia/Riyadh', 'label' => 'Riyadh, Saudi Arabia'],
        'jeddah' => ['timezone' => 'Asia/Riyadh', 'label' => 'Jeddah, Saudi Arabia'],
        'dubai' => ['timezone' => 'Asia/Dubai', 'label' => 'Dubai, United Arab Emirates'],
        'london' => ['timezone' => 'Europe/London', 'label' => 'London, United Kingdom'],
        'new york' => ['timezone' => 'America/New_York', 'label' => 'New York, United States'],
        'los angeles' => ['timezone' => 'America/Los_Angeles', 'label' => 'Los Angeles, United States']
    ];

    if (isset($aliases[$normalized])) {
        return [
            'ok' => true,
            'timezone' => $aliases[$normalized]['timezone'],
            'label' => $aliases[$normalized]['label'],
            'warning' => ''
        ];
    }

    $query = rawurlencode($location);
    $geo = http_get_json(
        'https://geocoding-api.open-meteo.com/v1/search?name=' . $query . '&count=1&language=en&format=json',
        $timeoutSeconds
    );

    if (!(bool) ($geo['ok'] ?? false)) {
        return ['ok' => false, 'timezone' => '', 'label' => '', 'warning' => 'Location lookup unavailable.'];
    }

    $results = as_array($geo['data']['results'] ?? []);
    if (count($results) === 0 || !is_array($results[0])) {
        return ['ok' => false, 'timezone' => '', 'label' => '', 'warning' => 'Location not found.'];
    }

    $first = $results[0];
    $timezone = clean_text($first['timezone'] ?? '');
    if ($timezone === '') {
        return ['ok' => false, 'timezone' => '', 'label' => '', 'warning' => 'Timezone not available for location.'];
    }

    $name = clean_text($first['name'] ?? $location, $location);
    $country = clean_text($first['country'] ?? '', '');
    $label = $country !== '' ? ($name . ', ' . $country) : $name;

    return [
        'ok' => true,
        'timezone' => $timezone,
        'label' => $label,
        'warning' => ''
    ];
}

function fetch_time_payload(string $location, int $timeoutSeconds): array
{
    $resolved = resolve_timezone_from_location($location, $timeoutSeconds);
    $timezoneName = clean_text($resolved['timezone'] ?? '', 'UTC');
    $label = clean_text($resolved['label'] ?? '', $location !== '' ? $location : 'UTC');
    $warning = clean_text($resolved['warning'] ?? '', '');

    if ($label === '') {
        $label = 'UTC';
    }

    try {
        $zone = new DateTimeZone($timezoneName);
    } catch (Exception $exception) {
        $zone = new DateTimeZone('UTC');
        $timezoneName = 'UTC';
        $warning = 'Timezone fallback to UTC.';
    }

    $now = new DateTimeImmutable('now', $zone);
    $offsetSeconds = $zone->getOffset($now);
    $offsetAbs = abs($offsetSeconds);
    $offsetHours = intdiv($offsetAbs, 3600);
    $offsetMinutes = intdiv($offsetAbs % 3600, 60);
    $offsetLabel = sprintf('%s%02d:%02d', $offsetSeconds >= 0 ? '+' : '-', $offsetHours, $offsetMinutes);

    return [
        'ok' => true,
        'payload' => [
            'location' => $label,
            'timezone' => $timezoneName,
            'dateLabel' => $now->format('l, F j, Y'),
            'time12' => $now->format('g:i A'),
            'time24' => $now->format('H:i'),
            'iso' => $now->format(DateTimeInterface::ATOM),
            'offset' => $offsetLabel,
            'warning' => $warning
        ]
    ];
}

function detect_prompt_capability(string $prompt): array
{
    $normalized = strtolower($prompt);
    $isImagePrompt = is_image_prompt($normalized);
    $isWeatherPrompt = preg_match('/\b(weather|forecast|temperature)\b/i', $normalized) === 1;
    $isTimePrompt = preg_match('/\b(current time|local time|time in|what time|clock)\b/i', $normalized) === 1;
    $coinId = detect_crypto_symbol_from_prompt($prompt);

    if ($isImagePrompt) {
        return [
            'type' => 'image',
            'location' => '',
            'coinId' => ''
        ];
    }

    if ($coinId !== '') {
        return [
            'type' => 'crypto',
            'coinId' => $coinId,
            'location' => ''
        ];
    }

    if ($isWeatherPrompt) {
        return [
            'type' => 'weather',
            'location' => extract_weather_location_from_prompt($prompt)
        ];
    }

    if ($isTimePrompt) {
        return [
            'type' => 'time',
            'location' => extract_time_location_from_prompt($prompt)
        ];
    }

    return ['type' => 'generic', 'location' => '', 'coinId' => ''];
}

function parse_prompt_widget_response(string $raw, string $fallbackAnswer): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
    $response = '';
    $items = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        if (stripos($trimmed, 'ANSWER:') === 0) {
            $response = trim(substr($trimmed, 7));
            continue;
        }

        if (stripos($trimmed, 'ACTION_') === 0 || stripos($trimmed, 'ACTION:') === 0) {
            $parts = explode(':', $trimmed, 2);
            $item = isset($parts[1]) ? trim($parts[1]) : '';
            if ($item !== '') {
                $items[] = $item;
            }
        }
    }

    if ($response === '') {
        $response = trim($raw);
    }
    if ($response === '') {
        $response = $fallbackAnswer;
    }

    if (count($items) === 0) {
        $items = [
            'Refine the prompt with exact scope and constraints.',
            'Ask for output format you can reuse directly.',
            'Refresh to iterate quickly on the same widget.'
        ];
    }

    return [
        'response' => $response,
        'items' => array_slice($items, 0, 4)
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
    $prompt = clean_text($widget['config']['prompt'] ?? '');
    if ($prompt !== '') {
        $capability = detect_prompt_capability($prompt);
        if (($capability['type'] ?? 'generic') === 'image') {
            $imageResult = fetch_generated_image_payload($prompt, $config, $timeoutSeconds);
            $imagePayload = as_array($imageResult['payload'] ?? []);

            $payload = [
                'mode' => 'prompt',
                'capability' => 'image',
                'title' => $widget['title'],
                'prompt' => $prompt,
                'response' => 'Image render complete for your prompt.',
                'items' => [
                    'Provider: ' . clean_text($imagePayload['provider'] ?? 'fallback', 'fallback'),
                    'Model: ' . clean_text($imagePayload['model'] ?? 'placeholder', 'placeholder'),
                    'Use refresh to generate another variation.'
                ],
                'imageUrl' => clean_text($imagePayload['imageUrl'] ?? ''),
                'facts' => $imagePayload
            ];
            $source = clean_text($imageResult['source'] ?? 'fallback', 'fallback');
        } elseif (($capability['type'] ?? 'generic') === 'time') {
            $location = clean_text($capability['location'] ?? '', 'Saudi Arabia');
            $timeResult = fetch_time_payload($location, $timeoutSeconds);
            $timePayload = as_array($timeResult['payload'] ?? []);

            $responseText = 'Current time in ' . clean_text($timePayload['location'] ?? $location, $location) .
                ': ' . clean_text($timePayload['time12'] ?? '--:--', '--:--') .
                ' (' . clean_text($timePayload['offset'] ?? '+00:00', '+00:00') . ')' .
                ' on ' . clean_text($timePayload['dateLabel'] ?? '', gmdate('l, F j, Y')) . '.';

            $items = [
                'Timezone: ' . clean_text($timePayload['timezone'] ?? 'UTC', 'UTC'),
                '24-hour format: ' . clean_text($timePayload['time24'] ?? '--:--', '--:--'),
                'Use refresh for real-time updates.'
            ];

            $warning = clean_text($timePayload['warning'] ?? '', '');
            if ($warning !== '') {
                $items[] = 'Note: ' . $warning;
            }

            $payload = [
                'mode' => 'prompt',
                'capability' => 'time',
                'title' => $widget['title'],
                'prompt' => $prompt,
                'response' => $responseText,
                'items' => array_slice($items, 0, 4),
                'facts' => $timePayload
            ];
            $source = 'external';
        } elseif (($capability['type'] ?? 'generic') === 'weather') {
            $location = clean_text($capability['location'] ?? '', 'New York');
            $weather = fetch_weather_payload($location, $timeoutSeconds);

            if (!(bool) ($weather['ok'] ?? false)) {
                $payload = [
                    'mode' => 'prompt',
                    'capability' => 'weather',
                    'title' => $widget['title'],
                    'prompt' => $prompt,
                    'response' => clean_text($weather['error'] ?? 'Weather data unavailable right now.', 'Weather data unavailable right now.'),
                    'items' => ['Try refresh for a new pull.', 'Check location spelling for best results.'],
                    'facts' => [
                        'location' => $location
                    ]
                ];
                $source = 'fallback';
            } else {
                $weatherPayload = as_array($weather['payload'] ?? []);
                $tempC = isset($weatherPayload['temperatureC']) ? (float) $weatherPayload['temperatureC'] : 0.0;
                $tempF = celsius_to_fahrenheit($tempC);
                $responseText = 'Current weather in ' . clean_text($weatherPayload['city'] ?? $location, $location) .
                    ': ' . number_format($tempF, 1) . 'F (' . number_format($tempC, 1) . 'C), ' .
                    clean_text($weatherPayload['condition'] ?? 'conditions unavailable', 'conditions unavailable') . '.';

                $payload = [
                    'mode' => 'prompt',
                    'capability' => 'weather',
                    'title' => $widget['title'],
                    'prompt' => $prompt,
                    'response' => $responseText,
                    'items' => [
                        'Feels like: ' . number_format((float) ($weatherPayload['feelsLikeC'] ?? 0.0), 1) . 'C',
                        'Wind: ' . number_format((float) ($weatherPayload['windKph'] ?? 0.0), 1) . ' kph',
                        'Daily range: ' . number_format((float) ($weatherPayload['lowC'] ?? 0.0), 1) . 'C to ' . number_format((float) ($weatherPayload['highC'] ?? 0.0), 1) . 'C'
                    ],
                    'facts' => $weatherPayload
                ];
                $source = 'external';
            }
        } elseif (($capability['type'] ?? 'generic') === 'crypto') {
            $coinId = clean_text($capability['coinId'] ?? '', 'bitcoin');
            $crypto = fetch_crypto_price_payload($coinId, $timeoutSeconds);

            if (!(bool) ($crypto['ok'] ?? false)) {
                $payload = [
                    'mode' => 'prompt',
                    'capability' => 'crypto',
                    'title' => $widget['title'],
                    'prompt' => $prompt,
                    'response' => clean_text($crypto['error'] ?? 'Crypto price unavailable right now.', 'Crypto price unavailable right now.'),
                    'items' => ['Try refresh for a live pull.', 'Confirm the requested coin symbol is supported.'],
                    'facts' => [
                        'coinId' => $coinId
                    ]
                ];
                $source = 'fallback';
            } else {
                $cryptoPayload = as_array($crypto['payload'] ?? []);
                $symbol = clean_text($cryptoPayload['symbol'] ?? strtoupper($coinId), strtoupper($coinId));
                $priceUsd = (float) ($cryptoPayload['priceUsd'] ?? 0.0);
                $change24 = (float) ($cryptoPayload['change24hPct'] ?? 0.0);
                $changePrefix = $change24 >= 0 ? '+' : '';
                $responseText = $symbol . ' price: $' . number_format($priceUsd, 2) . ' USD';

                $payload = [
                    'mode' => 'prompt',
                    'capability' => 'crypto',
                    'title' => $widget['title'],
                    'prompt' => $prompt,
                    'response' => $responseText,
                    'items' => [
                        '24h change: ' . $changePrefix . number_format($change24, 2) . '%',
                        'Updated: ' . clean_text($cryptoPayload['updatedAt'] ?? gmdate('c'), gmdate('c')),
                        'Use refresh for the latest market tick.'
                    ],
                    'facts' => $cryptoPayload
                ];
                $source = 'external';
            }
        } else {
        $fallbackAnswer = fallback_prompt_widget_answer($prompt);
        $completion = ai_short_text(
            $config,
            'You generate concise widget answers. Return exactly 3 lines: ANSWER:, ACTION_1:, ACTION_2:.',
            'Widget title: ' . $widget['title'] . '. User prompt: ' . $prompt . '. Keep each line concise.',
            "ANSWER: {$fallbackAnswer}\nACTION_1: Tighten the prompt with clear desired output.\nACTION_2: Refresh to get a sharper iteration."
        );

        $parsed = parse_prompt_widget_response($completion['text'], $fallbackAnswer);
        $payload = [
            'mode' => 'prompt',
            'title' => $widget['title'],
            'prompt' => $prompt,
            'response' => $parsed['response'],
            'items' => $parsed['items']
        ];
        $source = $completion['source'];
        }
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
}

send_json(200, [
    'widgetId' => $widget['id'],
    'type' => $widget['type'],
    'refreshedAt' => gmdate('c'),
    'source' => $source,
    'payload' => $payload
]);
