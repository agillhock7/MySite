import type { GapSuggestion } from '@/api/ai';

const WORDPRESS_CONTENT_ENDPOINT = '/api/content/wp.php';
const WORDPRESS_POST_ENDPOINT = '/api/content/post.php';

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

export interface WordpressPostDetailResponse {
  available: boolean;
  post: {
    id: number;
    title: string;
    excerpt: string;
    contentHtml: string;
    canonicalUrl: string;
    date: string;
    modified: string;
    imageUrl: string;
    author: string;
    categories: string[];
    tags: string[];
    readMinutes: number;
  } | null;
  related: Array<{
    id: number;
    title: string;
    excerpt: string;
    href: string;
    imageUrl: string;
    date: string;
  }>;
  errors: string[];
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

function normalizeStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter((item): item is string => typeof item === 'string').map((item) => item.trim()).filter(Boolean);
}

export async function fetchWordpressPostDetail(postId: number): Promise<WordpressPostDetailResponse | null> {
  if (!Number.isFinite(postId) || postId <= 0) {
    return null;
  }

  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 12000);

  try {
    const response = await fetch(`${WORDPRESS_POST_ENDPOINT}?id=${encodeURIComponent(String(postId))}`, {
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

    const rawPost = asObject(record.post);
    const rawRelated = Array.isArray(record.related) ? record.related : [];

    return {
      available: Boolean(record.available),
      post: rawPost
        ? {
            id: Number.parseInt(String(rawPost.id ?? 0), 10),
            title: typeof rawPost.title === 'string' ? rawPost.title : '',
            excerpt: typeof rawPost.excerpt === 'string' ? rawPost.excerpt : '',
            contentHtml: typeof rawPost.contentHtml === 'string' ? rawPost.contentHtml : '',
            canonicalUrl: typeof rawPost.canonicalUrl === 'string' ? rawPost.canonicalUrl : '',
            date: typeof rawPost.date === 'string' ? rawPost.date : '',
            modified: typeof rawPost.modified === 'string' ? rawPost.modified : '',
            imageUrl: typeof rawPost.imageUrl === 'string' ? rawPost.imageUrl : '',
            author: typeof rawPost.author === 'string' ? rawPost.author : '',
            categories: normalizeStringArray(rawPost.categories),
            tags: normalizeStringArray(rawPost.tags),
            readMinutes: Number.parseInt(String(rawPost.readMinutes ?? 1), 10) || 1
          }
        : null,
      related: rawRelated
        .map((item) => {
          const related = asObject(item);
          if (!related) {
            return null;
          }

          return {
            id: Number.parseInt(String(related.id ?? 0), 10),
            title: typeof related.title === 'string' ? related.title : '',
            excerpt: typeof related.excerpt === 'string' ? related.excerpt : '',
            href: typeof related.href === 'string' ? related.href : '',
            imageUrl: typeof related.imageUrl === 'string' ? related.imageUrl : '',
            date: typeof related.date === 'string' ? related.date : ''
          };
        })
        .filter(
          (
            related
          ): related is {
            id: number;
            title: string;
            excerpt: string;
            href: string;
            imageUrl: string;
            date: string;
          } => related !== null && related.id > 0
        ),
      errors: normalizeStringArray(record.errors)
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}
