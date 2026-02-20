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

## AI backend wiring (cPanel)

This project uses a server-side endpoint at `public/api/ai/blueprint.php` (deployed to `dist/api/ai/blueprint.php`).

The browser never uses your OpenAI API key directly.

### 1) Configure key on server

Option A (preferred): set environment variable `OPENAI_API_KEY`.

Option B (works on shared cPanel): create a key file outside web root:

```bash
mkdir -p ~/.secrets
chmod 700 ~/.secrets
printf '%s\n' 'YOUR_OPENAI_API_KEY' > ~/.secrets/mysite_openai_api_key
chmod 600 ~/.secrets/mysite_openai_api_key
```

Optional model override:

- env var `OPENAI_MODEL`
- default: `gpt-4o-mini`

### 2) Endpoint behavior

- `POST /api/ai/blueprint.php`
- input: `{ "intentProfile": { ... } }`
- output: blueprint JSON only
- on backend/key/API failure, frontend falls back to deterministic local generator

## cPanel deploy

Deploy uses prebuilt `dist` only (no server-side npm build required):

- `.cpanel.yml` copies `dist/` into `/home/alexande/my.alexanderjgill.com/`

Deployment flow:

1. Build locally: `npm run build`
2. Commit/push including `dist/`
3. In cPanel Git Version Control: `Update from Remote`, then `Deploy HEAD Commit`
