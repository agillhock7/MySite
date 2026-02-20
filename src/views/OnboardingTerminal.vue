<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { generateBlueprintFromIntent, type IntentProfile } from '@/api/ai';
import { defaultBlueprint } from '@/blueprint/defaultBlueprint';
import { migrateBlueprintIfNeeded, validateBlueprint } from '@/blueprint/engine';
import { usePersonalizationStore } from '@/stores/personalization';

interface TranscriptEntry {
  id: number;
  speaker: 'system' | 'assistant' | 'user';
  text: string;
}

const router = useRouter();
const personalization = usePersonalizationStore();

const transcript = ref<TranscriptEntry[]>([]);
const input = ref('');
const thinking = ref(false);
const transcriptRef = ref<HTMLElement | null>(null);

const stepIndex = ref(0);
const intentDraft = ref<IntentProfile>({
  goal: '',
  vibe: 'minimal',
  density: 'medium',
  primaryTopics: []
});

const prompts = [
  'What is your primary goal for this workspace?',
  'Choose a vibe: minimal, visual, dense, or playful.',
  'Choose information density: low, medium, or high.',
  'List 2-4 primary topics (comma separated).'
];

function pushLine(speaker: TranscriptEntry['speaker'], text: string): void {
  transcript.value.push({
    id: Date.now() + Math.floor(Math.random() * 1000),
    speaker,
    text
  });
  scrollTranscriptToBottom();
}

function scrollTranscriptToBottom(): void {
  nextTick(() => {
    const element = transcriptRef.value;
    if (!element) {
      return;
    }
    element.scrollTop = element.scrollHeight;
  });
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, ms);
  });
}

async function assistantReply(text: string): Promise<void> {
  thinking.value = true;
  await sleep(480);
  thinking.value = false;
  pushLine('assistant', text);
}

function resetOnboardingState(): void {
  stepIndex.value = 0;
  intentDraft.value = {
    goal: '',
    vibe: 'minimal',
    density: 'medium',
    primaryTopics: []
  };
}

function normalizeVibe(raw: string): IntentProfile['vibe'] {
  const normalized = raw.trim().toLowerCase();

  if (normalized.includes('play')) {
    return 'playful';
  }

  if (normalized.includes('visual')) {
    return 'visual';
  }

  if (normalized.includes('dense')) {
    return 'dense';
  }

  return 'minimal';
}

function normalizeDensity(raw: string): IntentProfile['density'] {
  const normalized = raw.trim().toLowerCase();

  if (normalized.includes('high')) {
    return 'high';
  }

  if (normalized.includes('low')) {
    return 'low';
  }

  return 'medium';
}

function parseTopics(raw: string): string[] {
  const topics = raw
    .split(',')
    .map((topic) => topic.trim())
    .filter(Boolean);

  if (topics.length > 0) {
    return topics.slice(0, 4);
  }

  return raw
    .split(' ')
    .map((token) => token.trim())
    .filter((token) => token.length > 2)
    .slice(0, 4);
}

async function finalizeBlueprint(rawBlueprint: unknown): Promise<void> {
  const migrated = migrateBlueprintIfNeeded(rawBlueprint);
  const valid = validateBlueprint(migrated);
  const resolvedBlueprint = valid ?? defaultBlueprint();

  if (!valid) {
    await assistantReply('Blueprint validation failed. Loading safe default personalization.');
  }

  personalization.setBlueprint(resolvedBlueprint);
  await router.replace('/app');
}

async function handleCommand(command: string): Promise<void> {
  if (command === '/help') {
    pushLine('system', 'Commands: /help, /reset, /skip');
    return;
  }

  if (command === '/reset') {
    personalization.resetPersonalization();
    transcript.value = [];
    resetOnboardingState();
    pushLine('system', 'Personalization cache cleared. Starting onboarding again.');
    await assistantReply(prompts[0]);
    return;
  }

  if (command === '/skip') {
    pushLine('system', 'Skipping onboarding. Using default blueprint.');
    await finalizeBlueprint(defaultBlueprint());
    return;
  }

  pushLine('system', `Unknown command: ${command}. Try /help.`);
}

function collectIntentAnswer(answer: string): void {
  if (stepIndex.value === 0) {
    intentDraft.value.goal = answer.trim() || 'Create a practical productivity workspace';
    return;
  }

  if (stepIndex.value === 1) {
    intentDraft.value.vibe = normalizeVibe(answer);
    return;
  }

  if (stepIndex.value === 2) {
    intentDraft.value.density = normalizeDensity(answer);
    return;
  }

  if (stepIndex.value === 3) {
    intentDraft.value.primaryTopics = parseTopics(answer);
  }
}

async function handleSubmit(): Promise<void> {
  if (thinking.value) {
    return;
  }

  const value = input.value.trim();
  if (!value) {
    return;
  }

  pushLine('user', value);
  input.value = '';

  if (value.startsWith('/')) {
    await handleCommand(value.toLowerCase());
    return;
  }

  collectIntentAnswer(value);

  if (stepIndex.value < prompts.length - 1) {
    stepIndex.value += 1;
    await assistantReply(prompts[stepIndex.value]);
    return;
  }

  if (!intentDraft.value.goal) {
    intentDraft.value.goal = 'Create a practical productivity workspace';
  }

  if (intentDraft.value.primaryTopics.length === 0) {
    intentDraft.value.primaryTopics = ['Planning', 'Execution'];
  }

  await assistantReply('Thanks. Generating your UI blueprint now...');

  thinking.value = true;
  const generated = await generateBlueprintFromIntent(intentDraft.value);
  thinking.value = false;

  await finalizeBlueprint(generated);
}

onMounted(async () => {
  pushLine('system', 'Terminal onboarding initialized. Type /help for commands.');
  await assistantReply(prompts[0]);
});
</script>

<template>
  <main class="onboarding-root">
    <section class="terminal-panel" role="region" aria-label="Onboarding terminal">
      <header class="terminal-header">
        <span class="dot"></span>
        <h1>Welcome terminal</h1>
      </header>

      <div ref="transcriptRef" class="transcript" aria-live="polite">
        <p
          v-for="line in transcript"
          :key="line.id"
          class="line"
          :class="`speaker-${line.speaker}`"
        >
          <span class="prompt">{{ line.speaker === 'user' ? '>' : '$' }}</span>
          {{ line.text }}
        </p>
        <p v-if="thinking" class="line speaker-assistant thinking">$ thinking...</p>
      </div>

      <form class="input-row" @submit.prevent="handleSubmit">
        <label for="terminal-input" class="sr-only">Terminal input</label>
        <span class="prompt">></span>
        <input
          id="terminal-input"
          v-model="input"
          type="text"
          autocomplete="off"
          autocapitalize="off"
          placeholder="Type your response and press Enter"
        />
      </form>
    </section>
  </main>
</template>

<style scoped>
.onboarding-root {
  min-height: 100vh;
  padding: 1rem;
  background: #000000;
  display: grid;
  place-items: center;
}

.terminal-panel {
  width: min(920px, 100%);
  min-height: min(86vh, 680px);
  border: 1px solid #1f2937;
  border-radius: 16px;
  background: #040404;
  box-shadow: 0 0 0 1px rgba(110, 231, 183, 0.08), 0 0 36px rgba(110, 231, 183, 0.12);
  color: #d1fae5;
  font-family: 'IBM Plex Mono', 'Fira Code', monospace;
  display: grid;
  grid-template-rows: auto 1fr auto;
}

.terminal-header {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #111827;
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: #6ee7b7;
  box-shadow: 0 0 14px rgba(110, 231, 183, 0.9);
}

h1 {
  margin: 0;
  font-size: clamp(1.05rem, 3.8vw, 1.3rem);
  font-weight: 500;
}

.transcript {
  overflow: auto;
  padding: 1rem;
}

.line {
  margin: 0 0 0.8rem;
  line-height: 1.55;
  font-size: clamp(1rem, 3.6vw, 1.1rem);
  white-space: pre-wrap;
}

.prompt {
  margin-right: 0.5rem;
  color: #86efac;
}

.speaker-system {
  color: #a7f3d0;
}

.speaker-assistant {
  color: #d1fae5;
}

.speaker-user {
  color: #e5e7eb;
}

.thinking {
  opacity: 0.8;
}

.input-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.9rem 1rem;
  border-top: 1px solid #111827;
}

.input-row input {
  width: 100%;
  border: 0;
  outline: none;
  color: #f9fafb;
  background: transparent;
  font-size: clamp(1rem, 3.6vw, 1.1rem);
}

.input-row input::placeholder {
  color: #4b5563;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}
</style>
