export interface HeroContent {
  title: string;
  subtitle: string;
  ctaLabel: string;
}

export interface GridItem {
  title: string;
  description: string;
}

export interface ListItem {
  title: string;
  detail: string;
}

export interface ActionItem {
  label: string;
  action: string;
}

export interface FaqItem {
  question: string;
  answer: string;
}

export const contentLibrary: Record<string, unknown> = {
  heroWelcome: {
    title: 'Build your workspace faster',
    subtitle:
      'Your layout is generated from onboarding intent and can be reset any time.',
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
      { label: 'Re-run Onboarding', action: 'reopen-onboarding' },
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
        question: 'Does onboarding run real AI?',
        answer: 'For MVP, it uses a deterministic local stub that returns schema-safe JSON.'
      }
    ]
  } as { items: FaqItem[] }
};

export function hasContentKey(contentKey: string | undefined): boolean {
  return Boolean(contentKey && Object.prototype.hasOwnProperty.call(contentLibrary, contentKey));
}

export function getContentByKey(contentKey: string | undefined): unknown {
  if (!contentKey || !hasContentKey(contentKey)) {
    return null;
  }

  return contentLibrary[contentKey];
}
