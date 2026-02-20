# MySite MVP

Vue 3 + Vite + TypeScript app with terminal onboarding, AI blueprint generation, and read-only WordPress content integration.

## Local development

```bash
npm install
npm run dev
```

Build production assets:

```bash
npm run build
```

## Environment config

Backend config load order:

1. `config.php` in deployed app root
2. `MYSITE_CONFIG_FILE` (if set)
3. `~/.config/mysite/config.php`
4. `~/.config/mysite/<host>.php`

Template files in repo:

- `example.config.php`
- `config/server-config.example.php`

### Example config

```php
<?php
return [
  'openai' => [
    'enabled' => true,
    'api_key' => 'YOUR_OPENAI_API_KEY',
    'model' => 'gpt-4o-mini',
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
```

## API behavior

### `POST /api/ai/blueprint.php`

Input:

```json
{ "intentProfile": { "goal": "...", "vibe": "minimal", "density": "medium", "primaryTopics": ["..."] } }
```

Output:

- `blueprint`: schema-aligned UI blueprint JSON
- `contentOverrides`: WordPress-derived module content keyed by content library keys
- `gapSuggestions`: heuristic missing-content recommendations
- `wordpress`: metadata about WP fetch status

Conversion behavior:

- Intent is classified into conversion profiles (`pro_suite_onboarding`, `hosting_plan`, `portfolio_review`, `content_learning`)
- CTAs are personalized toward hosting-plan start and/or HiOps Pro Suite onboarding using built-in defaults (no extra conversion config required)

### `POST /api/ai/onboarding.php`

Turn-by-turn onboarding conversation endpoint.

Input:

```json
{
  "transcript": [{ "role": "user", "text": "..." }],
  "currentIntent": { "goal": "", "vibe": "minimal", "density": "medium", "primaryTopics": [] }
}
```

Output:

- `assistantMessage`: next conversational response
- `intentProfile`: updated inferred intent
- `isComplete`: readiness to generate blueprint
- `confidence`: extraction confidence score

### `GET /api/content/wp.php`

Returns the latest WordPress-derived `contentOverrides` + `gapSuggestions` for runtime refresh.

## WordPress safety

Integration is read-only by design.

- Uses only `GET` calls to public WP REST endpoints (`/wp-json/wp/v2/...`)
- Does not call WP admin endpoints
- Does not create, update, or delete WordPress content
- Existing WordPress frontend remains untouched

## cPanel deploy

Deploy uses prebuilt `dist` only (no server-side npm build).

`.cpanel.yml` copies:

- `dist/` -> `/home/alexande/my.alexanderjgill.com/`
- `example.config.php` -> `/home/alexande/my.alexanderjgill.com/example.config.php`

Deployment flow:

1. Build locally: `npm run build`
2. Commit/push including `dist/`
3. In cPanel Git Version Control: `Update from Remote`, then `Deploy HEAD Commit`

After deploy:

- Open `/reset` to force-clear personalization cache and restart onboarding.
- Check onboarding header build tag to confirm latest frontend is live.
