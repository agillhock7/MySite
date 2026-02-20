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

export interface AssistantActionSuggestion {
  label: string;
  action: string;
}

export interface AssistantTurnResult {
  assistantMessage: string;
  suggestions: AssistantActionSuggestion[];
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
  source: 'backend' | 'stub' | 'server_fallback';
  contentOverrides: Record<string, unknown>;
  gapSuggestions: GapSuggestion[];
  wordpress: {
    baseUrl: string;
    available: boolean;
    fetchedAt: string;
    errors: string[];
  } | null;
  design: {
    signature: string;
    profile: string;
    thoughtPasses: number;
    selectedPass: number;
  } | null;
}

const BACKEND_BLUEPRINT_ENDPOINT = '/api/ai/blueprint.php';
const BACKEND_ONBOARDING_ENDPOINT = '/api/ai/onboarding.php';
const BACKEND_ASSISTANT_ENDPOINT = '/api/ai/assistant.php';

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

function inferVibeFromLanguage(raw: string): IntentProfile['vibe'] | null {
  const normalized = raw.toLowerCase();

  if (/playful|fun|quirky|surprise|energetic|whimsical/.test(normalized)) {
    return 'playful';
  }

  if (/cinematic|visual|bold|immersive|editorial|gallery|story/.test(normalized)) {
    return 'visual';
  }

  if (/dense|detailed|technical|deep|analysis|research|comprehensive/.test(normalized)) {
    return 'dense';
  }

  if (/minimal|clean|simple|calm|focused|quiet/.test(normalized)) {
    return 'minimal';
  }

  return null;
}

function inferDensityFromLanguage(raw: string): IntentProfile['density'] | null {
  const normalized = raw.toLowerCase();

  if (/brief|quick|skim|lightweight|simple|short/.test(normalized)) {
    return 'low';
  }

  if (/detailed|deep|thorough|comprehensive|rich|in-depth/.test(normalized)) {
    return 'high';
  }

  if (/balanced|medium|moderate/.test(normalized)) {
    return 'medium';
  }

  return null;
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

function seedFromIntent(intentProfile: IntentProfile, visitorId: string, variantNonce: number): string {
  const topics = intentProfile.primaryTopics.join('|').toLowerCase();
  return `${visitorId}::v${variantNonce}::${intentProfile.goal.toLowerCase()}::${intentProfile.vibe}::${intentProfile.density}::${topics}`;
}

function inferJourneyProfile(intentProfile: IntentProfile): {
  primaryLabel: string;
  primaryUrl: string;
  secondaryLabel: string;
  secondaryUrl: string;
  narrative: string;
} {
  const source = `${intentProfile.goal} ${intentProfile.primaryTopics.join(' ')}`.toLowerCase();
  const baseUrl = 'https://alexanderjgill.com';

  if (/work|portfolio|case|project|build/.test(source)) {
    return {
      primaryLabel: 'Read Featured Posts',
      primaryUrl: baseUrl,
      secondaryLabel: 'Browse Post Archive',
      secondaryUrl: baseUrl,
      narrative: 'Lead with proof-first storytelling, then guide visitors to deeper content.'
    };
  }

  if (/read|learn|article|insight|blog/.test(source)) {
    return {
      primaryLabel: 'Read Latest Posts',
      primaryUrl: baseUrl,
      secondaryLabel: 'Browse More Posts',
      secondaryUrl: baseUrl,
      narrative: 'Prioritize educational flow with contextual links into portfolio and bio.'
    };
  }

  if (/bio|about|alexander|profile/.test(source)) {
    return {
      primaryLabel: 'Read Intro Post',
      primaryUrl: baseUrl,
      secondaryLabel: 'Continue Reading',
      secondaryUrl: baseUrl,
      narrative: 'Center narrative and credibility, then branch to projects and insights.'
    };
  }

  return {
    primaryLabel: 'Read Latest Post',
    primaryUrl: baseUrl,
    secondaryLabel: 'Browse Archive',
    secondaryUrl: baseUrl,
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
  const heroVariant = seededPick(seed, 'hero-variant', [
    'default',
    'spotlight',
    'split',
    'poster',
    'frame'
  ]);

  const gridVariant = seededPick(seed, 'grid-variant', ['default', 'magazine', 'mosaic', 'cards']);
  const listVariant = seededPick(seed, 'list-variant', ['default', 'timeline', 'checklist', 'stacked']);
  const gridColumns = seededPick(seed, 'grid-columns', [2, 2, 3]);
  const gridLimit = seededPick(seed, 'grid-limit', [3, 4, 5, 6]);
  const gridOffset = seededPick(seed, 'grid-offset', [0, 1, 2]);
  const listLimit = seededPick(seed, 'list-limit', [3, 4, 5, 6]);
  const listOffset = seededPick(seed, 'list-offset', [0, 1, 2]);

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
          columns: gridColumns,
          limit: gridLimit,
          offset: gridOffset
        },
        contentKey: 'featuredGrid'
      },
      {
        id: 'list-decision',
        type: 'ContentList',
        props: {
          title: `Editorial path for ${secondTopic}`,
          intro: 'Structured next actions based on content and visitor intent.',
          variant: listVariant,
          limit: listLimit,
          offset: listOffset
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
          columns: gridColumns,
          limit: gridLimit,
          offset: gridOffset
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
          variant: listVariant,
          limit: listLimit,
          offset: listOffset
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
          intro: 'Top priorities inferred from visitor behavior and content context.',
          variant: listVariant,
          limit: listLimit,
          offset: listOffset
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
          columns: gridColumns,
          limit: gridLimit,
          offset: gridOffset
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

  const modules = [...moduleTemplates[seededIndex(seed, 'module-template', moduleTemplates.length)]];
  const extraModuleCandidates: BlueprintModule[] = [
    {
      id: `grid-archive-${seededIndex(seed, 'extra-grid-id', 9000)}`,
      type: 'ContentGrid',
      props: {
        title: `Archive slice: ${firstTopic}`,
        intro: 'Supplemental content slice for this visitor.',
        variant: 'mosaic',
        columns: 3,
        limit: 4,
        offset: 1
      },
      contentKey: 'featuredGrid'
    },
    {
      id: `list-alt-${seededIndex(seed, 'extra-list-id', 9000)}`,
      type: 'ContentList',
      props: {
        title: 'Secondary reading path',
        intro: 'Alternate sequence based on this design seed.',
        variant: 'checklist',
        limit: 5,
        offset: 1
      },
      contentKey: 'nextStepsList'
    }
  ];

  for (const extra of extraModuleCandidates) {
    if (modules.length >= 7) {
      break;
    }

    if (seededIndex(seed, `include-${extra.id}`, 2) === 1) {
      modules.push(extra);
    }
  }

  return modules.slice(0, 8);
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

function normalizeDesignContext(value: unknown): BlueprintGenerationResult['design'] {
  const record = asObject(value);
  if (!record) {
    return null;
  }

  const signature = typeof record.signature === 'string' ? record.signature : '';
  const profile = typeof record.profile === 'string' ? record.profile : '';
  const thoughtPassesRaw =
    typeof record.thoughtPasses === 'number'
      ? record.thoughtPasses
      : Number.parseInt(String(record.thoughtPasses ?? 0), 10);
  const selectedPassRaw =
    typeof record.selectedPass === 'number'
      ? record.selectedPass
      : Number.parseInt(String(record.selectedPass ?? 0), 10);

  const thoughtPasses = Number.isFinite(thoughtPassesRaw) ? Math.max(0, Math.floor(thoughtPassesRaw)) : 0;
  const selectedPass = Number.isFinite(selectedPassRaw) ? Math.max(0, Math.floor(selectedPassRaw)) : 0;

  if (!signature && !profile && thoughtPasses === 0) {
    return null;
  }

  return {
    signature,
    profile,
    thoughtPasses,
    selectedPass
  };
}

export async function generateBlueprintFromIntent(
  intentProfile: IntentProfile,
  options?: { visitorId?: string; variantNonce?: number }
): Promise<Blueprint> {
  const visitorId = options?.visitorId ?? 'visitor-local';
  const variantNonce = options?.variantNonce ?? 0;
  const seed = seedFromIntent(intentProfile, visitorId, variantNonce);
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
      { label: 'Open Main Site', action: 'https://alexanderjgill.com' }
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
  source?: unknown;
  design?: unknown;
}

interface BackendOnboardingResponse {
  assistantMessage?: unknown;
  intentProfile?: unknown;
  isComplete?: unknown;
  confidence?: unknown;
}

function isIntentComplete(intent: IntentProfile): boolean {
  return intent.goal.trim().length > 0 && intent.primaryTopics.length >= 1;
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

function composeFollowUpPrompt(
  nextIntent: IntentProfile,
  lastUserMessage: string,
  seed: string,
  userTurns: number
): string {
  const missingGoal = nextIntent.goal.trim().length === 0;
  const missingTopics = nextIntent.primaryTopics.length < 1;

  if (userLooksConfused(lastUserMessage)) {
    return seededPick(seed, 'confusion', [
      'No problem. Should this feel simple and direct, or rich with detail?',
      'All good. Should we prioritize portfolio, insights, or bio content first?',
      'Clear. Tell me the one content area visitors should hit first, and I will shape the flow.'
    ]);
  }

  if (userTurns <= 1) {
    return seededPick(seed, 'ask-identity', [
      'Before design: what are you into right now, and what should this experience feel like about you?',
      'Let me design around you first. What interests, obsessions, or themes should lead your experience?',
      'Tell me about your style and interests so I can shape a uniquely personal experience.'
    ]);
  }

  if (missingGoal) {
    return seededPick(seed, 'ask-goal', [
      'What should this experience make the visitor feel or do in the first 20 seconds?',
      'If this UX worked perfectly, what action would people take first?',
      'What is the one conversion or behavior this custom experience must drive?'
    ]);
  }

  if (missingTopics) {
    return seededPick(seed, 'ask-topics', [
      'List 2-4 personal interest themes to anchor the design.',
      'Name 2-4 topics that represent your voice and should shape this experience.',
      'What 2-4 themes should this personalized interface revolve around?'
    ]);
  }

  if (userTurns <= 3) {
    return seededPick(seed, 'ask-style-finish', [
      'Last thing: should the interface feel calm, cinematic, or high-energy?',
      'Final calibration: do you want clean minimalism, bold visual storytelling, or dense technical depth?',
      'Quick final tune: should this feel lightweight, balanced, or information-rich?'
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
  visitorId?: string,
  variantNonce = 0
): OnboardingTurnResult {
  const lastUser = [...transcript].reverse().find((entry) => entry.role === 'user');
  const latestMessage = lastUser?.text ?? '';
  const seed = `${visitorId ?? 'visitor-local'}:v${variantNonce}:${transcript.length}`;
  const userTurns = transcript.filter((entry) => entry.role === 'user').length;

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
  } else {
    const inferredVibe = inferVibeFromLanguage(latestMessage);
    if (inferredVibe) {
      nextIntent.vibe = inferredVibe;
    }
  }

  if (/\blow\b|\bmedium\b|\bhigh\b/i.test(latestMessage)) {
    nextIntent.density = normalizeDensity(latestMessage);
  } else {
    const inferredDensity = inferDensityFromLanguage(latestMessage);
    if (inferredDensity) {
      nextIntent.density = inferredDensity;
    }
  }

  const extractedTopics = parseTopics(latestMessage);
  if (nextIntent.primaryTopics.length < 2 && extractedTopics.length > 0) {
    nextIntent.primaryTopics = Array.from(new Set([...nextIntent.primaryTopics, ...extractedTopics])).slice(
      0,
      4
    );
  }

  const assistantMessage = lastUser
    ? composeFollowUpPrompt(nextIntent, latestMessage, seed, userTurns)
    : seededPick(seed, 'opening', [
        'Let us make this personal. What are you into, and what should visitors feel about you immediately?',
        'I will design around your personality first. What interests and tone should define this experience?',
        'Start with you: what topics, identity, or energy should this interface express?'
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
  visitorId?: string,
  variantNonce?: number
): Promise<OnboardingTurnResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_ONBOARDING_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ transcript, currentIntent, visitorId, variantNonce }),
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
  variantNonce?: number;
}): Promise<OnboardingTurnResult> {
  const backendTurn = await requestOnboardingTurnFromBackend(
    params.transcript,
    params.currentIntent,
    params.visitorId,
    params.variantNonce
  );
  if (backendTurn) {
    return backendTurn;
  }

  return localOnboardingFallback(
    params.transcript,
    params.currentIntent,
    params.visitorId,
    params.variantNonce ?? 0
  );
}

async function requestBlueprintFromBackend(
  intentProfile: IntentProfile,
  transcript: OnboardingTranscriptLine[],
  visitorId?: string,
  variantNonce?: number
): Promise<BlueprintGenerationResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_BLUEPRINT_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ intentProfile, transcript, visitorId, variantNonce }),
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
          wordpress: rawResponse.wordpress,
          source: rawResponse.source,
          design: rawResponse.design
        }
      : { blueprint: payload };

    const validBlueprint = validateBlueprint(normalizedPayload.blueprint);
    if (!validBlueprint) {
      return null;
    }

    const sourceRaw = typeof normalizedPayload.source === 'string' ? normalizedPayload.source : 'backend_ai';
    const source: BlueprintGenerationResult['source'] = sourceRaw === 'server_fallback' ? 'server_fallback' : 'backend';

    return {
      blueprint: validBlueprint,
      source,
      contentOverrides: asObject(normalizedPayload.contentOverrides) ?? {},
      gapSuggestions: normalizeGapSuggestions(normalizedPayload.gapSuggestions),
      wordpress: normalizeWordpressContext(normalizedPayload.wordpress),
      design: normalizeDesignContext(normalizedPayload.design)
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

export async function generateBlueprintWithFallback(
  intentProfile: IntentProfile,
  options?: { visitorId?: string; variantNonce?: number; transcript?: OnboardingTranscriptLine[] }
): Promise<BlueprintGenerationResult> {
  const backendResult = await requestBlueprintFromBackend(
    intentProfile,
    options?.transcript ?? [],
    options?.visitorId,
    options?.variantNonce
  );
  if (backendResult) {
    return backendResult;
  }

  return {
    blueprint: await generateBlueprintFromIntent(intentProfile, options),
    source: 'stub',
    contentOverrides: {},
    gapSuggestions: [],
    wordpress: null,
    design: null
  };
}

function normalizeAssistantSuggestions(value: unknown): AssistantActionSuggestion[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .map((item) => {
      const record = asObject(item);
      if (!record) {
        return null;
      }

      const label = typeof record.label === 'string' ? record.label.trim() : '';
      const action = typeof record.action === 'string' ? record.action.trim() : '';

      if (!label || !action) {
        return null;
      }

      return { label, action };
    })
    .filter((item): item is AssistantActionSuggestion => item !== null)
    .slice(0, 4);
}

function localAssistantFallback(userMessage: string): AssistantTurnResult {
  const normalized = userMessage.toLowerCase();

  if (/host|hosting|server|domain|pro suite|dark horse|whmcs/.test(normalized)) {
    return {
      assistantMessage:
        'If you want managed hosting and onboarding, I recommend the Dark Horse Virtue Pro Suite path first. I can guide you through account setup and migration sequence.',
      suggestions: [
        { label: 'Open Pro Suite', action: 'https://hiops.darkhorsevirtue.io' },
        { label: 'View Main Site', action: 'https://alexanderjgill.com' },
        { label: 'Refine UX Again', action: '/onboarding?force=1' }
      ],
      source: 'local'
    };
  }

  if (/ai|automation|assistant|agent|prompt/.test(normalized)) {
    return {
      assistantMessage:
        'For AI access, start with your highest-value workflow and I will map a practical stack with rollout steps, guardrails, and cost control.',
      suggestions: [
        { label: 'Open Pro Suite', action: 'https://hiops.darkhorsevirtue.io' },
        { label: 'Ask About Hosting', action: 'ask-hosting' },
        { label: 'Reset Personalization', action: '/onboarding?force=1&reset=1' }
      ],
      source: 'local'
    };
  }

  return {
    assistantMessage:
      'Tell me your goal and I will guide you to either hosting onboarding, AI access, or a custom UX refinement path.',
    suggestions: [
      { label: 'Dark Horse Virtue', action: 'https://hiops.darkhorsevirtue.io' },
      { label: 'Main Blog', action: 'https://alexanderjgill.com' },
      { label: 'Refine Experience', action: '/onboarding?force=1' }
    ],
    source: 'local'
  };
}

export async function generateAssistantTurnWithFallback(params: {
  transcript: OnboardingTranscriptLine[];
  userMessage: string;
  visitorId?: string;
  variantNonce?: number;
}): Promise<AssistantTurnResult> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_ASSISTANT_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(params),
      signal: controller.signal
    });

    if (!response.ok) {
      return localAssistantFallback(params.userMessage);
    }

    const payload = (await response.json()) as unknown;
    const record = asObject(payload);
    if (!record) {
      return localAssistantFallback(params.userMessage);
    }

    const assistantMessage =
      typeof record.assistantMessage === 'string' && record.assistantMessage.trim().length > 0
        ? record.assistantMessage.trim()
        : localAssistantFallback(params.userMessage).assistantMessage;

    return {
      assistantMessage,
      suggestions: normalizeAssistantSuggestions(record.suggestions),
      source: 'backend'
    };
  } catch {
    return localAssistantFallback(params.userMessage);
  } finally {
    clearTimeout(timeout);
  }
}
