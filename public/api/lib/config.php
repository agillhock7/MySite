<?php
declare(strict_types=1);

function mysite_merge_config(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
            $base[$key] = mysite_merge_config($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function mysite_default_server_config(): array
{
    return [
        'app' => [
            'name' => 'MySite',
            'environment' => 'production'
        ],
        'openai' => [
            'enabled' => true,
            'api_key' => '',
            'api_key_file' => '',
            'model' => 'gpt-4o-mini',
            'api_url' => 'https://api.openai.com/v1/chat/completions',
            'timeout_seconds' => 30
        ],
        'wordpress' => [
            'enabled' => true,
            'base_url' => 'https://alexanderjgill.com',
            'timeout_seconds' => 12,
            'max_posts' => 6,
            'max_pages' => 6,
            'max_categories' => 12,
            'max_tags' => 12
        ],
        'experience' => [
            'brand_name' => 'Alexander J Gill',
            'company_name' => 'Dark Horse Virtue',
            'hosting_start_url' => 'https://alexanderjgill.com',
            'pro_suite_onboarding_url' => 'https://hiops.darkhorsevirtue.io',
            'primary_conversion_goal' => 'pro_suite_onboarding'
        ],
        'database' => [
            'driver' => '',
            'host' => '',
            'port' => '',
            'name' => '',
            'user' => '',
            'password' => ''
        ]
    ];
}

function mysite_sanitize_host_name(string $host): string
{
    $clean = strtolower(trim($host));
    $clean = preg_replace('/[^a-z0-9.\-]/', '', $clean) ?? '';
    return trim($clean, '.-');
}

function mysite_load_server_config(): array
{
    $config = mysite_default_server_config();

    $home = rtrim((string) ($_SERVER['HOME'] ?? ''), '/');
    $host = mysite_sanitize_host_name((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $appRoot = dirname(__DIR__, 2);

    $paths = [];

    $paths[] = $appRoot . '/config.php';

    $explicitPath = getenv('MYSITE_CONFIG_FILE');
    if (is_string($explicitPath) && trim($explicitPath) !== '') {
        $paths[] = trim($explicitPath);
    }

    if ($home !== '') {
        $paths[] = $home . '/.config/mysite/config.php';

        if ($host !== '') {
            $paths[] = $home . '/.config/mysite/' . $host . '.php';
        }
    }

    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $loaded = require $path;
        if (is_array($loaded)) {
            $config = mysite_merge_config($config, $loaded);
        }
    }

    $envApiKey = getenv('OPENAI_API_KEY');
    if (is_string($envApiKey) && trim($envApiKey) !== '') {
        $config['openai']['api_key'] = trim($envApiKey);
    }

    $envModel = getenv('OPENAI_MODEL');
    if (is_string($envModel) && trim($envModel) !== '') {
        $config['openai']['model'] = trim($envModel);
    }

    return $config;
}

function mysite_load_secret_file(string $path): string
{
    if ($path === '' || !is_readable($path)) {
        return '';
    }

    return trim((string) file_get_contents($path));
}

function mysite_resolve_openai_api_key(array $config): string
{
    $openAi = is_array($config['openai'] ?? null) ? $config['openai'] : [];

    $directKey = trim((string) ($openAi['api_key'] ?? ''));
    if ($directKey !== '') {
        return $directKey;
    }

    $keyFile = trim((string) ($openAi['api_key_file'] ?? ''));
    if ($keyFile !== '') {
        $fromConfiguredFile = mysite_load_secret_file($keyFile);
        if ($fromConfiguredFile !== '') {
            return $fromConfiguredFile;
        }
    }

    $home = rtrim((string) ($_SERVER['HOME'] ?? ''), '/');
    if ($home !== '') {
        $legacyCandidates = [
            $home . '/.secrets/mysite_openai_api_key',
            $home . '/.secrets/openai_api_key'
        ];

        foreach ($legacyCandidates as $path) {
            $value = mysite_load_secret_file($path);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}
