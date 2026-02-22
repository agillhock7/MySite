<script setup lang="ts">
import { computed, ref } from 'vue';
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

const props = defineProps<{
  signature: string;
  topics: string[];
}>();

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
        feedback: 'Too vague. AI cannot infer what “better” means.'
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

const orderedQuestions = computed(() => buildOrderedQuestions(props.signature, props.topics));
const currentIndex = ref(0);
const score = ref(0);
const selectedIndex = ref<number | null>(null);
const answered = ref(false);
const gameStarted = ref(false);
const complete = computed(() => currentIndex.value >= orderedQuestions.value.length);

const currentQuestion = computed(() => {
  if (complete.value) {
    return null;
  }

  return orderedQuestions.value[currentIndex.value] ?? null;
});

const masteryPercent = computed(() => {
  if (orderedQuestions.value.length === 0) {
    return 0;
  }

  return Math.round((score.value / orderedQuestions.value.length) * 100);
});

const grade = computed(() => {
  if (masteryPercent.value >= 90) return 'Elite Prompt Operator';
  if (masteryPercent.value >= 75) return 'Advanced Prompt Builder';
  if (masteryPercent.value >= 55) return 'Solid Prompt Crafter';
  return 'Prompt Apprentice';
});

const lesson = computed(() => {
  const question = currentQuestion.value;
  if (!question || selectedIndex.value === null) {
    return '';
  }

  return question.options[selectedIndex.value]?.feedback ?? '';
});

function startGame(): void {
  gameStarted.value = true;
  currentIndex.value = 0;
  score.value = 0;
  selectedIndex.value = null;
  answered.value = false;
}

function chooseOption(index: number): void {
  if (!currentQuestion.value || answered.value) {
    return;
  }

  selectedIndex.value = index;
  answered.value = true;

  if (index === currentQuestion.value.bestIndex) {
    score.value += 1;
  }
}

function nextQuestion(): void {
  if (!answered.value) {
    return;
  }

  currentIndex.value += 1;
  selectedIndex.value = null;
  answered.value = false;
}

function restartGame(): void {
  startGame();
}
</script>

<template>
  <article class="game-card">
    <header class="game-header">
      <p class="game-kicker">AI Skill Game</p>
      <h2>Prompt Ops Simulator</h2>
      <p class="game-subtitle">Learn practical prompting techniques with quick scenario rounds.</p>
    </header>

    <div v-if="!gameStarted" class="game-intro">
      <p>
        Complete {{ orderedQuestions.length }} rounds. Choose the best prompt strategy for each scenario to raise your mastery score.
      </p>
      <button type="button" class="game-btn" @click="startGame">Start Game</button>
    </div>

    <div v-else-if="!complete && currentQuestion" class="game-round">
      <p class="round-meta">Round {{ currentIndex + 1 }} / {{ orderedQuestions.length }}</p>
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
        <button type="button" class="game-btn" :disabled="!answered" @click="nextQuestion">Next Round</button>
      </div>
    </div>

    <div v-else class="game-results">
      <p class="result-score">Score: {{ score }} / {{ orderedQuestions.length }}</p>
      <h3>{{ grade }}</h3>
      <p>You achieved {{ masteryPercent }}% mastery. Keep iterating with structure + context + constraints.</p>
      <button type="button" class="game-btn" @click="restartGame">Play Again</button>
    </div>
  </article>
</template>

<style scoped>
.game-card {
  border: 1px solid #1f2937;
  border-radius: 14px;
  background: rgba(2, 6, 23, 0.86);
  padding: 0.9rem;
}

.game-header h2 {
  margin: 0.35rem 0 0;
  font-size: 1.08rem;
}

.game-kicker {
  margin: 0;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #67e8f9;
}

.game-subtitle {
  margin: 0.38rem 0 0;
  color: #a7f3d0;
}

.game-intro p,
.game-results p,
.round-objective,
.round-scenario,
.feedback {
  margin: 0.55rem 0 0;
  color: #bbf7d0;
}

.round-meta {
  margin: 0;
  color: #67e8f9;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
}

.options-grid {
  margin-top: 0.65rem;
  display: grid;
  gap: 0.55rem;
}

.option-card {
  text-align: left;
  border: 1px solid #1f2937;
  border-radius: 10px;
  background: #0b1220;
  color: #d1fae5;
  padding: 0.65rem;
}

.option-title {
  margin: 0;
  color: #67e8f9;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
}

.option-prompt {
  margin: 0.35rem 0 0;
  color: #d1fae5;
  font-size: 0.84rem;
  line-height: 1.45;
}

.option-card.selected {
  border-color: #0891b2;
}

.option-card.correct {
  border-color: #16a34a;
  background: rgba(22, 163, 74, 0.16);
}

.option-card.wrong {
  border-color: #dc2626;
  background: rgba(220, 38, 38, 0.14);
}

.round-footer {
  margin-top: 0.62rem;
  display: flex;
  justify-content: space-between;
  gap: 0.55rem;
  align-items: center;
  flex-wrap: wrap;
}

.game-btn {
  border: 1px solid #134e4a;
  border-radius: 999px;
  background: #022c22;
  color: #99f6e4;
  padding: 0.36rem 0.74rem;
}

.game-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.result-score {
  color: #67e8f9;
}

.game-results h3 {
  margin: 0.35rem 0 0;
}
</style>
