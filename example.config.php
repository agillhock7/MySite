<?php
return [
    'app' => [
        'name' => 'MySite',
        'environment' => 'production'
    ],
    'openai' => [
        'enabled' => true,
        'api_key' => 'REPLACE_WITH_OPENAI_API_KEY',
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
    'database' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'my_db_name',
        'user' => 'my_db_user',
        'password' => 'my_db_password'
    ]
];
