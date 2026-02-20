<?php
/**
 * Copy this file to one of these server paths (outside web root):
 *   ~/.config/mysite/config.php
 *   ~/.config/mysite/<host>.php   (for host-specific overrides)
 *
 * Example host-specific file for your domain:
 *   ~/.config/mysite/my.alexanderjgill.com.php
 */
return [
    'app' => [
        'name' => 'MySite',
        'environment' => 'production'
    ],
    'openai' => [
        'enabled' => true,
        'api_key' => 'REPLACE_ME',
        'api_key_file' => '',
        'model' => 'gpt-4o-mini',
        'api_url' => 'https://api.openai.com/v1/chat/completions',
        'timeout_seconds' => 30
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
