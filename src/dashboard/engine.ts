import { hashText } from '@/utils/seed';

export type DashboardWidgetType = 'weather' | 'horoscope' | 'fashion' | 'sports' | 'customHtml';

export interface DashboardWidget {
  id: string;
  type: DashboardWidgetType;
  title: string;
  createdAt: string;
  config: Record<string, string>;
  html?: string;
}

const WIDGET_STORAGE_KEY = 'mysite-dashboard-widgets-v1';
export const MAX_DASHBOARD_WIDGETS = 7;

const ZODIAC_SIGNS = [
  'aries',
  'taurus',
  'gemini',
  'cancer',
  'leo',
  'virgo',
  'libra',
  'scorpio',
  'sagittarius',
  'capricorn',
  'aquarius',
  'pisces'
] as const;

function canUseStorage(): boolean {
  return typeof window !== 'undefined' && typeof localStorage !== 'undefined';
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
}

function cleanText(value: unknown, fallback = ''): string {
  if (typeof value !== 'string') {
    return fallback;
  }

  const trimmed = value.trim();
  return trimmed || fallback;
}

function normalizeWidget(input: unknown): DashboardWidget | null {
  const record = asRecord(input);
  if (!record) {
    return null;
  }

  const id = cleanText(record.id);
  const type = cleanText(record.type) as DashboardWidgetType;
  const title = cleanText(record.title);
  const createdAt = cleanText(record.createdAt);
  const config = asRecord(record.config) ?? {};
  const html = cleanText(record.html);

  if (!id || !title || !createdAt) {
    return null;
  }

  if (!['weather', 'horoscope', 'fashion', 'sports', 'customHtml'].includes(type)) {
    return null;
  }

  const normalizedConfig: Record<string, string> = {};
  for (const [key, value] of Object.entries(config)) {
    if (typeof value === 'string' && value.trim().length > 0) {
      normalizedConfig[key] = value.trim();
    }
  }

  return {
    id,
    type,
    title,
    createdAt,
    config: normalizedConfig,
    html: html || undefined
  };
}

export function loadWidgets(): DashboardWidget[] {
  if (!canUseStorage()) {
    return [];
  }

  try {
    const raw = localStorage.getItem(WIDGET_STORAGE_KEY);
    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw) as unknown;
    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed
      .map((item) => normalizeWidget(item))
      .filter((item): item is DashboardWidget => item !== null)
      .slice(0, MAX_DASHBOARD_WIDGETS);
  } catch {
    return [];
  }
}

export function saveWidgets(widgets: DashboardWidget[]): void {
  if (!canUseStorage()) {
    return;
  }

  try {
    if (widgets.length === 0) {
      localStorage.removeItem(WIDGET_STORAGE_KEY);
      return;
    }

    localStorage.setItem(WIDGET_STORAGE_KEY, JSON.stringify(widgets.slice(0, MAX_DASHBOARD_WIDGETS)));
  } catch {
    // Ignore storage failures; UI remains functional for current session.
  }
}

export function generateWidgetId(seedSource: string): string {
  const hash = hashText(`${seedSource}:${Date.now()}:${Math.random()}`)
    .toString(36)
    .toUpperCase();

  return `W-${hash.padStart(6, '0').slice(0, 6)}`;
}

export function sanitizeWidgetHtml(rawHtml: string): string {
  let clean = rawHtml.trim();

  clean = clean.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
  clean = clean.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
  clean = clean.replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '');
  clean = clean.replace(/<object\b[^>]*>[\s\S]*?<\/object>/gi, '');
  clean = clean.replace(/<embed\b[^>]*>/gi, '');
  clean = clean.replace(/<link\b[^>]*>/gi, '');
  clean = clean.replace(/<meta\b[^>]*>/gi, '');

  clean = clean.replace(/\s+on[a-z]+\s*=\s*"[^"]*"/gi, '');
  clean = clean.replace(/\s+on[a-z]+\s*=\s*'[^']*'/gi, '');
  clean = clean.replace(/\s+on[a-z]+\s*=\s*[^\s>]+/gi, '');

  clean = clean.replace(/(href|src)\s*=\s*"\s*javascript:[^"]*"/gi, '$1="#"');
  clean = clean.replace(/(href|src)\s*=\s*'\s*javascript:[^']*'/gi, "$1='#'");

  return clean;
}

function pickDefaultSign(seedSource: string): string {
  const index = hashText(`${seedSource}:horoscope-sign`) % ZODIAC_SIGNS.length;
  return ZODIAC_SIGNS[index];
}

export function createPresetWidget(type: DashboardWidgetType, seedSource: string, hint = ''): DashboardWidget {
  const now = new Date().toISOString();
  const id = generateWidgetId(seedSource);
  const normalizedHint = hint.trim();

  if (type === 'weather') {
    return {
      id,
      type,
      title: normalizedHint ? `Weather · ${normalizedHint}` : 'Weather Widget',
      createdAt: now,
      config: {
        city: normalizedHint || 'New York'
      }
    };
  }

  if (type === 'horoscope') {
    return {
      id,
      type,
      title: normalizedHint || 'Daily Horoscope',
      createdAt: now,
      config: {
        sign: pickDefaultSign(seedSource)
      }
    };
  }

  if (type === 'fashion') {
    return {
      id,
      type,
      title: normalizedHint || 'Fashion Trend Radar',
      createdAt: now,
      config: {
        season: 'current',
        mood: normalizedHint || 'street-minimal'
      }
    };
  }

  if (type === 'sports') {
    return {
      id,
      type,
      title: normalizedHint || 'Sports Scoreboard',
      createdAt: now,
      config: {
        league: normalizedHint || 'NHL'
      }
    };
  }

  return {
    id,
    type,
    title: normalizedHint || 'Custom HTML Widget',
    createdAt: now,
    config: {},
    html: sanitizeWidgetHtml('<div><h4>Custom Widget</h4><p>Edit this via the CLI command /widget html.</p></div>')
  };
}

function inferWidgetTitle(request: string, fallback: string): string {
  const compact = request.trim().replace(/\s+/g, ' ');
  if (!compact) {
    return fallback;
  }

  return compact.length > 48 ? `${compact.slice(0, 45)}...` : compact;
}

export function generateSimpleHtmlWidgetFromRequest(request: string, seedSource: string): DashboardWidget {
  const id = generateWidgetId(seedSource);
  const createdAt = new Date().toISOString();
  const title = inferWidgetTitle(request, 'AI HTML Widget');

  const bullets = request
    .split(/[,.]/)
    .map((item) => item.trim())
    .filter((item) => item.length > 2)
    .slice(0, 4);

  const listItems = bullets.length > 0
    ? bullets.map((item) => `<li>${item}</li>`).join('')
    : '<li>Define a goal</li><li>Add context</li><li>Set output format</li>';

  const rawHtml = `
    <section>
      <h4>${title}</h4>
      <p>AI-generated starter widget based on your CLI request.</p>
      <ul>${listItems}</ul>
    </section>
  `;

  return {
    id,
    type: 'customHtml',
    title,
    createdAt,
    config: {
      source: 'ai-cli'
    },
    html: sanitizeWidgetHtml(rawHtml)
  };
}

export function horoscopeInsight(sign: string, signature: string): string {
  const normalized = sign.trim().toLowerCase();
  const today = new Date().toISOString().slice(0, 10);
  const library = [
    'A focused prompt will unlock better results today.',
    'Ask for constraints first, then iterate for precision.',
    'You get stronger outputs by giving clearer context.',
    'Try role + objective + format for immediate quality gains.',
    'Refinement beats restarts. Edit prompts in small steps.',
    'Ask the model to explain assumptions before final output.'
  ];

  const index = hashText(`${normalized}|${signature}|${today}`) % library.length;
  return library[index];
}

export function sportsSnapshots(leagueHint: string, signature: string): string[] {
  const leagues = ['NFL', 'NBA', 'NHL', 'MLB', 'EPL'];
  const selectedLeague = leagues.includes(leagueHint.toUpperCase()) ? leagueHint.toUpperCase() : 'NHL';

  const matchups = [
    `${selectedLeague}: Falcons 3 - 2 Wolves`,
    `${selectedLeague}: Comets 1 - 4 Titans`,
    `${selectedLeague}: Storm 2 - 2 Royals`,
    `${selectedLeague}: Blazers 5 - 3 Raiders`
  ];

  const shift = hashText(`${signature}:${selectedLeague}`) % matchups.length;
  return [...matchups.slice(shift), ...matchups.slice(0, shift)].slice(0, 3);
}

export function fashionSnapshots(moodHint: string, signature: string): string[] {
  const mood = moodHint.trim() || 'street-minimal';
  const looks = [
    `${mood}: tonal layers + one statement accessory`,
    `${mood}: sharp outerwear with relaxed basics`,
    `${mood}: monochrome palette with texture contrast`,
    `${mood}: utility core with clean silhouettes`
  ];

  const shift = hashText(`${signature}:${mood}`) % looks.length;
  return [...looks.slice(shift), ...looks.slice(0, shift)].slice(0, 3);
}
