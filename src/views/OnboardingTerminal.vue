<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  defaultIntentProfile,
  generateBlueprintWithFallback,
  generateOnboardingTurnWithFallback,
  type IntentProfile,
  type OnboardingTranscriptLine
} from '@/api/ai';
import { defaultBlueprint } from '@/blueprint/defaultBlueprint';
import { migrateBlueprintIfNeeded, validateBlueprint } from '@/blueprint/engine';
import { setRuntimeContentOverrides } from '@/content/library';
import { usePersonalizationStore } from '@/stores/personalization';

interface TranscriptEntry {
  id: number;
  speaker: 'system' | 'assistant' | 'user';
  text: string;
}

const INITIAL_ASSISTANT_MESSAGE =
  'Tell me what you want this visit to accomplish, and I will tailor the experience for you.';

const router = useRouter();
const personalization = usePersonalizationStore();

const transcript = ref<TranscriptEntry[]>([]);
const input = ref('');
const thinking = ref(false);
const transcriptRef = ref<HTMLElement | null>(null);

const intentDraft = ref<IntentProfile>(defaultIntentProfile());
const turnsTaken = ref(0);
const fallbackNoticeShown = ref(false);

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

async function assistantReply(text: string, delay = 180): Promise<void> {
  if (delay > 0) {
    await sleep(delay);
  }
  pushLine('assistant', text);
}

function resetOnboardingState(): void {
  intentDraft.value = defaultIntentProfile();
  turnsTaken.value = 0;
  fallbackNoticeShown.value = false;
}

function normalizeIntentForGeneration(intent: IntentProfile): IntentProfile {
  return {
    goal: intent.goal.trim() || 'Create a practical conversion-focused site experience',
    vibe: intent.vibe,
    density: intent.density,
    primaryTopics: intent.primaryTopics.length > 0 ? intent.primaryTopics : ['Hosting', 'Pro Suite']
  };
}

function toTranscriptLines(entries: TranscriptEntry[]): OnboardingTranscriptLine[] {
  return entries.map((entry) => ({
    role: entry.speaker,
    text: entry.text
  }));
}

async function finalizeBlueprint(rawBlueprint: unknown): Promise<void> {
  const migrated = migrateBlueprintIfNeeded(rawBlueprint);
  const valid = validateBlueprint(migrated);
  const resolvedBlueprint = valid ?? defaultBlueprint();

  if (!valid) {
    await assistantReply('Blueprint validation failed. Loading safe default personalization.', 80);
  }

  personalization.setBlueprint(resolvedBlueprint);
  await router.replace('/app');
}

async function startBlueprintGeneration(intent: IntentProfile): Promise<void> {
  await assistantReply('Great, generating your personalized experience now...', 80);

  thinking.value = true;
  const generationResult = await generateBlueprintWithFallback(normalizeIntentForGeneration(intent));
  thinking.value = false;

  if (Object.keys(generationResult.contentOverrides).length > 0) {
    setRuntimeContentOverrides(generationResult.contentOverrides);
  }

  if (generationResult.source === 'backend') {
    const hasWordpress = generationResult.wordpress?.available ?? false;
    await assistantReply(
      hasWordpress
        ? 'AI blueprint generated from backend + live WordPress content.'
        : 'AI blueprint generated from backend. WordPress data was limited.',
      80
    );

    if (generationResult.gapSuggestions.length > 0) {
      const topGap = generationResult.gapSuggestions[0];
      await assistantReply(`Top content gap I detected: ${topGap.topic}.`, 80);
    }
  } else {
    await assistantReply('Backend AI unavailable. Used deterministic local blueprint generator.', 80);
  }

  await finalizeBlueprint(generationResult.blueprint);
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
    await assistantReply(INITIAL_ASSISTANT_MESSAGE, 80);
    return;
  }

  if (command === '/skip') {
    pushLine('system', 'Skipping onboarding. Using default blueprint.');
    await finalizeBlueprint(defaultBlueprint());
    return;
  }

  pushLine('system', `Unknown command: ${command}. Try /help.`);
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

  thinking.value = true;
  const turn = await generateOnboardingTurnWithFallback({
    transcript: toTranscriptLines(transcript.value),
    currentIntent: intentDraft.value
  });
  thinking.value = false;

  intentDraft.value = turn.intentProfile;
  turnsTaken.value += 1;

  if (turn.source === 'local' && !fallbackNoticeShown.value) {
    fallbackNoticeShown.value = true;
    pushLine('system', 'Live chat AI unavailable, continuing with local conversational fallback.');
  }

  await assistantReply(turn.assistantMessage, 80);

  if (turn.isComplete || turnsTaken.value >= 8) {
    await startBlueprintGeneration(intentDraft.value);
  }
}

onMounted(async () => {
  pushLine('system', 'Terminal onboarding initialized. Type /help for commands.');
  await assistantReply(INITIAL_ASSISTANT_MESSAGE, 80);
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
