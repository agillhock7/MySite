# MySite

Headless Vue frontend for a WordPress site, generated from a conversational onboarding chat and AI blueprint output.

## What it does

- Full-screen terminal onboarding captures identity, interests, and desired experience tone in chat.
- Chat transcript + intent profile drive blueprint generation.
- Blueprint output is strictly JSON and validated against schema.
- Shell UI is rendered from data-driven module mapping (safe component map, no arbitrary code execution).
- WordPress is consumed read-only via REST (`posts` as primary content stream).
- Valid blueprint is cached in `localStorage` for offline-friendly repeat visits.
- Reset controls clear personalization and restart onboarding.
- Final personalized shell includes an embedded AI concierge chat for UX guidance, hosting onboarding, and AI access routing.

## Stack

- Vue 3 + Vite + TypeScript
- Vue Router
- Pinia
- Zod
- PHP endpoints for AI + WordPress aggregation

## Project layout

- `src/views/OnboardingTerminal.vue`: chat onboarding terminal
- `src/views/PersonalizedShell.vue`: personalized shell renderer
- `src/blueprint/schema.ts`: Zod blueprint schema + TS types
- `src/blueprint/engine.ts`: load/save/clear/validate/migrate blueprint
- `src/api/ai.ts`: frontend API integration + fallback logic
- `src/content/library.ts`: default content + runtime overrides
- `public/api/ai/onboarding.php`: onboarding turn endpoint
- `public/api/ai/blueprint.php`: blueprint generation endpoint
- `public/api/ai/assistant.php`: in-shell assistant endpoint
- `public/api/content/wp.php`: WordPress content bundle endpoint

## Local development

Requirements:

- Node.js 20+ recommended
- npm
- PHP 8.1+ for API endpoints

Install and run:

```bash
npm install
npm run dev
```

Type check:

```bash
npm run lint
```

Production build:

```bash
npm run build
```

## Runtime config

Server config lookup order:

1. `config.php` in deployed app root
2. `MYSITE_CONFIG_FILE` env var path
3. `~/.config/mysite/config.php`
4. `~/.config/mysite/<host>.php`

Templates:

- `example.config.php`
- `config/server-config.example.php`

Security note:

- Do not commit real API keys.
- Keep production `config.php` out of git history.

## API contracts

### `POST /api/ai/onboarding.php`

Input includes transcript + current intent draft.  
Output returns:

- `assistantMessage`
- `intentProfile`
- `isComplete`
- `confidence`

### `POST /api/ai/blueprint.php`

Input includes:

- `intentProfile`
- `transcript`
- `visitorId`
- `variantNonce`

Output returns:

- `blueprint`
- `contentOverrides`
- `gapSuggestions`
- `wordpress`
- `source`
- `design` (`signature`, `profile`, `thoughtPasses`, `selectedPass`)

Generation behavior:

- Multi-pass AI thought loop (several style profiles per request)
- Candidate scoring and best-candidate selection
- Strict URL sanitization against discovered WordPress links
- Server fallback blueprint if AI is unavailable

### `POST /api/ai/assistant.php`

In-shell concierge assistant endpoint.

Input includes:

- `userMessage`
- `transcript`
- `visitorId`
- `variantNonce`

Output returns:

- `assistantMessage`
- `suggestions` (`label` + `action`)
- `source`

### `GET /api/content/wp.php`

Returns fresh WordPress-derived content overrides and content gap suggestions.

## Personalization flow

First visit:

1. User lands on onboarding terminal.
2. Chat captures intent.
3. App requests blueprint from backend.
4. Blueprint is validated, saved, and `/app` is rendered.

Returning visit:

1. App loads cached blueprint.
2. If valid, render `/app` immediately.
3. If invalid, cache is cleared and onboarding restarts.

## WordPress safety

- Read-only integration (public REST `GET` calls only).
- No writes to WordPress.
- Existing WordPress frontend remains source-of-truth and unaffected.

## cPanel deployment

This repo deploys prebuilt artifacts from `dist/`.

`.cpanel.yml` tasks:

- copy `dist/.` to `/home/alexande/my.alexanderjgill.com/`
- copy `example.config.php` to deploy root

Recommended deploy workflow:

1. `npm run build`
2. Commit and push (including updated `dist/` artifacts)
3. In cPanel Git Version Control: `Update from Remote`
4. Click `Deploy HEAD Commit`

Useful routes after deploy:

- `/onboarding?force=1&reset=1` (force restart onboarding)
- `/reset` (redirects to forced onboarding reset)
