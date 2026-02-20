import type { GapSuggestion } from '@/api/ai';

const WORDPRESS_CONTENT_ENDPOINT = '/api/content/wp.php';

export interface WordpressContentBundle {
  contentOverrides: Record<string, unknown>;
  gapSuggestions: GapSuggestion[];
  wordpress: {
    baseUrl: string;
    available: boolean;
    fetchedAt: string;
    errors: string[];
  } | null;
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

function normalizeWordpressContext(value: unknown): WordpressContentBundle['wordpress'] {
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

export async function fetchWordpressContentBundle(): Promise<WordpressContentBundle | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 12000);

  try {
    const response = await fetch(WORDPRESS_CONTENT_ENDPOINT, {
      method: 'GET',
      signal: controller.signal
    });

    if (!response.ok) {
      return null;
    }

    const payload = (await response.json()) as unknown;
    const record = asObject(payload);
    if (!record) {
      return null;
    }

    return {
      contentOverrides: asObject(record.contentOverrides) ?? {},
      gapSuggestions: normalizeGapSuggestions(record.gapSuggestions),
      wordpress: normalizeWordpressContext(record.wordpress)
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}
