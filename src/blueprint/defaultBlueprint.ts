import type { Blueprint } from './schema';

export const DEFAULT_BLUEPRINT_VERSION = 1;

const nowIso = () => new Date().toISOString();

export function defaultBlueprint(): Blueprint {
  const timestamp = nowIso();

  return {
    version: DEFAULT_BLUEPRINT_VERSION,
    theme: {
      mode: 'dark',
      accent: '#6ee7b7'
    },
    layout: {
      nav: 'top',
      density: 'medium'
    },
    modules: [
      {
        id: 'hero-welcome',
        type: 'Hero',
        props: { title: 'Welcome to your blueprint workspace' },
        contentKey: 'heroWelcome'
      },
      {
        id: 'content-grid-featured',
        type: 'ContentGrid',
        props: { title: 'Featured Blocks' },
        contentKey: 'featuredGrid'
      },
      {
        id: 'content-list-next-steps',
        type: 'ContentList',
        props: { title: 'Suggested Next Steps' },
        contentKey: 'nextStepsList'
      },
      {
        id: 'quick-actions-default',
        type: 'QuickActions',
        props: { title: 'Quick Actions' },
        contentKey: 'quickStartActions'
      },
      {
        id: 'faq-default',
        type: 'FAQ',
        props: { title: 'Common Questions' },
        contentKey: 'faqGeneral'
      }
    ],
    shortcuts: [
      { label: 'Start Tour', action: 'start-tour' },
      { label: 'Open Notes', action: 'open-notes' },
      { label: 'View Plan', action: 'view-plan' }
    ],
    createdAt: timestamp,
    updatedAt: timestamp
  };
}
