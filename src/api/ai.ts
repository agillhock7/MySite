import type { Blueprint } from '@/blueprint/schema';
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

function pickAccent(vibe: IntentProfile['vibe']): string {
  const map: Record<IntentProfile['vibe'], string> = {
    minimal: '#22c55e',
    visual: '#0ea5e9',
    dense: '#f97316',
    playful: '#ec4899'
  };

  return map[vibe];
}

function inferNav(density: IntentProfile['density']): 'side' | 'top' | 'none' {
  if (density === 'high') {
    return 'side';
  }

  if (density === 'low') {
    return 'none';
  }

  return 'top';
}

function inferMode(vibe: IntentProfile['vibe']): 'dark' | 'light' {
  return vibe === 'visual' || vibe === 'playful' ? 'light' : 'dark';
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
  intentProfile: IntentProfile
): Promise<Blueprint> {
  const timestamp = new Date().toISOString();
  const topicLabel = intentProfile.primaryTopics[0] ?? 'Core Focus';

  return {
    version: 1,
    theme: {
      mode: inferMode(intentProfile.vibe),
      accent: pickAccent(intentProfile.vibe)
    },
    layout: {
      nav: inferNav(intentProfile.density),
      density: intentProfile.density
    },
    modules: [
      {
        id: 'hero-intent',
        type: 'Hero',
        props: {
          title: intentProfile.goal,
          subtitle: `Primary topic: ${topicLabel}`
        },
        contentKey: 'heroWelcome'
      },
      {
        id: 'grid-featured',
        type: 'ContentGrid',
        props: {
          title: `${topicLabel} Highlights`
        },
        contentKey: 'featuredGrid'
      },
      {
        id: 'list-plan',
        type: 'ContentList',
        props: {
          title: 'Execution Checklist'
        },
        contentKey: 'nextStepsList'
      },
      {
        id: 'actions-primary',
        type: 'QuickActions',
        props: {
          title: 'Common Actions'
        },
        contentKey: 'quickStartActions'
      },
      {
        id: 'faq-primary',
        type: 'FAQ',
        props: {
          title: 'Need-to-Know'
        },
        contentKey: 'faqGeneral'
      }
    ],
    shortcuts: [
      { label: 'Goal', action: intentProfile.goal.slice(0, 32) || 'goal' },
      { label: 'Vibe', action: intentProfile.vibe },
      { label: 'Density', action: intentProfile.density }
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
    normalized === 'what?' ||
    normalized === 'what'
  );
}

function localOnboardingFallback(
  transcript: OnboardingTranscriptLine[],
  currentIntent: IntentProfile
): OnboardingTurnResult {
  const lastUser = [...transcript].reverse().find((entry) => entry.role === 'user');
  const latestMessage = lastUser?.text ?? '';

  const nextIntent: IntentProfile = {
    ...currentIntent,
    goal: currentIntent.goal,
    vibe: currentIntent.vibe,
    density: currentIntent.density,
    primaryTopics: [...currentIntent.primaryTopics]
  };

  if (latestMessage.trim().length > 10 && nextIntent.goal.trim().length === 0) {
    nextIntent.goal = latestMessage.trim();
  }

  if (/minimal|visual|dense|playful/i.test(latestMessage)) {
    nextIntent.vibe = normalizeVibe(latestMessage);
  }

  if (/\blow\b|\bmedium\b|\bhigh\b/i.test(latestMessage)) {
    nextIntent.density = normalizeDensity(latestMessage);
  }

  const extractedTopics = parseTopics(latestMessage);
  if (nextIntent.primaryTopics.length < 2 && extractedTopics.length > 0) {
    nextIntent.primaryTopics = extractedTopics;
  }

  let assistantMessage = 'Tell me what you want this visitor experience to accomplish first.';

  if (!lastUser) {
    assistantMessage =
      'I can tailor this experience fast. What should this visitor journey help you achieve first?';
  } else if (userLooksConfused(latestMessage)) {
    assistantMessage =
      'No problem. In one sentence, what do you want visitors to do first: start hosting, begin Pro Suite onboarding, or explore your work?';
  } else if (nextIntent.goal.trim().length === 0) {
    assistantMessage = 'What main outcome do you want for this visitor journey?';
  } else if (!/minimal|visual|dense|playful/i.test(latestMessage) && currentIntent.vibe === nextIntent.vibe) {
    assistantMessage = 'What vibe fits best: minimal, visual, dense, or playful?';
  } else if (!/\blow\b|\bmedium\b|\bhigh\b/i.test(latestMessage) && currentIntent.density === nextIntent.density) {
    assistantMessage =
      'How detailed should it feel: low (simple), medium (balanced), or high (information-rich)?';
  } else if (nextIntent.primaryTopics.length < 2) {
    assistantMessage =
      'Give me 2-4 topics to highlight (for example: hosting, Pro Suite onboarding, case studies, contact).';
  } else {
    assistantMessage = 'Perfect. I have enough context to generate your personalized experience.';
  }

  const complete = isIntentComplete(nextIntent);

  return {
    assistantMessage,
    intentProfile: nextIntent,
    isComplete: complete,
    confidence: complete ? 0.82 : 0.56,
    source: 'local'
  };
}

async function requestOnboardingTurnFromBackend(
  transcript: OnboardingTranscriptLine[],
  currentIntent: IntentProfile
): Promise<OnboardingTurnResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_ONBOARDING_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ transcript, currentIntent }),
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
}): Promise<OnboardingTurnResult> {
  const backendTurn = await requestOnboardingTurnFromBackend(params.transcript, params.currentIntent);
  if (backendTurn) {
    return backendTurn;
  }

  return localOnboardingFallback(params.transcript, params.currentIntent);
}

async function requestBlueprintFromBackend(
  intentProfile: IntentProfile
): Promise<BlueprintGenerationResult | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 15000);

  try {
    const response = await fetch(BACKEND_BLUEPRINT_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ intentProfile }),
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
  intentProfile: IntentProfile
): Promise<BlueprintGenerationResult> {
  const backendResult = await requestBlueprintFromBackend(intentProfile);
  if (backendResult) {
    return backendResult;
  }

  return {
    blueprint: await generateBlueprintFromIntent(intentProfile),
    source: 'stub',
    contentOverrides: {},
    gapSuggestions: [],
    wordpress: null
  };
}
