import type { Blueprint } from '@/blueprint/schema';
import { validateBlueprint } from '@/blueprint/engine';

export interface IntentProfile {
  goal: string;
  vibe: 'minimal' | 'visual' | 'dense' | 'playful';
  density: 'low' | 'medium' | 'high';
  primaryTopics: string[];
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

function asObject(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
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
