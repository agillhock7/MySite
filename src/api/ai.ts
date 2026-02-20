import type { Blueprint, BlueprintModule } from '@/blueprint/schema';
import { validateBlueprint } from '@/blueprint/engine';

export interface IntentProfile {
  goal: string;
  vibe: 'minimal' | 'visual' | 'dense' | 'playful';
  density: 'low' | 'medium' | 'high';
  primaryTopics: string[];
}

export interface OnboardingTranscriptLine {
  role: 'system' | 'assistant' | 'user';
  text: string;
}

export interface OnboardingTurnResult {
  assistantMessage: string;
  intentProfile: IntentProfile;
  isComplete: boolean;
  confidence: number;
  source: 'backend' | 'local';
}

export interface GapSuggestion {
  topic: string;
  priority: 'high' | 'medium' | 'low';
  reason: string;
  suggestedAction: string;
}

export interface BlueprintGenerationResult {
  blueprint: Blueprint;
  source: 'backend' | 'stub';
  contentOverrides: Record<string, unknown>;
  gapSuggestions: GapSuggestion[];
  wordpress: {
    baseUrl: string;
    available: boolean;
    fetchedAt: string;
    errors: string[];
  } | null;
}

const BACKEND_BLUEPRINT_ENDPOINT = '/api/ai/blueprint.php';
const BACKEND_ONBOARDING_ENDPOINT = '/api/ai/onboarding.php';

export function defaultIntentProfile(): IntentProfile {
  return {
    goal: '',
    vibe: 'minimal',
    density: 'medium',
    primaryTopics: []
  };
}

function normalizeVibe(raw: string): IntentProfile['vibe'] {
  const normalized = raw.toLowerCase();

  if (normalized.includes('play')) {
    return 'playful';
  }

  if (normalized.includes('visual')) {
    return 'visual';
  }

  if (normalized.includes('dense')) {
    return 'dense';
  }

  return 'minimal';
}

function normalizeDensity(raw: string): IntentProfile['density'] {
  const normalized = raw.toLowerCase();

  if (normalized.includes('high')) {
    return 'high';
  }

  if (normalized.includes('low')) {
    return 'low';
  }

  return 'medium';
}

function parseTopics(raw: string): string[] {
  const withComma = raw
    .split(',')
    .map((topic) => topic.trim())
    .filter(Boolean);

  if (withComma.length > 0) {
    return withComma.slice(0, 4);
  }

  return raw
    .split(' ')
    .map((token) => token.trim())
    .filter((token) => token.length > 3)
    .slice(0, 4);
}

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

function seededIndex(seed: string, salt: string, length: number): number {
  if (length <= 0) {
    return 0;
  }

  return hashText(`${seed}:${salt}`) % length;
}

function seededPick<T>(seed: string, salt: string, options: T[]): T {
  return options[seededIndex(seed, salt, options.length)];
}

function seedFromIntent(intentProfile: IntentProfile, visitorId: string): string {
  const topics = intentProfile.primaryTopics.join('|').toLowerCase();
  return `${visitorId}::${intentProfile.goal.toLowerCase()}::${intentProfile.vibe}::${intentProfile.density}::${topics}`;
}

function inferJourneyProfile(intentProfile: IntentProfile): {
  primaryLabel: string;
  primaryUrl: string;
  secondaryLabel: string;
  secondaryUrl: string;
  narrative: string;
} {
  const source = `${intentProfile.goal} ${intentProfile.primaryTopics.join(' ')}`.toLowerCase();

  if (/work|portfolio|case|project|build/.test(source)) {
    return {
      primaryLabel: 'Explore Work',
      primaryUrl: 'https://alexanderjgill.com/work/',
      secondaryLabel: 'Read Insights',
      secondaryUrl: 'https://alexanderjgill.com/read/',
      narrative: 'Lead with proof-first storytelling, then guide visitors to deeper content.'
    };
  }

  if (/read|learn|article|insight|blog/.test(source)) {
    return {
      primaryLabel: 'Read Insights',
      primaryUrl: 'https://alexanderjgill.com/read/',
      secondaryLabel: 'Explore Work',
      secondaryUrl: 'https://alexanderjgill.com/work/',
      narrative: 'Prioritize educational flow with contextual links into portfolio and bio.'
    };
  }

  if (/bio|about|alexander|profile/.test(source)) {
    return {
      primaryLabel: 'Read Bio',
      primaryUrl: 'https://alexanderjgill.com/bio/',
      secondaryLabel: 'Explore Work',
      secondaryUrl: 'https://alexanderjgill.com/work/',
      narrative: 'Center narrative and credibility, then branch to projects and insights.'
    };
  }

  return {
    primaryLabel: 'Explore Main Site',
    primaryUrl: 'https://alexanderjgill.com',
    secondaryLabel: 'Explore Work',
    secondaryUrl: 'https://alexanderjgill.com/work/',
    narrative: 'Guide visitors through core site sections with clear editorial hierarchy.'
  };
}

function seededAccent(intentProfile: IntentProfile, seed: string): string {
  const palettes: Record<IntentProfile['vibe'], string[]> = {
    minimal: ['#22c55e', '#14b8a6', '#10b981', '#65a30d'],
    visual: ['#2563eb', '#0891b2', '#0ea5e9', '#06b6d4'],
    dense: ['#ea580c', '#c2410c', '#f97316', '#d97706'],
    playful: ['#db2777', '#7c3aed', '#ec4899', '#f43f5e']
  };

  return seededPick(seed, `${intentProfile.vibe}:accent`, palettes[intentProfile.vibe]);
}

function seededMode(intentProfile: IntentProfile, seed: string): 'dark' | 'light' {
  if (intentProfile.vibe === 'visual') {
    return 'light';
  }

  if (intentProfile.vibe === 'dense') {
    return seededIndex(seed, 'dense-mode', 2) === 0 ? 'dark' : 'light';
  }

  if (intentProfile.vibe === 'playful') {
    return seededIndex(seed, 'playful-mode', 3) === 0 ? 'dark' : 'light';
  }

  return seededIndex(seed, 'minimal-mode', 4) === 0 ? 'light' : 'dark';
}

function seededNav(density: IntentProfile['density'], seed: string): 'side' | 'top' | 'none' {
  if (density === 'high') {
    return seededIndex(seed, 'high-nav', 3) === 0 ? 'top' : 'side';
  }

  if (density === 'low') {
    return seededIndex(seed, 'low-nav', 4) === 0 ? 'top' : 'none';
  }

  return seededIndex(seed, 'medium-nav', 2) === 0 ? 'top' : 'side';
}

function buildSeededModules(intentProfile: IntentProfile, seed: string): BlueprintModule[] {
  const journey = inferJourneyProfile(intentProfile);
  const firstTopic = intentProfile.primaryTopics[0] ?? 'work';
  const secondTopic = intentProfile.primaryTopics[1] ?? 'insights';

  const heroKicker = seededPick(seed, 'hero-kicker', [
    'Visitor Blueprint',
    'Adaptive Journey',
    'AI Interface DNA',
    'Conversion Narrative'
  ]);
  const heroVariant = seededPick(seed, 'hero-variant', ['default', 'spotlight', 'split']);

  const gridVariant = seededPick(seed, 'grid-variant', ['default', 'magazine']);
  const listVariant = seededPick(seed, 'list-variant', ['default', 'timeline']);
  const gridColumns = seededPick(seed, 'grid-columns', [2, 2, 3]);

  const moduleTemplates: BlueprintModule[][] = [
    [
      {
        id: 'hero-entry',
        type: 'Hero',
        props: {
          kicker: heroKicker,
          title: intentProfile.goal || 'Adaptive visitor experience for alexanderjgill.com',
          subtitle: journey.narrative,
          ctaUrl: journey.primaryUrl,
          variant: heroVariant
        },
        contentKey: 'heroWelcome'
      },
      {
        id: 'actions-primary',
        type: 'QuickActions',
        props: {
          title: 'Choose Your Next Step'
        },
        contentKey: 'quickStartActions'
      },
      {
        id: 'grid-proof',
        type: 'ContentGrid',
        props: {
          title: `Proof around ${firstTopic}`,
          intro: `Live highlights aligned to ${intentProfile.goal || 'your stated outcome'}.`,
          variant: gridVariant,
          columns: gridColumns
        },
        contentKey: 'featuredGrid'
      },
      {
        id: 'list-decision',
        type: 'ContentList',
        props: {
          title: `Editorial path for ${secondTopic}`,
          intro: 'Structured next actions based on content and visitor intent.',
          variant: listVariant
        },
        contentKey: 'nextStepsList'
      },
      {
        id: 'faq-confidence',
        type: 'FAQ',
        props: {
          title: 'Trust + Implementation FAQs'
        },
        contentKey: 'faqGeneral'
      }
    ],
    [
      {
        id: 'hero-concierge',
        type: 'Hero',
        props: {
          kicker: heroKicker,
          title: `Built for ${firstTopic} outcomes`,
          subtitle: `This flow prioritizes ${journey.primaryLabel.toLowerCase()} and adapts content hierarchy automatically.`,
          ctaUrl: journey.primaryUrl,
          variant: heroVariant
        },
        contentKey: 'heroWelcome'
      },
      {
        id: 'grid-story',
        type: 'ContentGrid',
        props: {
          title: 'Story + Signals from WordPress',
          intro: 'Recent content is used as dynamic source material for this visitor shell.',
          variant: gridVariant,
          columns: gridColumns
        },
        contentKey: 'featuredGrid'
      },
      {
        id: 'actions-paths',
        type: 'QuickActions',
        props: {
          title: 'Primary Navigation Paths'
        },
        contentKey: 'quickStartActions'
      },
      {
        id: 'list-roadmap',
        type: 'ContentList',
        props: {
          title: 'Roadmap to Action',
          intro: 'Move from context to action in fewer steps.',
          variant: listVariant
        },
        contentKey: 'nextStepsList'
      },
      {
        id: 'faq-objections',
        type: 'FAQ',
        props: {
          title: 'Objection Handling'
        },
        contentKey: 'faqGeneral'
      }
    ],
    [
      {
        id: 'hero-prime',
        type: 'Hero',
        props: {
          kicker: heroKicker,
          title: `${journey.primaryLabel} with confidence`,
          subtitle: `This UX plan blends ${firstTopic} with ${secondTopic} signals.`,
          ctaUrl: journey.primaryUrl,
          variant: heroVariant
        },
        contentKey: 'heroWelcome'
      },
      {
        id: 'list-priorities',
        type: 'ContentList',
        props: {
          title: 'Visitor Priorities',
          intro: 'Top priorities inferred from visitor behavior and page context.',
          variant: listVariant
        },
        contentKey: 'nextStepsList'
      },
      {
        id: 'grid-context',
        type: 'ContentGrid',
        props: {
          title: 'Live Context Library',
          intro: 'Source material from alexanderjgill.com used for this shell.',
          variant: gridVariant,
          columns: gridColumns
        },
        contentKey: 'featuredGrid'
      },
      {
        id: 'actions-commit',
        type: 'QuickActions',
        props: {
          title: 'Commit to Next Step'
        },
        contentKey: 'quickStartActions'
      },
      {
        id: 'faq-runtime',
        type: 'FAQ',
        props: {
          title: 'Runtime Personalization Notes'
        },
        contentKey: 'faqGeneral'
      }
    ]
  ];

  return moduleTemplates[seededIndex(seed, 'module-template', moduleTemplates.length)];
}

function asObject(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
}

function normalizeIntentProfile(value: unknown): IntentProfile {
  const fallback = defaultIntentProfile();
  const record = asObject(value);
  if (!record) {
    return fallback;
  }

  const goal = typeof record.goal === 'string' ? record.goal.trim() : '';
  const vibeRaw = typeof record.vibe === 'string' ? record.vibe : fallback.vibe;
  const densityRaw = typeof record.density === 'string' ? record.density : fallback.density;

  const vibe: IntentProfile['vibe'] =
    vibeRaw === 'visual' || vibeRaw === 'dense' || vibeRaw === 'playful' ? vibeRaw : 'minimal';

  const density: IntentProfile['density'] =
    densityRaw === 'low' || densityRaw === 'high' ? densityRaw : 'medium';

  const topicsRaw = Array.isArray(record.primaryTopics) ? record.primaryTopics : [];
  const primaryTopics = topicsRaw
    .filter((topic): topic is string => typeof topic === 'string')
    .map((topic) => topic.trim())
    .filter(Boolean)
    .slice(0, 4);

  return {
    goal,
    vibe,
    density,
    primaryTopics
  };
}

function normalizeGapSuggestions(value: unknown): GapSuggestion[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((item) => {
      const record = asObject(item);
      if (!record) {
        return null;
      }

      const topic = typeof record.topic === 'string' ? record.topic : '';
      const priorityRaw = typeof record.priority === 'string' ? record.priority : 'medium';
      const reason = typeof record.reason === 'string' ? record.reason : '';
      const suggestedAction =
        typeof record.suggestedAction === 'string' ? record.suggestedAction : '';

      const priority: GapSuggestion['priority'] =
        priorityRaw === 'high' || priorityRaw === 'low' ? priorityRaw : 'medium';

      if (!topic) {
        return null;
      }

      return {
        topic,
        priority,
        reason,
        suggestedAction
      };
    })
    .filter((item): item is GapSuggestion => item !== null);
}

function normalizeWordpressContext(value: unknown): BlueprintGenerationResult['wordpress'] {
  const record = asObject(value);
  if (!record) {
    return null;
  }

  return {
    baseUrl: typeof record.baseUrl === 'string' ? record.baseUrl : '',
    available: Boolean(record.available),
    fetchedAt: typeof record.fetchedAt === 'string' ? record.fetchedAt : '',
    errors: Array.isArray(record.errors)
      ? record.errors.filter((item): item is string => typeof item === 'string')
      : []
  };
}

export async function generateBlueprintFromIntent(
  intentProfile: IntentProfile,
  options?: { visitorId?: string }
): Promise<Blueprint> {
  const visitorId = options?.visitorId ?? 'visitor-local';
  const seed = seedFromIntent(intentProfile, visitorId);
  const timestamp = new Date().toISOString();
  const journey = inferJourneyProfile(intentProfile);

  return {
    version: 1,
    theme: {
      mode: seededMode(intentProfile, seed),
      accent: seededAccent(intentProfile, seed)
    },
    layout: {
      nav: seededNav(intentProfile.density, seed),
      density: intentProfile.density
    },
    modules: buildSeededModules(intentProfile, seed),
    shortcuts: [
      { label: journey.primaryLabel, action: journey.primaryUrl },
      { label: journey.secondaryLabel, action: journey.secondaryUrl },
      { label: 'Explore Main Site', action: 'https://alexanderjgill.com' },
      { label: 'Read Insights', action: 'https://alexanderjgill.com/read/' },
      { label: 'Open Work Archive', action: 'https://alexanderjgill.com/work/' },
      { label: 'Read Bio', action: 'https://alexanderjgill.com/bio/' }
    ],
    createdAt: timestamp,
    updatedAt: timestamp
  };
}

interface BackendBlueprintResponse {
  blueprint: unknown;
  contentOverrides?: unknown;
  gapSuggestions?: unknown;
  wordpress?: unknown;
}

interface BackendOnboardingResponse {
  assistantMessage?: unknown;
  intentProfile?: unknown;
  isComplete?: unknown;
  confidence?: unknown;
}

function isIntentComplete(intent: IntentProfile): boolean {
  return intent.goal.trim().length > 0 && intent.primaryTopics.length >= 2;
}

function userLooksConfused(text: string): boolean {
  const normalized = text.toLowerCase();
  return (
    normalized.includes("don't get it") ||
    normalized.includes('dont get it') ||
    normalized.includes('not sure') ||
    normalized.includes('confused') ||
    normalized === 'what?' ||
    normalized === 'what'
  );
}

function inferGoalFromMessage(message: string): string {
  const clean = message.trim();
  if (clean.length < 12) {
    return '';
  }

  return clean;
}

function composeFollowUpPrompt(nextIntent: IntentProfile, lastUserMessage: string, seed: string): string {
  const missingGoal = nextIntent.goal.trim().length === 0;
  const missingTopics = nextIntent.primaryTopics.length < 2;
  const askedVibe = /minimal|visual|dense|playful/i.test(lastUserMessage);
  const askedDensity = /\blow\b|\bmedium\b|\bhigh\b/i.test(lastUserMessage);

  if (userLooksConfused(lastUserMessage)) {
    return seededPick(seed, 'confusion', [
      'No problem. Should this feel simple and direct, or rich with detail?',
      'All good. Should we prioritize portfolio, insights, or bio content first?',
      'Clear. Tell me the one page area visitors should hit first, and I will shape the flow.'
    ]);
  }

  if (missingGoal) {
    return seededPick(seed, 'ask-goal', [
      'What outcome should this first-time visitor experience drive?',
      'In one line, what should visitors accomplish before leaving the page?',
      'What is the main page journey you want this experience to trigger?'
    ]);
  }

  if (!askedVibe) {
    return seededPick(seed, 'ask-vibe', [
      'What visual tone fits best: minimal, visual, dense, or playful?',
      'Pick the vibe that should guide the interface: minimal, visual, dense, or playful.',
      'Should this feel minimal, visual, dense, or playful overall?'
    ]);
  }

  if (!askedDensity) {
    return seededPick(seed, 'ask-density', [
      'How detailed should the page feel: low, medium, or high density?',
      'Should I keep it lightweight, balanced, or information-rich?',
      'Choose information density: low, medium, or high.'
    ]);
  }

  if (missingTopics) {
    return seededPick(seed, 'ask-topics', [
      'Name 2-4 topics this visitor should see first.',
      'List the top topics to highlight first (2-4 is perfect).',
      'What 2-4 content themes should anchor this experience?'
    ]);
  }

  return seededPick(seed, 'ready', [
    'Perfect. I have enough signal to generate your unique experience.',
    'Great, this is enough to build your personalized interface blueprint.',
    'Excellent. I can now generate a custom shell around this visitor profile.'
  ]);
}

function localOnboardingFallback(
  transcript: OnboardingTranscriptLine[],
  currentIntent: IntentProfile,
  visitorId?: string
): OnboardingTurnResult {
  const lastUser = [...transcript].reverse().find((entry) => entry.role === 'user');
  const latestMessage = lastUser?.text ?? '';
  const seed = `${visitorId ?? 'visitor-local'}:${transcript.length}`;

  const nextIntent: IntentProfile = {
    ...currentIntent,
    goal: currentIntent.goal,
    vibe: currentIntent.vibe,
    density: currentIntent.density,
    primaryTopics: [...currentIntent.primaryTopics]
  };

  const inferredGoal = inferGoalFromMessage(latestMessage);
  if (inferredGoal && nextIntent.goal.trim().length === 0) {
    nextIntent.goal = inferredGoal;
  }

  if (/minimal|visual|dense|playful/i.test(latestMessage)) {
    nextIntent.vibe = normalizeVibe(latestMessage);
  }

  if (/\blow\b|\bmedium\b|\bhigh\b/i.test(latestMessage)) {
    nextIntent.density = normalizeDensity(latestMessage);
  }

  const extractedTopics = parseTopics(latestMessage);
  if (nextIntent.primaryTopics.length < 2 && extractedTopics.length > 0) {
    nextIntent.primaryTopics = Array.from(new Set([...nextIntent.primaryTopics, ...extractedTopics])).slice(
      0,
      4
    );
  }

  const assistantMessage = lastUser
    ? composeFollowUpPrompt(nextIntent, latestMessage, seed)
    : seededPick(seed, 'opening', [
        'Describe the visitor journey you want to create for this session.',
        'Tell me what this visitor should accomplish first, and I will design around it.',
        'What should this personalized experience prioritize first for the visitor?'
      ]);

  const complete = isIntentComplete(nextIntent);

  return {
    assistantMessage,
    intentProfile: nextIntent,
    isComplete: complete,
    confidence: complete ? 0.84 : 0.58,
    source: 'local'
  };
}

async function requestOnboardingTurnFromBackend(
  transcript: OnboardingTranscriptLine[],
  currentIntent: IntentProfile,
  visitorId?: string
): Promise<OnboardingTurnResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_ONBOARDING_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ transcript, currentIntent, visitorId }),
      signal: controller.signal
    });

    if (!response.ok) {
      return null;
    }

    const payload = (await response.json()) as BackendOnboardingResponse;
    const assistantMessage =
      typeof payload.assistantMessage === 'string' && payload.assistantMessage.trim().length > 0
        ? payload.assistantMessage.trim()
        : 'Tell me your main outcome and I will tailor your experience.';

    const intentProfile = normalizeIntentProfile(payload.intentProfile);
    const isComplete = Boolean(payload.isComplete) || isIntentComplete(intentProfile);

    const rawConfidence = typeof payload.confidence === 'number' ? payload.confidence : 0.7;
    const confidence = Math.max(0, Math.min(1, rawConfidence));

    return {
      assistantMessage,
      intentProfile,
      isComplete,
      confidence,
      source: 'backend'
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

export async function generateOnboardingTurnWithFallback(params: {
  transcript: OnboardingTranscriptLine[];
  currentIntent: IntentProfile;
  visitorId?: string;
}): Promise<OnboardingTurnResult> {
  const backendTurn = await requestOnboardingTurnFromBackend(
    params.transcript,
    params.currentIntent,
    params.visitorId
  );
  if (backendTurn) {
    return backendTurn;
  }

  return localOnboardingFallback(params.transcript, params.currentIntent, params.visitorId);
}

async function requestBlueprintFromBackend(
  intentProfile: IntentProfile,
  visitorId?: string
): Promise<BlueprintGenerationResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_BLUEPRINT_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ intentProfile, visitorId }),
      signal: controller.signal
    });

    if (!response.ok) {
      return null;
    }

    const payload = (await response.json()) as unknown;
    const rawResponse = asObject(payload);
    const normalizedPayload: BackendBlueprintResponse = rawResponse
      ? {
          blueprint: rawResponse.blueprint ?? rawResponse,
          contentOverrides: rawResponse.contentOverrides,
          gapSuggestions: rawResponse.gapSuggestions,
          wordpress: rawResponse.wordpress
        }
      : { blueprint: payload };

    const validBlueprint = validateBlueprint(normalizedPayload.blueprint);
    if (!validBlueprint) {
      return null;
    }

    return {
      blueprint: validBlueprint,
      source: 'backend',
      contentOverrides: asObject(normalizedPayload.contentOverrides) ?? {},
      gapSuggestions: normalizeGapSuggestions(normalizedPayload.gapSuggestions),
      wordpress: normalizeWordpressContext(normalizedPayload.wordpress)
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

export async function generateBlueprintWithFallback(
  intentProfile: IntentProfile,
  options?: { visitorId?: string }
): Promise<BlueprintGenerationResult> {
  const backendResult = await requestBlueprintFromBackend(intentProfile, options?.visitorId);
  if (backendResult) {
    return backendResult;
  }

  return {
    blueprint: await generateBlueprintFromIntent(intentProfile, options),
    source: 'stub',
    contentOverrides: {},
    gapSuggestions: [],
    wordpress: null
  };
}
