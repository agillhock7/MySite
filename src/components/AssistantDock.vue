<script setup lang="ts">
import { computed, ref } from 'vue';
import {
  generateAssistantTurnWithFallback,
  type AssistantActionSuggestion,
  type OnboardingTranscriptLine
} from '@/api/ai';

interface AssistantMessage {
  id: number;
  role: 'assistant' | 'user';
  text: string;
}

const props = defineProps<{
  visitorId: string;
  variantNonce: number;
  designSignature: string;
}>();

const open = ref(true);
const thinking = ref(false);
const input = ref('');
const suggestions = ref<AssistantActionSuggestion[]>([]);
const messages = ref<AssistantMessage[]>([
  {
    id: Date.now(),
    role: 'assistant',
    text: `Design assistant online. Signature ${props.designSignature}. Ask me about UX direction, hosting via Dark Horse Virtue, or AI access.`
  }
]);

const transcript = computed<OnboardingTranscriptLine[]>(() =>
  messages.value.map((message) => ({
    role: message.role,
    text: message.text
  }))
);

function pushMessage(role: AssistantMessage['role'], text: string): void {
  messages.value.push({
    id: Date.now() + Math.floor(Math.random() * 1000),
    role,
    text
  });
}

function normalizeSuggestionAction(action: string): string {
  if (action === 'reopen-onboarding') {
    return '/onboarding?force=1';
  }

  return action;
}

function handleUrlAction(action: string): void {
  const normalizedAction = normalizeSuggestionAction(action);

  if (normalizedAction.startsWith('/')) {
    window.location.assign(normalizedAction);
    return;
  }

  if (/^https?:\/\//i.test(normalizedAction)) {
    window.open(normalizedAction, '_blank', 'noopener,noreferrer');
  }
}

async function requestAssistantTurn(userMessage: string): Promise<void> {
  thinking.value = true;

  const result = await generateAssistantTurnWithFallback({
    transcript: transcript.value,
    userMessage,
    visitorId: props.visitorId,
    variantNonce: props.variantNonce
  });

  thinking.value = false;
  pushMessage('assistant', result.assistantMessage);
  suggestions.value = result.suggestions;
}

async function submit(): Promise<void> {
  if (thinking.value) {
    return;
  }

  const userMessage = input.value.trim();
  if (!userMessage) {
    return;
  }

  pushMessage('user', userMessage);
  input.value = '';
  await requestAssistantTurn(userMessage);
}

async function runSuggestion(suggestion: AssistantActionSuggestion): Promise<void> {
  const normalizedAction = normalizeSuggestionAction(suggestion.action);

  if (normalizedAction === 'ask-hosting') {
    pushMessage('user', 'I want help choosing a hosting path.');
    await requestAssistantTurn('I want help choosing a hosting path.');
    return;
  }

  if (normalizedAction === 'ask-ai-access') {
    pushMessage('user', 'I want an AI access rollout plan.');
    await requestAssistantTurn('I want an AI access rollout plan.');
    return;
  }

  if (normalizedAction.startsWith('/') || /^https?:\/\//i.test(normalizedAction)) {
    handleUrlAction(normalizedAction);
    return;
  }

  input.value = suggestion.label;
}
</script>

<template>
  <aside class="assistant-dock" :class="{ collapsed: !open }" aria-label="Embedded AI assistant">
    <header class="dock-header">
      <div>
        <p class="eyebrow">AI Assistant</p>
        <h3>Guidance Concierge</h3>
        <p class="brand-mini">Alexander Gill · Power plays.</p>
      </div>
      <button type="button" class="toggle" @click="open = !open">{{ open ? 'Hide' : 'Open' }}</button>
    </header>

    <div v-if="open" class="dock-body">
      <div class="messages" aria-live="polite">
        <p
          v-for="message in messages"
          :key="message.id"
          class="message"
          :class="`msg-${message.role}`"
        >
          {{ message.text }}
        </p>
        <p v-if="thinking" class="message msg-assistant thinking">Thinking...</p>
      </div>

      <div v-if="suggestions.length > 0" class="suggestions">
        <button
          v-for="suggestion in suggestions"
          :key="`${suggestion.label}:${suggestion.action}`"
          type="button"
          class="chip"
          @click="runSuggestion(suggestion)"
        >
          {{ suggestion.label }}
        </button>
      </div>

      <form class="input-row" @submit.prevent="submit">
        <input
          v-model="input"
          type="text"
          autocomplete="off"
          placeholder="Ask about hosting, AI access, or UX guidance"
        />
        <button type="submit">Send</button>
      </form>
    </div>
  </aside>
</template>

<style scoped>
.assistant-dock {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 20;
  width: min(380px, calc(100vw - 2rem));
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--accent) 36%, var(--border));
  background:
    radial-gradient(circle at 88% -16%, color-mix(in srgb, var(--accent) 28%, transparent), transparent 48%),
    color-mix(in srgb, var(--surface) 88%, transparent);
  backdrop-filter: blur(calc(var(--panel-blur) + 3px));
  box-shadow: 0 16px 38px color-mix(in srgb, var(--accent) 16%, transparent);
}

.assistant-dock.collapsed {
  width: 220px;
}

.dock-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
  padding: 0.8rem 0.9rem;
  border-bottom: 1px solid color-mix(in srgb, var(--accent) 18%, var(--border));
}

.brand-mini {
  margin: 0.25rem 0 0;
  font-size: 0.7rem;
  color: color-mix(in srgb, var(--accent) 75%, var(--text-secondary));
}

.eyebrow {
  margin: 0;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-secondary);
}

h3 {
  margin: 0.12rem 0 0;
  font-size: 0.98rem;
}

.toggle {
  border: 1px solid color-mix(in srgb, var(--accent) 42%, var(--border));
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
  color: var(--text-primary);
  padding: 0.35rem 0.72rem;
  font-size: 0.78rem;
}

.dock-body {
  display: grid;
  gap: 0.7rem;
  padding: 0.75rem 0.8rem 0.9rem;
}

.messages {
  max-height: 230px;
  overflow: auto;
  display: grid;
  gap: 0.45rem;
}

.message {
  margin: 0;
  border-radius: 10px;
  padding: 0.5rem 0.58rem;
  font-size: 0.88rem;
  line-height: 1.4;
}

.msg-assistant {
  border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
}

.msg-user {
  border: 1px solid var(--border);
  background: color-mix(in srgb, var(--surface-muted) 70%, var(--surface));
}

.thinking {
  opacity: 0.8;
}

.suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.chip {
  border: 1px solid color-mix(in srgb, var(--accent) 38%, var(--border));
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
  color: var(--text-primary);
  padding: 0.32rem 0.65rem;
  font-size: 0.78rem;
}

.input-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.5rem;
}

.input-row input {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: color-mix(in srgb, var(--surface) 92%, transparent);
  color: var(--text-primary);
  padding: 0.5rem 0.62rem;
  outline: none;
  font-size: 0.86rem;
}

.input-row button {
  border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--border));
  border-radius: 10px;
  background: color-mix(in srgb, var(--accent) 18%, var(--surface));
  color: var(--text-primary);
  padding: 0.5rem 0.75rem;
}

@media (max-width: 780px) {
  .assistant-dock {
    right: 0.6rem;
    bottom: 0.6rem;
    width: min(420px, calc(100vw - 1.2rem));
  }

  .messages {
    max-height: 200px;
  }
}
</style>
