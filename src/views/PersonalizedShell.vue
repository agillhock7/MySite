<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import ModuleRenderer from '@/components/ModuleRenderer.vue';
import { fetchWordpressContentBundle } from '@/api/wp';
import { setRuntimeContentOverrides } from '@/content/library';
import { usePersonalizationStore } from '@/stores/personalization';
import { BUILD_TAG } from '@/meta/build';

const router = useRouter();
const personalization = usePersonalizationStore();

const blueprint = computed(() => personalization.blueprint);

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
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
    '--panel-blur': `${panelBlur}px`
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
    return 'Adaptive Site Experience';
  }

  if (blueprint.value.layout.nav === 'none') {
    return 'Focused Conversion Journey';
  }

  if (blueprint.value.layout.nav === 'side') {
    return 'Guided Multi-Section Experience';
  }

  return 'Adaptive Site Experience';
});

const wordpressStatus = computed(() => {
  if (!blueprint.value) {
    return '';
  }

  return 'Personalized using alexanderjgill.com as source-of-truth content.';
});

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.replace('/onboarding?force=1&reset=1');
}

onMounted(async () => {
  if (!blueprint.value) {
    personalization.loadFromStorage();
  }

  if (!personalization.blueprint) {
    await router.replace('/onboarding');
    return;
  }

  const wpBundle = await fetchWordpressContentBundle();
  if (wpBundle && Object.keys(wpBundle.contentOverrides).length > 0) {
    setRuntimeContentOverrides(wpBundle.contentOverrides);
  }
});
</script>

<template>
  <main v-if="blueprint" class="shell" :class="[modeClass, toneClass]" :style="shellStyle">
    <header class="shell-header">
      <div>
        <p class="eyebrow">Visitor Signature {{ designSignature }} · {{ BUILD_TAG }}</p>
        <h1>{{ shellTitle }}</h1>
        <p class="source-note">{{ wordpressStatus }}</p>
      </div>
      <button type="button" class="reset-btn" @click="resetPersonalization">Reset Personalization</button>
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
      >
        <ModuleRenderer :module="item.module" :shortcuts="blueprint.shortcuts" />
      </article>
    </section>
  </main>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  padding: 1rem;
  background:
    radial-gradient(circle at 12% 8%, color-mix(in srgb, var(--accent) 25%, transparent), transparent 38%),
    radial-gradient(circle at 85% 2%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 33%),
    var(--bg);
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

.reset-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 45%, var(--border));
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  color: var(--text-primary);
  padding: 0.55rem 0.95rem;
}

.shell-nav {
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

.module-stack {
  margin-top: 1.1rem;
  display: grid;
  gap: 1rem;
}

.module-stack.density-low {
  gap: 1.05rem;
}

.module-stack.density-high {
  gap: 0.62rem;
}

.module-slot {
  position: relative;
}

.slot-type-Hero :deep(.hero-module) {
  padding: 1.35rem;
  border-radius: calc(var(--radius) + 4px);
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .slot-type-Hero,
  .slot-type-FAQ {
    grid-column: 1 / -1;
  }
}
</style>
