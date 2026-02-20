<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import ModuleRenderer from '@/components/ModuleRenderer.vue';
import { usePersonalizationStore } from '@/stores/personalization';

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
  return shortcuts.slice(0, 5);
});

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
  }
});
</script>

<template>
  <main v-if="blueprint" class="shell" :class="modeClass" :style="shellStyle">
    <header class="shell-header">
      <div>
        <p class="eyebrow">UI Blueprint</p>
        <h1>{{ blueprint.layout.nav === 'none' ? 'Minimal Workspace' : 'Personalized Workspace' }}</h1>
      </div>
      <button type="button" class="reset-btn" @click="resetPersonalization">Reset Personalization</button>
    </header>

    <nav v-if="blueprint.layout.nav !== 'none'" class="shell-nav" :class="`nav-${blueprint.layout.nav}`">
      <button v-for="item in navItems" :key="item.action" type="button" class="nav-item">
        {{ item.label }}
      </button>
    </nav>

    <section class="module-stack" :class="`density-${blueprint.layout.density}`">
      <ModuleRenderer
        v-for="module in blueprint.modules"
        :key="module.id"
        :module="module"
        :shortcuts="blueprint.shortcuts"
      />
    </section>
  </main>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  padding: 1rem;
  background: radial-gradient(circle at 100% 0%, color-mix(in srgb, var(--accent) 24%, transparent), transparent 38%),
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

.reset-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 45%, var(--border));
  border-radius: 10px;
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  color: var(--text-primary);
  padding: 0.55rem 0.8rem;
}

.shell-nav {
  margin-top: 1rem;
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.shell-nav.nav-side {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.nav-item {
  border: 1px solid var(--border);
  border-radius: 9px;
  background: var(--surface);
  color: var(--text-primary);
  padding: 0.45rem 0.7rem;
}

.module-stack {
  margin-top: 1rem;
  display: grid;
  gap: 0.8rem;
}

.module-stack.density-low {
  gap: 1rem;
}

.module-stack.density-high {
  gap: 0.6rem;
}

@media (min-width: 960px) {
  .shell {
    padding: 1.25rem 2rem 2rem;
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
}
</style>
