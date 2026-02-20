# MySite MVP

Vue 3 + Vite + TypeScript app with terminal onboarding and schema-driven personalized UI rendering.

## Local development

```bash
npm install
npm run dev
```

Build production assets:

```bash
npm run build
```

## AI backend + environment config

Server endpoint: `public/api/ai/blueprint.php` (deployed as `dist/api/ai/blueprint.php`).

The browser never uses your OpenAI API key.

### Config-first setup (recommended)

This backend reads config from files outside web root, so each environment can be configured without changing code.

Load order:

1. `config.php` in app root (easy mode)
2. `MYSITE_CONFIG_FILE` (if set)
3. `~/.config/mysite/config.php`
4. `~/.config/mysite/<host>.php` (host-specific override)

Template files in repo:

- `example.config.php`
- `config/server-config.example.php`

Quick path for cPanel (recommended): copy `example.config.php` to `config.php` in your deployed app root and fill it in.

Alternative host-specific path:

- `/home/alexande/.config/mysite/my.alexanderjgill.com.php`

Example content:

```php
<?php
return [
  'openai' => [
    'enabled' => true,
    'api_key' => 'YOUR_OPENAI_API_KEY',
    'model' => 'gpt-4o-mini',
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
```

Notes:

- `database` keys are ready for upcoming backend work.
- Legacy fallbacks still work (`OPENAI_API_KEY` env var or `~/.secrets/mysite_openai_api_key`).

### Endpoint behavior

- `POST /api/ai/blueprint.php`
- input: `{ "intentProfile": { ... } }`
- output: blueprint JSON only
- if backend/key/API fails, frontend falls back to deterministic local generator

## cPanel deploy

Deploy uses prebuilt `dist` only (no server-side npm build).

- `.cpanel.yml` copies `dist/` into `/home/alexande/my.alexanderjgill.com/`

Deployment flow:

1. Build locally: `npm run build`
2. Commit/push including `dist/`
3. In cPanel Git Version Control: `Update from Remote`, then `Deploy HEAD Commit`
