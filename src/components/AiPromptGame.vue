<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { hashText } from '@/utils/seed';

interface PromptOption {
  title: string;
  prompt: string;
  feedback: string;
}

interface PromptQuestion {
  id: string;
  scenario: string;
  objective: string;
  options: PromptOption[];
  bestIndex: number;
}

interface CompletionBadge {
  id: string;
  label: string;
  mastery: number;
  grade: string;
  xp: number;
  correct: number;
  total: number;
  signature: string;
  earnedAt: string;
}

const props = defineProps<{
  signature: string;
  topics: string[];
  themeStyle?: Record<string, string>;
  themeMode?: 'dark' | 'light';
}>();

const BADGE_STORAGE_KEY = 'mysite.ai-skill.badges.v1';
const ROUND_SECONDS = 36;
const SHARE_STATUS_TIMEOUT_MS = 2600;

const questionBank: PromptQuestion[] = [
  {
    id: 'q-clarity',
    scenario: 'A new visitor asks what your blog can do for them.',
    objective: 'Get a concise answer with strong clarity.',
    bestIndex: 1,
    options: [
      {
        title: 'Vague Ask',
        prompt: 'Explain my blog.',
        feedback: 'Too broad. The AI has no audience, format, or constraints.'
      },
      {
        title: 'Structured Prompt',
        prompt:
          'You are a concise website guide. In 4 bullets, explain who this blog helps, what topics it covers, and one next step to start reading. Tone: welcoming.',
        feedback: 'Strong. Clear role, format, scope, and tone produce a focused response.'
      },
      {
        title: 'Overloaded Prompt',
        prompt:
          'Write everything about the site, all categories, all history, every detail, and include links and strategy and branding and social copy in one answer.',
        feedback: 'Overloaded. Too many tasks in one shot lowers quality.'
      }
    ]
  },
  {
    id: 'q-context',
    scenario: 'You want AI to draft a post intro for a specific audience.',
    objective: 'Use context so the output matches intent.',
    bestIndex: 2,
    options: [
      {
        title: 'No Context',
        prompt: 'Write an intro for my post.',
        feedback: 'Missing topic, audience, voice, and outcome.'
      },
      {
        title: 'Context Lite',
        prompt: 'Write a fun intro about business.',
        feedback: 'Better, but still too broad for high-quality personalization.'
      },
      {
        title: 'Context Rich',
        prompt:
          'Write a 120-word intro for founders evaluating hosting. Audience: technical but time-constrained. Voice: confident and practical. Include one question hook and one clear CTA.',
        feedback: 'Excellent. Rich context and constraints guide output quality.'
      }
    ]
  },
  {
    id: 'q-iteration',
    scenario: 'The first AI answer is okay but not great.',
    objective: 'Improve with targeted iteration.',
    bestIndex: 0,
    options: [
      {
        title: 'Targeted Revision',
        prompt:
          'Revise your previous answer: keep the structure, shorten by 30%, add one concrete example, and end with a direct next step.',
        feedback: 'Correct. Iteration works best when you specify exact changes.'
      },
      {
        title: 'Generic Retry',
        prompt: 'Try again but better.',
        feedback: 'Too vague. AI cannot infer what "better" means.'
      },
      {
        title: 'Total Restart',
        prompt: 'Ignore all of that and start over completely with random ideas.',
        feedback: 'Sometimes useful, but usually loses valuable context.'
      }
    ]
  },
  {
    id: 'q-output',
    scenario: 'You need output you can use directly in a dashboard.',
    objective: 'Force a reliable output format.',
    bestIndex: 2,
    options: [
      {
        title: 'Freeform Output',
        prompt: 'Give me suggestions.',
        feedback: 'Too open-ended to integrate cleanly into UI.'
      },
      {
        title: 'Loose Format',
        prompt: 'Give me a list of ideas maybe with titles.',
        feedback: 'Inconsistent format can break downstream rendering.'
      },
      {
        title: 'Schema Prompt',
        prompt:
          'Return JSON only with keys: title (string), reason (string), action (string URL). Provide exactly 3 items.',
        feedback: 'Best practice for app integration and predictable rendering.'
      }
    ]
  },
  {
    id: 'q-safety',
    scenario: 'You want trustworthy AI guidance for users.',
    objective: 'Include guardrails in prompts.',
    bestIndex: 1,
    options: [
      {
        title: 'No Guardrails',
        prompt: 'Give users advice on anything quickly.',
        feedback: 'Risky. No boundaries or verification rules.'
      },
      {
        title: 'Guardrailed Prompt',
        prompt:
          'Provide guidance with confidence labels. If uncertain, say so. Avoid making up links. Ask one clarifying question before giving high-stakes advice.',
        feedback: 'Strong. This reduces hallucinations and improves trust.'
      },
      {
        title: 'Overly Restrictive',
        prompt: 'Never answer anything directly and always refuse.',
        feedback: 'Safe but not useful. Balance safety with utility.'
      }
    ]
  }
];

function buildOrderedQuestions(signature: string, topics: string[]): PromptQuestion[] {
  const seed = hashText(`${signature}|${topics.join('|')}`);

  return [...questionBank].sort((left, right) => {
    const leftWeight = hashText(`${seed}:${left.id}`);
    const rightWeight = hashText(`${seed}:${right.id}`);
    return leftWeight - rightWeight;
  });
}

function safeParseBadges(raw: string | null): CompletionBadge[] {
  if (!raw) {
    return [];
  }

  try {
    const parsed = JSON.parse(raw) as unknown;
    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed
      .map((entry) => {
        if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
          return null;
        }

        const record = entry as Record<string, unknown>;
        const id = typeof record.id === 'string' ? record.id : '';
        const label = typeof record.label === 'string' ? record.label : '';
        const mastery = typeof record.mastery === 'number' ? record.mastery : 0;
        const grade = typeof record.grade === 'string' ? record.grade : '';
        const xp = typeof record.xp === 'number' ? record.xp : 0;
        const correct = typeof record.correct === 'number' ? record.correct : 0;
        const total = typeof record.total === 'number' ? record.total : 0;
        const signature = typeof record.signature === 'string' ? record.signature : '';
        const earnedAt = typeof record.earnedAt === 'string' ? record.earnedAt : '';

        if (!id || !label || !grade || !signature || !earnedAt) {
          return null;
        }

        return {
          id,
          label,
          mastery,
          grade,
          xp,
          correct,
          total,
          signature,
          earnedAt
        } as CompletionBadge;
      })
      .filter((badge): badge is CompletionBadge => badge !== null)
      .slice(0, 16);
  } catch {
    return [];
  }
}

function badgeLabelForMastery(mastery: number): string {
  if (mastery >= 95) return 'Quantum Prompt Architect';
  if (mastery >= 80) return 'Neural Prompt Navigator';
  if (mastery >= 60) return 'Signal Prompt Operator';
  return 'Prompt Explorer';
}

function roundTimeBonus(secondsLeft: number): number {
  return Math.max(0, secondsLeft) * 3;
}

function applyBodyScrollLock(lock: boolean): void {
  if (typeof document === 'undefined') {
    return;
  }
  document.body.style.overflow = lock ? 'hidden' : '';
}

const orderedQuestions = computed(() => buildOrderedQuestions(props.signature, props.topics));
const gameStarted = ref(false);
const currentIndex = ref(0);
const selectedIndex = ref<number | null>(null);
const answered = ref(false);
const score = ref(0);
const xp = ref(0);
const streak = ref(0);
const bestStreak = ref(0);
const lastOutcome = ref<'idle' | 'correct' | 'wrong' | 'timeout'>('idle');
const roundTimeLeft = ref(ROUND_SECONDS);
const roundTimer = ref<number | null>(null);
const gameExpanded = ref(false);
const earnedBadges = ref<CompletionBadge[]>([]);
const sessionBadge = ref<CompletionBadge | null>(null);
const badgeAwardedThisRun = ref(false);
const shareStatus = ref('');
let shareStatusTimer: number | null = null;

const complete = computed(() => currentIndex.value >= orderedQuestions.value.length);
const currentQuestion = computed(() => (complete.value ? null : orderedQuestions.value[currentIndex.value] ?? null));
const progressPercent = computed(() => {
  if (orderedQuestions.value.length === 0) {
    return 0;
  }

  return Math.round((Math.min(currentIndex.value, orderedQuestions.value.length) / orderedQuestions.value.length) * 100);
});
const masteryPercent = computed(() => {
  if (orderedQuestions.value.length === 0) {
    return 0;
  }

  return Math.round((score.value / orderedQuestions.value.length) * 100);
});
const timePercent = computed(() => Math.max(0, Math.round((roundTimeLeft.value / ROUND_SECONDS) * 100)));
const isLastRound = computed(() => currentIndex.value === orderedQuestions.value.length - 1);
const grade = computed(() => {
  if (masteryPercent.value >= 90) return 'Elite Prompt Operator';
  if (masteryPercent.value >= 75) return 'Advanced Prompt Builder';
  if (masteryPercent.value >= 55) return 'Solid Prompt Crafter';
  return 'Prompt Apprentice';
});
const lesson = computed(() => {
  if (!currentQuestion.value) {
    return '';
  }

  if (lastOutcome.value === 'timeout') {
    return 'Time expired. In live AI workflows, constrained prompts beat rushed prompts. Slow down and define role + format + context.';
  }

  if (selectedIndex.value === null) {
    return '';
  }

  return currentQuestion.value.options[selectedIndex.value]?.feedback ?? '';
});

const promptEnginePulse = computed(() => {
  const signal = hashText(`${props.signature}:${props.topics.join('|')}:${currentIndex.value}:${score.value}`) % 9999;
  return signal.toString().padStart(4, '0');
});

const visualOrbs = computed(() => {
  const seed = `${props.signature}:${props.topics.join('|')}`;
  return Array.from({ length: 6 }, (_, index) => {
    const value = hashText(`${seed}:orb:${index}`);
    const top = (value % 82) + 8;
    const left = ((value >>> 4) % 82) + 8;
    const size = 90 + ((value >>> 7) % 170);
    const delay = (value >>> 10) % 8;
    const duration = 10 + ((value >>> 14) % 14);

    return {
      top: `${top}%`,
      left: `${left}%`,
      width: `${size}px`,
      height: `${size}px`,
      '--delay': `${delay}s`,
      '--duration': `${duration}s`
    } as Record<string, string>;
  });
});

const celebrationParticles = computed(() => {
  const seed = hashText(`${props.signature}:${masteryPercent.value}:${xp.value}`);
  return Array.from({ length: 20 }, (_, idx) => {
    const value = hashText(`${seed}:particle:${idx}`);
    const left = (value % 98) + 1;
    const duration = 1.8 + ((value >>> 5) % 14) / 10;
    const delay = ((value >>> 9) % 12) / 10;
    const size = 6 + ((value >>> 12) % 8);

    return {
      left: `${left}%`,
      '--duration': `${duration}s`,
      '--delay': `${delay}s`,
      width: `${size}px`,
      height: `${size}px`
    } as Record<string, string>;
  });
});

const victoryRings = computed(() =>
  Array.from({ length: 4 }, (_, idx) => ({
    '--delay': `${idx * 0.34}s`,
    '--duration': `${2.1 + idx * 0.35}s`
  }))
);

const progressRingStyle = computed<Record<string, string>>(() => ({
  '--progress-angle': `${Math.max(0, Math.min(100, progressPercent.value)) * 3.6}deg`
}));

const latestEarnedBadge = computed(() => earnedBadges.value[0] ?? null);

const mergedThemeStyle = computed<Record<string, string>>(() => ({
  '--game-accent-rgb': '22, 199, 207',
  '--game-accent-soft-rgb': '120, 224, 228',
  '--game-accent-sharp-rgb': '16, 153, 178',
  '--game-text-primary': '#d1fae5',
  '--game-text-secondary': '#a7f3d0',
  '--game-surface-main': 'rgba(2, 8, 24, 0.86)',
  '--game-surface-card': 'rgba(2, 10, 28, 0.72)',
  '--game-surface-elevated': 'rgba(2, 8, 23, 0.74)',
  ...(props.themeStyle ?? {})
}));

const gameClassName = computed(() => [
  props.themeMode === 'light' ? 'theme-light' : 'theme-dark',
  {
    'is-fullscreen': gameExpanded.value,
    'is-started': gameStarted.value,
    'is-complete': complete.value && gameStarted.value
  }
]);

function setShareStatus(message: string): void {
  shareStatus.value = message;
  if (shareStatusTimer !== null) {
    window.clearTimeout(shareStatusTimer);
  }

  shareStatusTimer = window.setTimeout(() => {
    if (shareStatus.value === message) {
      shareStatus.value = '';
    }
  }, SHARE_STATUS_TIMEOUT_MS);
}

function buildSharePayload(): { title: string; text: string; url: string } {
  const scoreLine = `${score.value}/${orderedQuestions.value.length}`;
  const text = `I completed the MySite Prompt Ops Simulator with ${masteryPercent.value}% mastery (${scoreLine}) and ${xp.value} XP. Try it here:`;

  if (typeof window === 'undefined') {
    return {
      title: 'MySite AI Skill Game Score',
      text,
      url: ''
    };
  }

  const shareUrl = new URL('/app/skill-game', window.location.origin);
  shareUrl.searchParams.set('ref', 'ai-skill-game');
  shareUrl.searchParams.set('score', scoreLine);
  shareUrl.searchParams.set('mastery', `${masteryPercent.value}`);

  return {
    title: 'MySite AI Skill Game Score',
    text,
    url: shareUrl.toString()
  };
}

async function shareScore(): Promise<void> {
  if (!complete.value || !gameStarted.value) {
    setShareStatus('Finish the run before sharing.');
    return;
  }

  const payload = buildSharePayload();

  try {
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
      await navigator.share(payload);
      setShareStatus('Score shared.');
      return;
    }

    const intent = new URL('https://twitter.com/intent/tweet');
    intent.searchParams.set('text', `${payload.text} ${payload.url}`.trim());
    window.open(intent.toString(), '_blank', 'noopener,noreferrer');
    setShareStatus('Share intent opened.');
  } catch {
    setShareStatus('Share cancelled.');
  }
}

async function copyShareLink(): Promise<void> {
  if (!complete.value || !gameStarted.value) {
    setShareStatus('Finish the run before copying a link.');
    return;
  }

  const payload = buildSharePayload();
  const value = `${payload.text} ${payload.url}`.trim();

  try {
    await navigator.clipboard.writeText(value);
    setShareStatus('Share text copied.');
  } catch {
    setShareStatus('Clipboard blocked.');
  }
}

function stopRoundTimer(): void {
  if (roundTimer.value !== null) {
    window.clearInterval(roundTimer.value);
    roundTimer.value = null;
  }
}

function startRoundTimer(): void {
  stopRoundTimer();
  roundTimer.value = window.setInterval(() => {
    if (!gameStarted.value || answered.value || complete.value) {
      return;
    }

    if (roundTimeLeft.value <= 1) {
      roundTimeLeft.value = 0;
      stopRoundTimer();
      answered.value = true;
      selectedIndex.value = null;
      streak.value = 0;
      lastOutcome.value = 'timeout';
      return;
    }

    roundTimeLeft.value -= 1;
  }, 1000);
}

function prepareRound(): void {
  selectedIndex.value = null;
  answered.value = false;
  roundTimeLeft.value = ROUND_SECONDS;
  lastOutcome.value = 'idle';
  startRoundTimer();
}

function saveBadges(): void {
  if (typeof window === 'undefined') {
    return;
  }

  try {
    localStorage.setItem(BADGE_STORAGE_KEY, JSON.stringify(earnedBadges.value.slice(0, 16)));
  } catch {
    // no-op
  }
}

function awardCompletionBadge(): CompletionBadge {
  const mastery = masteryPercent.value;
  const badge: CompletionBadge = {
    id: `BDG-${Date.now().toString(36).toUpperCase()}`,
    label: badgeLabelForMastery(mastery),
    mastery,
    grade: grade.value,
    xp: xp.value,
    correct: score.value,
    total: orderedQuestions.value.length,
    signature: props.signature,
    earnedAt: new Date().toISOString()
  };

  earnedBadges.value = [badge, ...earnedBadges.value].slice(0, 16);
  saveBadges();
  badgeAwardedThisRun.value = true;
  return badge;
}

function finalizeGame(): void {
  stopRoundTimer();
  if (!badgeAwardedThisRun.value) {
    sessionBadge.value = awardCompletionBadge();
  }
}

function startGame(): void {
  gameStarted.value = true;
  currentIndex.value = 0;
  score.value = 0;
  xp.value = 0;
  streak.value = 0;
  bestStreak.value = 0;
  sessionBadge.value = null;
  badgeAwardedThisRun.value = false;
  shareStatus.value = '';
  prepareRound();
}

function chooseOption(index: number): void {
  if (!currentQuestion.value || answered.value) {
    return;
  }

  selectedIndex.value = index;
  answered.value = true;
  stopRoundTimer();

  if (index === currentQuestion.value.bestIndex) {
    score.value += 1;
    streak.value += 1;
    bestStreak.value = Math.max(bestStreak.value, streak.value);
    xp.value += 120 + roundTimeBonus(roundTimeLeft.value) + streak.value * 9;
    lastOutcome.value = 'correct';
    return;
  }

  streak.value = 0;
  xp.value += 18;
  lastOutcome.value = 'wrong';
}

function nextQuestion(): void {
  if (!answered.value) {
    return;
  }

  currentIndex.value += 1;
  if (complete.value) {
    finalizeGame();
    return;
  }

  prepareRound();
}

function restartGame(): void {
  startGame();
}

function toggleFullscreen(): void {
  gameExpanded.value = !gameExpanded.value;
}

function closeFullscreen(): void {
  gameExpanded.value = false;
}

function handleGlobalKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && gameExpanded.value) {
    closeFullscreen();
  }
}

watch(gameExpanded, (expanded) => {
  applyBodyScrollLock(expanded);
});

onMounted(() => {
  if (typeof window !== 'undefined') {
    earnedBadges.value = safeParseBadges(localStorage.getItem(BADGE_STORAGE_KEY));
  }
  window.addEventListener('keydown', handleGlobalKeydown);
});

onUnmounted(() => {
  stopRoundTimer();
  applyBodyScrollLock(false);
  if (shareStatusTimer !== null) {
    window.clearTimeout(shareStatusTimer);
    shareStatusTimer = null;
  }
  window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
  <Teleport to="body" :disabled="!gameExpanded">
    <article class="game-card" :class="gameClassName" :style="mergedThemeStyle">
      <div class="aurora-layer" aria-hidden="true">
        <span v-for="(orb, index) in visualOrbs" :key="`orb-${index}`" class="aurora-orb" :style="orb"></span>
      </div>

      <header class="game-header">
        <div>
          <p class="game-kicker">AI Skill Game</p>
          <h2>Prompt Ops Simulator</h2>
          <p class="game-subtitle">Train real prompt engineering instincts with live rounds, speed pressure, and mastery badges.</p>
        </div>

        <div class="game-head-actions">
          <button type="button" class="head-btn" @click="toggleFullscreen">
            {{ gameExpanded ? 'Exit Fullscreen' : 'Fullscreen' }}
          </button>
          <button v-if="gameExpanded" type="button" class="head-btn ghost" @click="closeFullscreen">Close</button>
        </div>
      </header>

      <section class="telemetry-row">
        <article class="telemetry-card progress-card">
          <div class="progress-ring" :style="progressRingStyle">
            <span>{{ progressPercent }}%</span>
          </div>
          <div>
            <p class="telemetry-label">Mission Progress</p>
            <p class="telemetry-value">{{ Math.min(currentIndex, orderedQuestions.length) }} / {{ orderedQuestions.length }} rounds</p>
            <p class="telemetry-detail">Signal {{ promptEnginePulse }}</p>
          </div>
        </article>

        <article class="telemetry-card">
          <p class="telemetry-label">XP</p>
          <p class="telemetry-value">{{ xp }}</p>
          <p class="telemetry-detail">Best streak {{ bestStreak }}</p>
        </article>

        <article class="telemetry-card">
          <p class="telemetry-label">Mastery</p>
          <p class="telemetry-value">{{ masteryPercent }}%</p>
          <p class="telemetry-detail">{{ grade }}</p>
        </article>

        <article class="telemetry-card">
          <p class="telemetry-label">Round Clock</p>
          <p class="telemetry-value">{{ roundTimeLeft }}s</p>
          <div class="timer-bar" role="presentation">
            <span :style="{ width: `${timePercent}%` }"></span>
          </div>
        </article>
      </section>

      <div v-if="!gameStarted" class="game-intro">
        <p>
          This simulator sharpens prompting for production workflows: stronger context, cleaner output formats, better iteration, and safe AI behavior.
        </p>
        <p class="intro-note">
          Fullscreen mode delivers an immersive training run. Completion grants a persistent badge in your dashboard profile.
        </p>
        <div class="intro-actions">
          <button type="button" class="game-btn primary" @click="startGame">Launch Simulation</button>
          <button type="button" class="game-btn" @click="toggleFullscreen">Immersive Mode</button>
        </div>
        <div v-if="latestEarnedBadge" class="last-badge">
          <p class="last-badge-kicker">Last Earned Badge</p>
          <h3>{{ latestEarnedBadge.label }}</h3>
          <p>{{ latestEarnedBadge.mastery }}% mastery · {{ latestEarnedBadge.grade }}</p>
        </div>
      </div>

      <div v-else-if="!complete && currentQuestion" class="game-round">
        <div class="round-head">
          <p class="round-meta">Round {{ currentIndex + 1 }} / {{ orderedQuestions.length }}</p>
          <p class="round-status" :class="`status-${lastOutcome}`">
            {{
              lastOutcome === 'correct'
                ? 'Direct hit'
                : lastOutcome === 'wrong'
                  ? 'Tune and retry'
                  : lastOutcome === 'timeout'
                    ? 'Timeout detected'
                    : 'Awaiting selection'
            }}
          </p>
        </div>

        <p class="round-scenario">{{ currentQuestion.scenario }}</p>
        <p class="round-objective">Objective: {{ currentQuestion.objective }}</p>

        <div class="options-grid">
          <button
            v-for="(option, idx) in currentQuestion.options"
            :key="`${currentQuestion.id}-${option.title}`"
            type="button"
            class="option-card"
            :class="{
              selected: selectedIndex === idx,
              correct: answered && idx === currentQuestion.bestIndex,
              wrong: answered && selectedIndex === idx && idx !== currentQuestion.bestIndex
            }"
            @click="chooseOption(idx)"
          >
            <p class="option-title">{{ option.title }}</p>
            <p class="option-prompt">{{ option.prompt }}</p>
          </button>
        </div>

        <div class="round-footer">
          <p v-if="answered" class="feedback">{{ lesson }}</p>
          <button type="button" class="game-btn primary" :disabled="!answered" @click="nextQuestion">
            {{ isLastRound ? 'Finish Run' : 'Next Round' }}
          </button>
        </div>
      </div>

      <div v-else class="game-results">
        <div class="celebration-layer" aria-hidden="true">
          <span v-for="(particle, index) in celebrationParticles" :key="`p-${index}`" class="particle" :style="particle"></span>
        </div>
        <div class="victory-rings" aria-hidden="true">
          <span v-for="(ring, index) in victoryRings" :key="`ring-${index}`" :style="ring"></span>
        </div>

        <p class="result-score">Score: {{ score }} / {{ orderedQuestions.length }}</p>
        <h3>{{ grade }}</h3>
        <p class="result-copy">You reached {{ masteryPercent }}% mastery with {{ xp }} XP. Keep iterating with structure, context, and guardrails.</p>

        <article v-if="sessionBadge" class="badge-card">
          <p class="badge-kicker">Completion Badge Unlocked</p>
          <h4>{{ sessionBadge.label }}</h4>
          <p>{{ sessionBadge.mastery }}% mastery · {{ sessionBadge.grade }}</p>
          <p class="badge-id">Badge ID {{ sessionBadge.id }}</p>
        </article>

        <div class="results-grid">
          <article>
            <p class="telemetry-label">XP Earned</p>
            <p class="telemetry-value">{{ xp }}</p>
          </article>
          <article>
            <p class="telemetry-label">Peak Streak</p>
            <p class="telemetry-value">{{ bestStreak }}</p>
          </article>
          <article>
            <p class="telemetry-label">Accuracy</p>
            <p class="telemetry-value">{{ masteryPercent }}%</p>
          </article>
        </div>

        <div class="result-actions">
          <button type="button" class="game-btn primary" @click="restartGame">Run Again</button>
          <button type="button" class="game-btn" @click="shareScore">Share Score</button>
          <button type="button" class="game-btn" @click="copyShareLink">Copy Share Text</button>
          <button type="button" class="game-btn" @click="toggleFullscreen">
            {{ gameExpanded ? 'Keep Focus Mode' : 'Replay in Fullscreen' }}
          </button>
        </div>

        <p v-if="shareStatus" class="share-status">{{ shareStatus }}</p>
      </div>
    </article>
  </Teleport>
</template>

<style scoped>
.game-card {
  --game-accent-rgb: 22, 199, 207;
  --game-accent-soft-rgb: 120, 224, 228;
  --game-accent-sharp-rgb: 16, 153, 178;
  --game-text-primary: #d1fae5;
  --game-text-secondary: #a7f3d0;
  --game-surface-main: rgba(2, 8, 24, 0.86);
  --game-surface-card: rgba(2, 10, 28, 0.72);
  --game-surface-elevated: rgba(2, 8, 23, 0.74);

  position: relative;
  border: 1px solid rgba(var(--game-accent-rgb), 0.38);
  border-radius: 18px;
  background:
    radial-gradient(circle at 16% -12%, rgba(var(--game-accent-rgb), 0.18), transparent 42%),
    radial-gradient(circle at 84% 116%, rgba(var(--game-accent-soft-rgb), 0.14), transparent 46%),
    var(--game-surface-main);
  color: var(--game-text-primary);
  padding: 1rem;
  overflow: hidden;
  min-height: 520px;
  display: grid;
  gap: 0.9rem;
  box-shadow: 0 22px 44px rgba(2, 6, 23, 0.44), inset 0 1px 0 rgba(var(--game-accent-soft-rgb), 0.18);
}

.game-card.is-fullscreen {
  position: fixed;
  inset: 0;
  z-index: 240;
  min-height: 100vh;
  border-radius: 0;
  border: none;
  padding: clamp(0.9rem, 2.5vw, 1.7rem);
  backdrop-filter: blur(12px);
}

.aurora-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.aurora-orb {
  position: absolute;
  border-radius: 999px;
  background: radial-gradient(circle at 30% 30%, rgba(var(--game-accent-soft-rgb), 0.3), rgba(7, 21, 45, 0));
  transform: translate3d(-50%, -50%, 0);
  animation: orb-drift var(--duration, 12s) ease-in-out infinite;
  animation-delay: var(--delay, 0s);
}

@keyframes orb-drift {
  0%,
  100% {
    opacity: 0.4;
    transform: translate3d(-50%, -50%, 0) scale(0.95);
  }
  50% {
    opacity: 0.9;
    transform: translate3d(-50%, calc(-50% - 16px), 0) scale(1.05);
  }
}

.game-header,
.telemetry-row,
.game-intro,
.game-round,
.game-results {
  position: relative;
  z-index: 1;
}

.game-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.8rem;
}

.game-kicker {
  margin: 0;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: rgb(var(--game-accent-soft-rgb));
}

.game-header h2 {
  margin: 0.3rem 0 0;
  font-size: clamp(1.05rem, 2vw, 1.36rem);
  letter-spacing: 0.02em;
  color: var(--game-text-primary);
}

.game-subtitle {
  margin: 0.42rem 0 0;
  color: var(--game-text-secondary);
  max-width: 66ch;
  line-height: 1.46;
}

.game-head-actions {
  display: inline-flex;
  gap: 0.45rem;
}

.head-btn {
  border: 1px solid rgba(var(--game-accent-soft-rgb), 0.52);
  border-radius: 999px;
  padding: 0.34rem 0.74rem;
  background: rgba(var(--game-accent-rgb), 0.14);
  color: var(--game-text-primary);
  font-size: 0.72rem;
  letter-spacing: 0.03em;
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.head-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--game-accent-soft-rgb), 0.76);
  background: rgba(var(--game-accent-rgb), 0.26);
}

.head-btn.ghost {
  background: rgba(2, 6, 23, 0.5);
}

.telemetry-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.55rem;
}

.telemetry-card {
  border: 1px solid rgba(var(--game-accent-rgb), 0.3);
  border-radius: 12px;
  background: var(--game-surface-card);
  padding: 0.58rem 0.62rem;
  min-height: 84px;
  display: grid;
  align-content: start;
  gap: 0.25rem;
}

.progress-card {
  display: flex;
  gap: 0.6rem;
  align-items: center;
}

.progress-ring {
  --progress-angle: 0deg;
  width: 52px;
  height: 52px;
  border-radius: 999px;
  background: conic-gradient(rgba(var(--game-accent-rgb), 0.94) var(--progress-angle), rgba(30, 41, 59, 0.7) 0deg);
  display: grid;
  place-items: center;
  flex: 0 0 52px;
}

.progress-ring span {
  width: 40px;
  height: 40px;
  border-radius: 999px;
  display: grid;
  place-items: center;
  font-size: 0.64rem;
  color: var(--game-text-primary);
  background: rgba(2, 6, 23, 0.92);
  border: 1px solid rgba(var(--game-accent-rgb), 0.24);
}

.telemetry-label {
  margin: 0;
  font-size: 0.68rem;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: rgb(var(--game-accent-soft-rgb));
}

.telemetry-value {
  margin: 0;
  font-size: 1.04rem;
  color: var(--game-text-primary);
  font-weight: 600;
}

.telemetry-detail {
  margin: 0;
  font-size: 0.72rem;
  color: var(--game-text-secondary);
}

.timer-bar {
  width: 100%;
  height: 7px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.92);
  border: 1px solid rgba(var(--game-accent-rgb), 0.22);
  overflow: hidden;
}

.timer-bar span {
  display: block;
  height: 100%;
  width: 100%;
  background: linear-gradient(90deg, rgba(var(--game-accent-rgb), 0.88), rgba(var(--game-accent-soft-rgb), 0.95));
  transition: width 0.28s ease;
}

.game-intro,
.game-round,
.game-results {
  border: 1px solid rgba(var(--game-accent-rgb), 0.24);
  border-radius: 14px;
  padding: 0.86rem;
  background: var(--game-surface-elevated);
  display: grid;
  gap: 0.62rem;
}

.game-intro p,
.round-objective,
.round-scenario,
.feedback,
.result-copy {
  margin: 0;
  color: var(--game-text-primary);
  line-height: 1.48;
}

.intro-note,
.share-status {
  color: var(--game-text-secondary);
}

.intro-actions,
.result-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.game-btn {
  border: 1px solid rgba(var(--game-accent-rgb), 0.46);
  border-radius: 999px;
  background: rgba(var(--game-accent-rgb), 0.16);
  color: var(--game-text-primary);
  padding: 0.42rem 0.86rem;
  font-size: 0.74rem;
  letter-spacing: 0.03em;
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.game-btn.primary {
  background: linear-gradient(120deg, rgba(var(--game-accent-rgb), 0.42), rgba(var(--game-accent-sharp-rgb), 0.32));
}

.game-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--game-accent-soft-rgb), 0.84);
  background: rgba(var(--game-accent-rgb), 0.28);
}

.game-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.last-badge,
.badge-card {
  border: 1px solid rgba(var(--game-accent-soft-rgb), 0.34);
  border-radius: 12px;
  background: linear-gradient(130deg, rgba(var(--game-accent-rgb), 0.18), rgba(2, 6, 23, 0.62));
  padding: 0.64rem;
}

.last-badge-kicker,
.badge-kicker {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  font-size: 0.66rem;
  color: rgb(var(--game-accent-soft-rgb));
}

.last-badge h3,
.badge-card h4 {
  margin: 0.35rem 0 0;
  color: var(--game-text-primary);
}

.last-badge p,
.badge-card p {
  margin: 0.28rem 0 0;
  color: var(--game-text-secondary);
}

.badge-id {
  font-size: 0.73rem;
}

.round-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.55rem;
}

.round-meta {
  margin: 0;
  color: rgb(var(--game-accent-soft-rgb));
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.round-status {
  margin: 0;
  font-size: 0.71rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: #86efac;
}

.round-status.status-wrong,
.round-status.status-timeout {
  color: #fca5a5;
}

.options-grid {
  display: grid;
  gap: 0.56rem;
}

.option-card {
  text-align: left;
  border: 1px solid rgba(var(--game-accent-rgb), 0.3);
  border-radius: 11px;
  background: linear-gradient(145deg, rgba(3, 14, 36, 0.9), rgba(2, 8, 23, 0.82));
  color: var(--game-text-primary);
  padding: 0.66rem;
  transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
}

.option-card:hover {
  transform: translateY(-2px);
  border-color: rgba(var(--game-accent-soft-rgb), 0.76);
  box-shadow: 0 14px 26px rgba(2, 6, 23, 0.28);
}

.option-title {
  margin: 0;
  color: rgb(var(--game-accent-soft-rgb));
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.option-prompt {
  margin: 0.34rem 0 0;
  color: var(--game-text-primary);
  line-height: 1.48;
  font-size: 0.84rem;
}

.option-card.selected {
  border-color: rgba(var(--game-accent-soft-rgb), 0.78);
}

.option-card.correct {
  border-color: rgba(22, 163, 74, 0.82);
  background: linear-gradient(145deg, rgba(7, 58, 44, 0.9), rgba(3, 18, 20, 0.9));
}

.option-card.wrong {
  border-color: rgba(248, 113, 113, 0.72);
  background: linear-gradient(145deg, rgba(79, 16, 16, 0.76), rgba(30, 6, 6, 0.86));
}

.round-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.66rem;
  flex-wrap: wrap;
}

.feedback {
  max-width: 62ch;
}

.game-results {
  position: relative;
}

.celebration-layer,
.victory-rings {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
}

.particle {
  position: absolute;
  top: -20px;
  border-radius: 999px;
  background: linear-gradient(180deg, rgba(var(--game-accent-soft-rgb), 0.96), rgba(var(--game-accent-rgb), 0.2));
  animation: confetti-fall var(--duration, 2.4s) ease-in infinite;
  animation-delay: var(--delay, 0s);
  opacity: 0.82;
}

.victory-rings span {
  position: absolute;
  left: 50%;
  top: 48%;
  width: 120px;
  height: 120px;
  border: 1px solid rgba(var(--game-accent-soft-rgb), 0.4);
  border-radius: 999px;
  transform: translate(-50%, -50%);
  animation: ring-burst var(--duration, 2.3s) ease-out infinite;
  animation-delay: var(--delay, 0s);
}

@keyframes ring-burst {
  0% {
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.42);
  }
  15% {
    opacity: 0.65;
  }
  100% {
    opacity: 0;
    transform: translate(-50%, -50%) scale(2.2);
  }
}

@keyframes confetti-fall {
  0% {
    transform: translateY(-12px) rotate(0deg);
    opacity: 0;
  }
  15% {
    opacity: 0.95;
  }
  100% {
    transform: translateY(420px) rotate(360deg);
    opacity: 0;
  }
}

.result-score {
  margin: 0;
  color: rgb(var(--game-accent-soft-rgb));
}

.game-results h3 {
  margin: 0;
  font-size: 1.2rem;
  color: var(--game-text-primary);
}

.results-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}

.results-grid article {
  border: 1px solid rgba(var(--game-accent-rgb), 0.32);
  border-radius: 10px;
  background: var(--game-surface-card);
  padding: 0.54rem;
}

.theme-light .head-btn.ghost {
  background: rgba(255, 255, 255, 0.65);
}

@media (max-width: 980px) {
  .telemetry-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .results-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 640px) {
  .game-card {
    min-height: 560px;
    padding: 0.82rem;
  }

  .game-card.is-fullscreen {
    padding: 0.75rem;
  }

  .game-header {
    flex-direction: column;
  }

  .game-head-actions {
    width: 100%;
  }

  .head-btn {
    flex: 1 1 auto;
    text-align: center;
  }

  .telemetry-row {
    grid-template-columns: 1fr;
  }

  .progress-card {
    align-items: flex-start;
  }

  .round-footer {
    flex-direction: column;
    align-items: stretch;
  }

  .game-btn {
    width: 100%;
    text-align: center;
  }
}

@media (prefers-reduced-motion: reduce) {
  .aurora-orb,
  .particle,
  .victory-rings span {
    animation: none;
  }

  .option-card,
  .head-btn,
  .game-btn {
    transition: none;
  }
}
</style>
