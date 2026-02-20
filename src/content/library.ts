export interface HeroContent {
  title: string;
  subtitle: string;
  ctaLabel: string;
  ctaUrl?: string;
  imageUrl?: string;
}

export interface GridItem {
  id?: number;
  title: string;
  description: string;
  href?: string;
  canonicalUrl?: string;
  imageUrl?: string;
  meta?: string;
}

export interface ListItem {
  id?: number;
  title: string;
  detail: string;
  href?: string;
  canonicalUrl?: string;
  imageUrl?: string;
}

export interface ActionItem {
  label: string;
  action: string;
}

export interface FaqItem {
  question: string;
  answer: string;
}

const RUNTIME_CONTENT_STORAGE_KEY = 'terminal-runtime-content-v1';

export const contentLibrary: Record<string, unknown> = {
  heroWelcome: {
    title: 'Build your workspace faster',
    subtitle:
      'Your layout is generated from visitor intent and WordPress content and can be reset any time.',
    ctaLabel: 'Explore Modules'
  } as HeroContent,
  featuredGrid: {
    items: [
      { title: 'Intent Snapshot', description: 'Summarized goal, vibe, and topic focus.' },
      { title: 'Module Plan', description: 'Auto-selected module set matched to your needs.' },
      { title: 'Navigation Shape', description: 'Side, top, or minimal navigation.' },
      { title: 'Density Profile', description: 'Low, medium, or high information density.' }
    ]
  } as { items: GridItem[] },
  nextStepsList: {
    items: [
      { title: 'Review Blueprint', detail: 'Confirm module order and content bindings.' },
      { title: 'Adjust Theme', detail: 'Tune accent and mode for your style.' },
      { title: 'Integrate Backend', detail: 'Swap AI stub for your secure backend API.' }
    ]
  } as { items: ListItem[] },
  quickStartActions: {
    actions: [
      { label: 'Refine Personalization', action: 'reopen-onboarding' },
      { label: 'Inspect Blueprint JSON', action: 'inspect-blueprint' },
      { label: 'Open Content Library', action: 'open-content-library' }
    ]
  } as { actions: ActionItem[] },
  faqGeneral: {
    items: [
      {
        question: 'Does this app work offline?',
        answer: 'Yes. If a valid blueprint is in localStorage, the personalized shell still renders.'
      },
      {
        question: 'Can invalid blueprints break rendering?',
        answer: 'No. Invalid data is rejected by Zod and replaced with a safe default blueprint.'
      },
      {
        question: 'Does personalization run real AI?',
        answer: 'When backend is configured it uses real AI, otherwise deterministic local fallback is used.'
      }
    ]
  } as { items: FaqItem[] }
};

let runtimeContentOverrides: Record<string, unknown> = {};

function canUseStorage(): boolean {
  return typeof window !== 'undefined' && typeof localStorage !== 'undefined';
}

function loadRuntimeContentFromStorage(): Record<string, unknown> {
  if (!canUseStorage()) {
    return {};
  }

  try {
    const raw = localStorage.getItem(RUNTIME_CONTENT_STORAGE_KEY);
    if (!raw) {
      return {};
    }

    const parsed = JSON.parse(raw) as unknown;
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return {};
    }

    return parsed as Record<string, unknown>;
  } catch {
    return {};
  }
}

function persistRuntimeContent(overrides: Record<string, unknown>): void {
  if (!canUseStorage()) {
    return;
  }

  try {
    if (Object.keys(overrides).length === 0) {
      localStorage.removeItem(RUNTIME_CONTENT_STORAGE_KEY);
      return;
    }

    localStorage.setItem(RUNTIME_CONTENT_STORAGE_KEY, JSON.stringify(overrides));
  } catch {
    // Ignore localStorage quota or access errors; static fallback still works.
  }
}

runtimeContentOverrides = loadRuntimeContentFromStorage();

export function setRuntimeContentOverrides(overrides: Record<string, unknown>): void {
  runtimeContentOverrides = {
    ...runtimeContentOverrides,
    ...overrides
  };

  persistRuntimeContent(runtimeContentOverrides);
}

export function replaceRuntimeContentOverrides(overrides: Record<string, unknown>): void {
  runtimeContentOverrides = { ...overrides };
  persistRuntimeContent(runtimeContentOverrides);
}

export function clearRuntimeContentOverrides(): void {
  runtimeContentOverrides = {};
  persistRuntimeContent(runtimeContentOverrides);
}

export function hasContentKey(contentKey: string | undefined): boolean {
  if (!contentKey) {
    return false;
  }

  return (
    Object.prototype.hasOwnProperty.call(runtimeContentOverrides, contentKey) ||
    Object.prototype.hasOwnProperty.call(contentLibrary, contentKey)
  );
}

export function getContentByKey(contentKey: string | undefined): unknown {
  if (!contentKey || !hasContentKey(contentKey)) {
    return null;
  }

  if (Object.prototype.hasOwnProperty.call(runtimeContentOverrides, contentKey)) {
    return runtimeContentOverrides[contentKey];
  }

  return contentLibrary[contentKey];
}
