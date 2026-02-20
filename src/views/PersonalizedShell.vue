<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ModuleRenderer from '@/components/ModuleRenderer.vue';
import { defaultIntentProfile, generateBlueprintWithFallback, type IntentProfile } from '@/api/ai';
import { fetchWordpressContentBundle } from '@/api/wp';
import { setRuntimeContentOverrides } from '@/content/library';
import { BUILD_TAG } from '@/meta/build';
import { getDesignIteration, getOrCreateVisitorId } from '@/personalization/visitor';
import { usePersonalizationStore } from '@/stores/personalization';

const router = useRouter();
const personalization = usePersonalizationStore();

const initializing = ref(true);
const initializationError = ref('');
const wordpressError = ref('');
const blueprint = computed(() => personalization.blueprint);

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

function deriveAutoIntent(): IntentProfile {
  const intent = defaultIntentProfile();
  intent.goal = 'Create a unique headless front-end experience for alexanderjgill.com visitors.';
  intent.vibe = 'visual';
  intent.density = 'medium';
  intent.primaryTopics = ['Work', 'Read', 'Bio', 'Markets'];
  return intent;
}

async function initializePersonalization(): Promise<void> {
  initializing.value = true;
  initializationError.value = '';
  wordpressError.value = '';

  if (!personalization.blueprint) {
    personalization.loadFromStorage();
  }

  const wpBundle = await fetchWordpressContentBundle();
  if (wpBundle && Object.keys(wpBundle.contentOverrides).length > 0) {
    setRuntimeContentOverrides(wpBundle.contentOverrides);
  }
  if (wpBundle?.wordpress && !wpBundle.wordpress.available) {
    const firstError = wpBundle.wordpress.errors[0] ?? '';
    wordpressError.value = firstError
      ? `WordPress REST fetch warning: ${firstError}`
      : 'WordPress REST fetch warning: no posts returned.';
  }

  if (!personalization.blueprint) {
    const visitorId = getOrCreateVisitorId();
    const generated = await generateBlueprintWithFallback(deriveAutoIntent(), {
      visitorId,
      variantNonce: getDesignIteration()
    });

    if (Object.keys(generated.contentOverrides).length > 0) {
      setRuntimeContentOverrides(generated.contentOverrides);
    }

    personalization.setBlueprint(generated.blueprint);

    if (generated.source === 'stub') {
      initializationError.value =
        'AI endpoint is currently unavailable. Running local personalization fallback.';
    }
  }

  initializing.value = false;
}

const visualSeed = computed(() => {
  if (!blueprint.value) {
    return 0;
  }

  const moduleIds = blueprint.value.modules.map((module) => module.id).join('|');
  return hashText(`${blueprint.value.theme.accent}:${blueprint.value.layout.nav}:${moduleIds}`);
});

const modeClass = computed(() => {
  if (blueprint.value?.theme.mode === 'dark') {
    return 'mode-dark';
  }

  return 'mode-light';
});

const toneClass = computed(() => `tone-${visualSeed.value % 4}`);
const archetypeClass = computed(() => `archetype-${visualSeed.value % 5}`);
const layoutClass = computed(() => `layout-${visualSeed.value % 4}`);
const navStyleClass = computed(() => `navstyle-${visualSeed.value % 3}`);
const archetypeLabel = computed(() => {
  const labels = ['Editorial', 'Atlas', 'Studio', 'Signal', 'Prism'];
  return labels[visualSeed.value % labels.length];
});

const designSignature = computed(() => {
  const signature = visualSeed.value.toString(36).toUpperCase();
  return signature.padStart(6, '0').slice(0, 6);
});

const shellStyle = computed(() => {
  const radius = 12 + (visualSeed.value % 10);
  const panelBlur = 4 + (visualSeed.value % 7);

  return {
    '--accent': blueprint.value?.theme.accent ?? '#0ea5e9',
    '--radius': `${radius}px`,
    '--panel-blur': `${panelBlur}px`,
    '--module-gap': `${0.6 + (visualSeed.value % 5) * 0.12}rem`
  };
});

const navItems = computed(() => {
  const shortcuts = blueprint.value?.shortcuts ?? [];
  return shortcuts.slice(0, 6);
});

const orderedModules = computed(() => {
  const modules = blueprint.value?.modules ?? [];
  return modules.map((module, index) => ({
    module,
    index
  }));
});

const shellTitle = computed(() => {
  if (!blueprint.value) {
    return 'Headless WordPress Experience';
  }

  if (blueprint.value.layout.nav === 'none') {
    return 'Focused Content Journey';
  }

  if (blueprint.value.layout.nav === 'side') {
    return 'Editorial Discovery Layout';
  }

  return 'Headless WordPress Experience';
});

const wordpressStatus = computed(() => {
  if (!blueprint.value) {
    return '';
  }

  return 'Read-only data from alexanderjgill.com powers this frontend.';
});

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await initializePersonalization();
}

async function openChatRefinement(): Promise<void> {
  await router.push('/onboarding?force=1');
}

onMounted(async () => {
  await initializePersonalization();
});
</script>

<template>
  <main v-if="initializing" class="booting-shell">
    <section class="boot-card">
      <p>Building a personalized headless frontend from WordPress content...</p>
    </section>
  </main>

  <main
    v-else-if="blueprint"
    class="shell"
    :class="[modeClass, toneClass, archetypeClass, layoutClass, navStyleClass]"
    :style="shellStyle"
  >
    <div class="backdrop-layer" aria-hidden="true">
      <span class="shape shape-a"></span>
      <span class="shape shape-b"></span>
      <span class="shape shape-c"></span>
    </div>

    <header class="shell-header">
      <div>
        <p class="eyebrow">
          {{ archetypeLabel }} · Visitor Signature {{ designSignature }} · {{ BUILD_TAG }}
        </p>
        <h1>{{ shellTitle }}</h1>
        <p class="source-note">{{ wordpressStatus }}</p>
        <p v-if="initializationError" class="fallback-note">{{ initializationError }}</p>
        <p v-if="wordpressError" class="fallback-note">{{ wordpressError }}</p>
      </div>
      <div class="header-actions">
        <button type="button" class="secondary-btn" @click="openChatRefinement">Refine With Chat</button>
        <button type="button" class="reset-btn" @click="resetPersonalization">Reset Personalization</button>
      </div>
    </header>

    <nav v-if="blueprint.layout.nav !== 'none'" class="shell-nav" :class="`nav-${blueprint.layout.nav}`">
      <template v-for="item in navItems" :key="item.action">
        <a
          v-if="isUrlAction(item.action)"
          :href="item.action"
          class="nav-item"
          target="_blank"
          rel="noopener noreferrer"
        >
          {{ item.label }}
        </a>
        <button v-else type="button" class="nav-item">
          {{ item.label }}
        </button>
      </template>
    </nav>

    <section class="module-stack" :class="`density-${blueprint.layout.density}`">
      <article
        v-for="item in orderedModules"
        :key="item.module.id"
        class="module-slot"
        :class="[`slot-${item.index + 1}`, `slot-type-${item.module.type}`]"
        :style="{ '--stagger': `${item.index * 70}ms` }"
      >
        <ModuleRenderer :module="item.module" :shortcuts="blueprint.shortcuts" />
      </article>
    </section>
  </main>
</template>

<style scoped>
.booting-shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #060c18;
  color: #dbeafe;
  padding: 1rem;
}

.boot-card {
  border: 1px solid #1e3a5f;
  border-radius: 14px;
  background: #0b1425;
  padding: 1rem 1.25rem;
}

.shell {
  position: relative;
  overflow: hidden;
  min-height: 100vh;
  padding: 1rem;
  font-family: var(--shell-font, 'IBM Plex Sans', 'Segoe UI', sans-serif);
  background:
    radial-gradient(circle at 12% 8%, color-mix(in srgb, var(--accent) 25%, transparent), transparent 38%),
    radial-gradient(circle at 85% 2%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 33%),
    var(--bg);
}

.backdrop-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.shape {
  position: absolute;
  border-radius: 999px;
  filter: blur(0.5px);
  opacity: 0.35;
}

.shape-a {
  width: 420px;
  height: 420px;
  top: -140px;
  left: -80px;
  background: color-mix(in srgb, var(--accent) 26%, transparent);
}

.shape-b {
  width: 320px;
  height: 320px;
  top: 12%;
  right: -120px;
  background: color-mix(in srgb, var(--accent) 18%, transparent);
}

.shape-c {
  width: 280px;
  height: 280px;
  bottom: -120px;
  left: 32%;
  background: color-mix(in srgb, var(--accent) 15%, transparent);
}

.mode-light {
  --bg: #f8fafc;
  --surface: #ffffff;
  --surface-muted: #eef4ff;
  --text-primary: #111827;
  --text-secondary: #4b5563;
  --border: #d1d5db;
}

.mode-dark {
  --bg: #090f1f;
  --surface: #101729;
  --surface-muted: #17223c;
  --text-primary: #e5e7eb;
  --text-secondary: #94a3b8;
  --border: #27344f;
}

.tone-0 {
  background-image:
    radial-gradient(circle at 10% 8%, color-mix(in srgb, var(--accent) 22%, transparent), transparent 35%),
    radial-gradient(circle at 90% 4%, color-mix(in srgb, var(--accent) 18%, transparent), transparent 31%);
}

.tone-1 {
  background-image:
    linear-gradient(160deg, color-mix(in srgb, var(--accent) 6%, transparent), transparent 30%),
    radial-gradient(circle at 78% 0%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 32%);
}

.tone-2 {
  background-image:
    radial-gradient(circle at 25% 0%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 28%),
    radial-gradient(circle at 95% 18%, color-mix(in srgb, var(--accent) 17%, transparent), transparent 30%);
}

.tone-3 {
  background-image:
    linear-gradient(120deg, color-mix(in srgb, var(--accent) 8%, transparent), transparent 35%),
    radial-gradient(circle at 82% 5%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 33%);
}

.shell-header {
  position: relative;
  z-index: 1;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-start;
  padding: 1rem;
  border-radius: calc(var(--radius) + 2px);
  background: color-mix(in srgb, var(--surface) 88%, transparent);
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  backdrop-filter: blur(var(--panel-blur));
}

.eyebrow {
  margin: 0;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.78rem;
}

h1 {
  margin: 0.25rem 0 0;
  font-size: clamp(1.3rem, 4.2vw, 2rem);
}

.source-note {
  margin: 0.3rem 0 0;
  color: var(--text-secondary);
  font-size: 0.88rem;
}

.fallback-note {
  margin: 0.45rem 0 0;
  color: color-mix(in srgb, var(--accent) 70%, var(--text-secondary));
  font-size: 0.85rem;
}

.header-actions {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.secondary-btn,
.reset-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 45%, var(--border));
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  color: var(--text-primary);
  padding: 0.55rem 0.95rem;
}

.shell-nav {
  position: relative;
  z-index: 1;
  margin-top: 1rem;
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
  padding-bottom: 0.35rem;
}

.shell-nav.nav-side {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.nav-item {
  border: 1px solid var(--border);
  border-radius: 999px;
  background: color-mix(in srgb, var(--surface) 92%, transparent);
  color: var(--text-primary);
  padding: 0.5rem 0.8rem;
  text-decoration: none;
}

.navstyle-1 .nav-item {
  border-radius: 10px;
  font-weight: 600;
}

.navstyle-2 .nav-item {
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-size: 0.78rem;
}

.module-stack {
  position: relative;
  z-index: 1;
  margin-top: 1.1rem;
  display: grid;
  gap: var(--module-gap);
}

.module-stack.density-low {
  gap: 1.05rem;
}

.module-stack.density-high {
  gap: 0.62rem;
}

.module-slot {
  position: relative;
  animation: rise-in 620ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
  animation-delay: var(--stagger, 0ms);
}

.slot-type-Hero :deep(.hero-module) {
  padding: 1.35rem;
  border-radius: calc(var(--radius) + 4px);
}

.archetype-0 {
  --shell-font: 'Georgia', 'Times New Roman', serif;
}

.archetype-0 :deep(.module-card),
.archetype-0 :deep(.hero-module) {
  border-radius: 8px;
  border-width: 1px;
}

.archetype-1 {
  --shell-font: 'Trebuchet MS', 'Segoe UI', sans-serif;
}

.archetype-1 :deep(.module-card),
.archetype-1 :deep(.hero-module) {
  background: color-mix(in srgb, var(--surface) 86%, transparent);
  backdrop-filter: blur(calc(var(--panel-blur) + 2px));
}

.archetype-2 {
  --shell-font: 'Verdana', 'Segoe UI', sans-serif;
}

.archetype-2 :deep(.module-card),
.archetype-2 :deep(.hero-module) {
  border-width: 2px;
  box-shadow: 6px 6px 0 color-mix(in srgb, var(--accent) 24%, transparent);
}

.archetype-3 {
  --shell-font: 'Palatino', 'Book Antiqua', serif;
}

.archetype-3 :deep(.module-card),
.archetype-3 :deep(.hero-module) {
  border-radius: 2px;
}

.archetype-4 {
  --shell-font: 'Tahoma', 'Segoe UI', sans-serif;
}

.archetype-4 :deep(.module-card),
.archetype-4 :deep(.hero-module) {
  border-width: 1px;
  background: linear-gradient(
    150deg,
    color-mix(in srgb, var(--accent) 10%, var(--surface)),
    var(--surface)
  );
}

@keyframes rise-in {
  from {
    opacity: 0;
    transform: translateY(14px) scale(0.985);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (min-width: 960px) {
  .shell {
    padding: 1.4rem 2.2rem 2.3rem;
  }

  .shell-nav.nav-side {
    width: 320px;
    grid-template-columns: 1fr;
    position: fixed;
    top: 96px;
    left: 2rem;
  }

  .shell-nav.nav-side + .module-stack {
    margin-left: 340px;
  }

  .module-stack {
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }

  .module-slot {
    grid-column: span 6;
  }

  .layout-0 .slot-type-Hero,
  .layout-0 .slot-type-FAQ {
    grid-column: 1 / -1;
  }

  .layout-1 .slot-1 {
    grid-column: 1 / -1;
  }

  .layout-1 .slot-2 {
    grid-column: 1 / span 8;
  }

  .layout-1 .slot-3 {
    grid-column: 9 / -1;
  }

  .layout-1 .slot-4 {
    grid-column: 1 / span 5;
  }

  .layout-1 .slot-5 {
    grid-column: 6 / -1;
  }

  .layout-2 .slot-1 {
    grid-column: 1 / span 7;
  }

  .layout-2 .slot-2 {
    grid-column: 8 / -1;
  }

  .layout-2 .slot-3,
  .layout-2 .slot-4 {
    grid-column: span 6;
  }

  .layout-2 .slot-5 {
    grid-column: 1 / -1;
  }

  .layout-3 .slot-1,
  .layout-3 .slot-4,
  .layout-3 .slot-7 {
    grid-column: 1 / -1;
  }

  .layout-3 .slot-2,
  .layout-3 .slot-5 {
    grid-column: span 5;
  }

  .layout-3 .slot-3,
  .layout-3 .slot-6 {
    grid-column: span 7;
  }
}
</style>
