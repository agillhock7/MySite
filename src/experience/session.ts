import { hashText, seededChoice, seededUnit } from '@/utils/seed';

export interface ExperiencePost {
  id: string;
  title: string;
  description: string;
  href: string;
  imageUrl: string;
  meta: string;
}

export interface ExperienceTrack {
  id: string;
  label: string;
  summary: string;
  ctaLabel: string;
  ctaHref: string;
  signal: string;
}

export interface ExperienceScene {
  codename: string;
  mission: string;
  voice: string;
  pulse: string;
  prompts: string[];
  tracks: ExperienceTrack[];
}

interface BuildExperienceSceneParams {
  visitorId: string;
  signature: string;
  goal: string;
  topics: string[];
  posts: ExperiencePost[];
  nonce: number;
  forcedFocus?: string;
}

function cleanTopicList(topics: string[]): string[] {
  const unique = Array.from(new Set(topics.map((topic) => topic.trim()).filter(Boolean)));
  return unique.slice(0, 6);
}

function fallbackTopics(goal: string): string[] {
  if (!goal) {
    return ['Identity', 'Ideas', 'Momentum'];
  }

  return goal
    .split(/[\s,/]+/)
    .map((token) => token.trim())
    .filter((token) => token.length > 3)
    .slice(0, 3);
}

function toCodename(seed: number): string {
  const left = ['Quantum', 'Signal', 'Neon', 'Pulse', 'Vector', 'Aether', 'Nova', 'Cipher'] as const;
  const right = ['Drift', 'Flux', 'Atlas', 'Echo', 'Current', 'Stack', 'Vault', 'Field'] as const;
  const serial = Math.floor(seededUnit(seed, 'serial') * 9000 + 1000);

  return `${seededChoice(seed, 'code-left', left)} ${seededChoice(seed, 'code-right', right)}-${serial}`;
}

function toMission(seed: number, goal: string, focus: string): string {
  const openings = [
    'Build a memorable first impression in under 20 seconds.',
    'Guide each visitor to their highest-intent next action.',
    'Turn browsing into a high-signal storytelling journey.',
    'Make the content feel personally relevant at first glance.'
  ] as const;

  const goalLine = goal.trim() || seededChoice(seed, 'fallback-goal', openings);
  return `${goalLine} Focus lane: ${focus}.`;
}

function toVoice(seed: number, focus: string): string {
  const tones = [
    'Conversational, intelligent, and direct.',
    'High-energy but clear and practical.',
    'Editorial and cinematic without fluff.',
    'Calm, confident, and insight-forward.'
  ] as const;

  return `Voice profile: ${seededChoice(seed, 'voice', tones)} Topic gravity: ${focus}.`;
}

function toPulse(seed: number): string {
  const pulses = [
    'Pulse: live adaptation enabled',
    'Pulse: identity map synchronized',
    'Pulse: behavior-driven sequencing active',
    'Pulse: narrative stream aligned'
  ] as const;

  return seededChoice(seed, 'pulse', pulses);
}

function toPrompts(seed: number, focus: string): string[] {
  const promptBank = [
    `What outcome do you want first around ${focus}?`,
    `Should this journey feel more deep-dive or quick-hit?`,
    'Do you want tactical guidance or high-level direction first?',
    'Which post should become your opener for credibility?',
    'What should visitors remember after 30 seconds?',
    'Which lane should be emphasized: Work, Lab, Read, or Bio?'
  ];

  const ordered = [...promptBank].sort((left, right) => {
    const leftWeight = hashText(`${seed}:prompt:${left}`);
    const rightWeight = hashText(`${seed}:prompt:${right}`);
    return leftWeight - rightWeight;
  });

  return ordered.slice(0, 3);
}

function toTracks(seed: number, focus: string, posts: ExperiencePost[]): ExperienceTrack[] {
  const safePosts = posts.length > 0
    ? posts
    : [
        {
          id: 'fallback-1',
          title: 'Open the primary blog',
          description: 'No post feed detected right now. Use the main site stream.',
          href: 'https://alexanderjgill.com',
          imageUrl: '',
          meta: 'Fallback'
        }
      ];

  const labels = [
    `${focus} Launch`,
    `${focus} Deep Dive`,
    `${focus} Momentum`
  ];

  return labels.map((label, index) => {
    const post = safePosts[(index + Math.floor(seededUnit(seed, `post-${index}`) * safePosts.length)) % safePosts.length];
    const signals = ['SIG-A', 'SIG-B', 'SIG-C', 'SIG-D', 'SIG-E'];

    return {
      id: `track-${index + 1}`,
      label,
      summary: post.description || `Open the ${focus.toLowerCase()} lane and continue the story arc.`,
      ctaLabel: index === 0 ? 'Start Here' : index === 1 ? 'Continue' : 'Explore',
      ctaHref: post.href,
      signal: `${seededChoice(seed, `signal-${index}`, signals)} · ${post.meta || 'Live stream'}`
    };
  });
}

export function buildExperienceScene(params: BuildExperienceSceneParams): ExperienceScene {
  const topicList = cleanTopicList(params.topics);
  const fallback = fallbackTopics(params.goal);
  const focusPool = topicList.length > 0 ? topicList : fallback.length > 0 ? fallback : ['Identity'];
  const focus = params.forcedFocus?.trim() || seededChoice(hashText(`${params.visitorId}:${params.nonce}`), 'focus', focusPool);

  const seed = hashText(
    `${params.visitorId}|${params.signature}|${params.goal}|${focusPool.join('|')}|${params.nonce}|${params.forcedFocus ?? ''}`
  );

  return {
    codename: toCodename(seed),
    mission: toMission(seed, params.goal, focus),
    voice: toVoice(seed, focus),
    pulse: toPulse(seed),
    prompts: toPrompts(seed, focus),
    tracks: toTracks(seed, focus, params.posts)
  };
}
