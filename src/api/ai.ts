import type { Blueprint } from '@/blueprint/schema';

export interface IntentProfile {
  goal: string;
  vibe: 'minimal' | 'visual' | 'dense' | 'playful';
  density: 'low' | 'medium' | 'high';
  primaryTopics: string[];
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

/*
// Future backend integration example:
// export async function generateBlueprintFromIntent(intentProfile: IntentProfile): Promise<Blueprint> {
//   const response = await fetch('/api/ai/blueprint', {
//     method: 'POST',
//     headers: { 'Content-Type': 'application/json' },
//     body: JSON.stringify({ intentProfile })
//   });
//
//   if (!response.ok) {
//     throw new Error('Blueprint generation failed');
//   }
//
//   return (await response.json()) as Blueprint;
// }
*/
