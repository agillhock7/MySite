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

const modeClass = computed(() => {
  if (blueprint.value?.theme.mode === 'dark') {
    return 'mode-dark';
  }

  return 'mode-light';
});

const shellStyle = computed(() => ({
  '--accent': blueprint.value?.theme.accent ?? '#0ea5e9'
}));

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

const wordpressStatus = computed(() => {
  if (!blueprint.value) {
    return '';
  }

  return 'Adaptive visitor journey sourced from alexanderjgill.com';
});

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.replace('/onboarding');
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
  <main v-if="blueprint" class="shell" :class="modeClass" :style="shellStyle">
    <header class="shell-header">
      <div>
        <p class="eyebrow">Adaptive Theme Layer · {{ BUILD_TAG }}</p>
        <h1>{{ blueprint.layout.nav === 'none' ? 'Focused Visitor Journey' : 'Adaptive Site Experience' }}</h1>
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
    radial-gradient(circle at 15% 10%, color-mix(in srgb, var(--accent) 22%, transparent), transparent 32%),
    radial-gradient(circle at 85% 2%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 28%),
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
  --bg: #0b1020;
  --surface: #11182d;
  --surface-muted: #19213b;
  --text-primary: #e5e7eb;
  --text-secondary: #9ca3af;
  --border: #2d3748;
}

.shell-header {
  display: flex;
  gap: 0.85rem;
  justify-content: space-between;
  align-items: flex-start;
  padding: 1rem;
  border-radius: 14px;
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--accent) 16%, transparent),
    color-mix(in srgb, var(--surface) 90%, transparent)
  );
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
}

.eyebrow {
  margin: 0;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.78rem;
}

h1 {
  margin: 0.2rem 0 0;
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
  background: var(--surface);
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
  gap: 1rem;
}

.module-stack.density-high {
  gap: 0.6rem;
}

.module-slot {
  position: relative;
}

.slot-type-Hero :deep(.hero-module) {
  padding: 1.35rem;
  border-radius: 18px;
}

.slot-2,
.slot-3 {
  align-self: start;
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
