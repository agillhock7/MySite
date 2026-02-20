<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
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
import { BUILD_TAG } from '@/meta/build';
import { bumpDesignIteration, getDesignIteration, getOrCreateVisitorId } from '@/personalization/visitor';
import { usePersonalizationStore } from '@/stores/personalization';

interface TranscriptEntry {
  id: number;
  speaker: 'system' | 'assistant' | 'user';
  text: string;
}

const router = useRouter();
const route = useRoute();
const personalization = usePersonalizationStore();

const transcript = ref<TranscriptEntry[]>([]);
const input = ref('');
const thinking = ref(false);
const transcriptRef = ref<HTMLElement | null>(null);
const chatModeLabel = ref('Live AI chat pending');
const visitorId = getOrCreateVisitorId();
const variantNonce = ref(getDesignIteration());

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

async function seedConversation(): Promise<void> {
  thinking.value = true;
  const turn = await generateOnboardingTurnWithFallback({
    transcript: [],
    currentIntent: intentDraft.value,
    visitorId,
    variantNonce: variantNonce.value
  });
  thinking.value = false;

  intentDraft.value = turn.intentProfile;
  chatModeLabel.value = turn.source === 'backend' ? 'Live AI chat active' : 'Fallback chat mode';

  if (turn.source === 'local' && !fallbackNoticeShown.value) {
    fallbackNoticeShown.value = true;
    pushLine('system', 'Live chat AI unavailable, continuing with local conversational fallback.');
  }

  await assistantReply(turn.assistantMessage, 80);
}

function resetOnboardingState(): void {
  intentDraft.value = defaultIntentProfile();
  turnsTaken.value = 0;
  fallbackNoticeShown.value = false;
}

function normalizeIntentForGeneration(intent: IntentProfile): IntentProfile {
  return {
    goal:
      intent.goal.trim() ||
      'Design a highly personalized headless front-end experience based on this visitor personality and goals.',
    vibe: intent.vibe,
    density: intent.density,
    primaryTopics: intent.primaryTopics.length > 0 ? intent.primaryTopics : ['Identity', 'Interests', 'Goals']
  };
}

function hasEnoughIntent(intent: IntentProfile): boolean {
  return intent.goal.trim().length > 0 && intent.primaryTopics.length >= 1;
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

  const generationNonce = bumpDesignIteration();
  variantNonce.value = generationNonce;

  thinking.value = true;
  const generationResult = await generateBlueprintWithFallback(normalizeIntentForGeneration(intent), {
    visitorId,
    variantNonce: generationNonce,
    transcript: toTranscriptLines(transcript.value)
  });
  thinking.value = false;

  if (Object.keys(generationResult.contentOverrides).length > 0) {
    setRuntimeContentOverrides(generationResult.contentOverrides);
  }

  if (generationResult.source === 'backend' || generationResult.source === 'server_fallback') {
    const hasWordpress = generationResult.wordpress?.available ?? false;
    await assistantReply(
      generationResult.source === 'server_fallback'
        ? 'AI service degraded. Loaded a server-generated fallback from live WordPress content.'
        : hasWordpress
          ? 'AI blueprint generated from backend + live WordPress content.'
          : 'AI blueprint generated from backend. WordPress data was limited.',
      80
    );

    if (generationResult.design?.signature) {
      await assistantReply(
        `Design signature ${generationResult.design.signature} selected after ${generationResult.design.thoughtPasses} thought passes.`,
        80
      );
    }

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
    pushLine('system', 'Commands: /help, /reset, /hardreset, /skip');
    pushLine('system', 'Tip: answer with who you are, your interests, and the experience mood you want.');
    return;
  }

  if (command === '/reset') {
    personalization.resetPersonalization();
    variantNonce.value = getDesignIteration();
    transcript.value = [];
    resetOnboardingState();
    pushLine('system', 'Personalization cache cleared. Starting refinement chat again.');
    await seedConversation();
    return;
  }

  if (command === '/skip') {
    pushLine('system', 'Skipping chat. Using default blueprint.');
    await finalizeBlueprint(defaultBlueprint());
    return;
  }

  if (command === '/hardreset') {
    pushLine('system', 'Performing hard reset and restarting onboarding.');
    personalization.resetPersonalization();
    variantNonce.value = getDesignIteration();
    transcript.value = [];
    resetOnboardingState();
    await seedConversation();
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
    currentIntent: intentDraft.value,
    visitorId,
    variantNonce: variantNonce.value
  });
  thinking.value = false;

  intentDraft.value = turn.intentProfile;
  turnsTaken.value += 1;
  chatModeLabel.value = turn.source === 'backend' ? 'Live AI chat active' : 'Fallback chat mode';

  if (turn.source === 'local' && !fallbackNoticeShown.value) {
    fallbackNoticeShown.value = true;
    pushLine('system', 'Live chat AI unavailable, continuing with local conversational fallback.');
  }

  await assistantReply(turn.assistantMessage, 80);

  if ((turn.isComplete && turnsTaken.value >= 2) || (hasEnoughIntent(intentDraft.value) && turnsTaken.value >= 3) || turnsTaken.value >= 6) {
    await startBlueprintGeneration(intentDraft.value);
  }
}

onMounted(async () => {
  const hasForceFlag = route.query.force === '1' || route.query.reset === '1';
  if (personalization.blueprint && !hasForceFlag) {
    await router.replace('/app');
    return;
  }

  pushLine('system', 'Identity-first design chat initialized. Type /help for commands.');
  pushLine('system', 'I will ask about you first, then generate a unique UX/UI style from that profile.');
  await seedConversation();
});
</script>

<template>
  <main class="onboarding-root">
    <section class="terminal-panel" role="region" aria-label="Onboarding terminal">
      <header class="terminal-header">
        <div class="title-row">
          <span class="dot"></span>
          <h1>Identity design terminal</h1>
        </div>
        <p class="build-meta">{{ chatModeLabel }} · {{ BUILD_TAG }}</p>
        <div class="brand-row">
          <img
            class="brand-icon"
            src="https://alexanderjgill.com/wp-content/uploads/2025/09/A_icon_1_171f1f.png"
            alt="Alexander Gill icon"
            loading="lazy"
          />
          <p class="brand-copy">
            <strong>Alexander Gill</strong>
            <span>Power plays.</span>
          </p>
          <span class="brand-chip">Work</span>
          <span class="brand-chip">Lab</span>
          <span class="brand-chip">Read</span>
          <span class="brand-chip">Bio</span>
          <span class="brand-chip">Markets</span>
        </div>
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
  padding: 0.8rem 1rem;
  border-bottom: 1px solid #111827;
  display: grid;
  gap: 0.55rem;
  background:
    radial-gradient(circle at 92% -30%, rgba(14, 165, 233, 0.16), transparent 45%),
    linear-gradient(180deg, rgba(5, 12, 24, 0.92), rgba(4, 4, 4, 0.96));
}

.title-row {
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
  font-size: clamp(1.05rem, 3.8vw, 1.28rem);
  font-weight: 500;
  letter-spacing: 0.03em;
}

.build-meta {
  margin: 0;
  color: #6ee7ff;
  font-size: 0.8rem;
}

.brand-row {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
}

.brand-icon {
  width: 24px;
  height: 24px;
  border-radius: 999px;
  border: 1px solid rgba(110, 231, 255, 0.5);
  object-fit: cover;
}

.brand-copy {
  margin: 0;
  display: grid;
  line-height: 1.05;
}

.brand-copy strong {
  font-size: 0.78rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #d5f3ff;
}

.brand-copy span {
  font-size: 0.72rem;
  color: #6ee7ff;
}

.brand-chip {
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  border: 1px solid rgba(110, 231, 255, 0.32);
  border-radius: 999px;
  padding: 0.2rem 0.5rem;
  color: #a7f3d0;
  background: rgba(15, 23, 42, 0.48);
}

.transcript {
  overflow: auto;
  padding: 1rem;
  background:
    linear-gradient(transparent, rgba(3, 7, 18, 0.68)),
    repeating-linear-gradient(
      0deg,
      rgba(110, 231, 255, 0.04) 0,
      rgba(110, 231, 255, 0.04) 1px,
      transparent 1px,
      transparent 24px
    );
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
  background:
    linear-gradient(180deg, rgba(2, 6, 23, 0.88), rgba(4, 4, 4, 0.94));
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
