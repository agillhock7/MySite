import type { Blueprint } from '@/blueprint/schema';
import { validateBlueprint } from '@/blueprint/engine';

export interface IntentProfile {
  goal: string;
  vibe: 'minimal' | 'visual' | 'dense' | 'playful';
  density: 'low' | 'medium' | 'high';
  primaryTopics: string[];
}

export interface BlueprintGenerationResult {
  blueprint: Blueprint;
  source: 'backend' | 'stub';
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

  // Deterministic stub for MVP: returns JSON blueprint only, no executable code.
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

function normalizeBackendPayload(payload: unknown): unknown {
  if (!payload || typeof payload !== 'object') {
    return payload;
  }

  const asRecord = payload as Record<string, unknown>;
  if (asRecord.blueprint && typeof asRecord.blueprint === 'object') {
    return asRecord.blueprint;
  }

  return payload;
}

async function requestBlueprintFromBackend(
  intentProfile: IntentProfile
): Promise<Blueprint | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 12000);

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
    const normalized = normalizeBackendPayload(payload);
    const valid = validateBlueprint(normalized);

    return valid;
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

export async function generateBlueprintWithFallback(
  intentProfile: IntentProfile
): Promise<BlueprintGenerationResult> {
  const backendBlueprint = await requestBlueprintFromBackend(intentProfile);
  if (backendBlueprint) {
    return { blueprint: backendBlueprint, source: 'backend' };
  }

  return {
    blueprint: await generateBlueprintFromIntent(intentProfile),
    source: 'stub'
  };
}
