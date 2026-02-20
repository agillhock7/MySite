<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ModuleRenderer from '@/components/ModuleRenderer.vue';
import AssistantDock from '@/components/AssistantDock.vue';
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
const visitorId = getOrCreateVisitorId();
const assistantVariantNonce = getDesignIteration();

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

function firstStringModuleProp(propName: string): string {
  const modules = blueprint.value?.modules ?? [];
  for (const module of modules) {
    const value = module.props[propName];
    if (typeof value === 'string' && value.trim().length > 0) {
      return value.trim();
    }
  }

  return '';
}

function firstStringArrayModuleProp(propName: string): string[] {
  const modules = blueprint.value?.modules ?? [];
  for (const module of modules) {
    const value = module.props[propName];
    if (!Array.isArray(value)) {
      continue;
    }

    const clean = value
      .filter((item): item is string => typeof item === 'string')
      .map((item) => item.trim())
      .filter(Boolean);

    if (clean.length > 0) {
      return clean;
    }
  }

  return [];
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
    initializing.value = false;
    await router.replace('/onboarding');
    return;
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

const modeClass = computed(() => (blueprint.value?.theme.mode === 'dark' ? 'mode-dark' : 'mode-light'));
const shellProfile = computed(() => {
  const fromBlueprint = firstStringModuleProp('shellProfile').toLowerCase();
  const allowed = ['orbital', 'editorial', 'kinetic', 'glass', 'neo'];

  if (allowed.includes(fromBlueprint)) {
    return fromBlueprint;
  }

  return allowed[visualSeed.value % allowed.length];
});
const shellProfileClass = computed(() => `profile-${shellProfile.value}`);

const typographyProfile = computed(() => {
  const fromBlueprint = firstStringModuleProp('typographyProfile').toLowerCase();
  const allowed = ['grotesk', 'literary', 'display', 'mono'];

  if (allowed.includes(fromBlueprint)) {
    return fromBlueprint;
  }

  return allowed[visualSeed.value % allowed.length];
});

const motionProfile = computed(() => {
  const fromBlueprint = firstStringModuleProp('motionProfile').toLowerCase();
  const allowed = ['calm', 'balanced', 'kinetic'];

  if (allowed.includes(fromBlueprint)) {
    return fromBlueprint;
  }

  return allowed[visualSeed.value % allowed.length];
});

const toneClass = computed(() => `tone-${typographyProfile.value}`);
const motionClass = computed(() => `motion-${motionProfile.value}`);
const experienceMode = computed(() => (visualSeed.value + shellProfile.value.length) % 3);
const experienceClass = computed(() => `experience-${experienceMode.value}`);

const experienceLabel = computed(() => {
  const labelsByProfile: Record<string, string[]> = {
    orbital: ['Orbit Chronicle', 'Orbit Cinema', 'Orbit Atelier'],
    editorial: ['Editorial Chronicle', 'Feature Cinema', 'Archive Atelier'],
    kinetic: ['Pulse Chronicle', 'Motion Cinema', 'Kinetic Atelier'],
    glass: ['Glass Chronicle', 'Prism Cinema', 'Halo Atelier'],
    neo: ['Neo Chronicle', 'Neo Cinema', 'Neo Atelier']
  };

  const labels = labelsByProfile[shellProfile.value] ?? labelsByProfile.editorial;
  return labels[experienceMode.value];
});

const designSignature = computed(() => {
  const fromBlueprint = firstStringModuleProp('signature');
  if (fromBlueprint) {
    return fromBlueprint.slice(0, 10).toUpperCase();
  }

  const signature = visualSeed.value.toString(36).toUpperCase();
  return signature.padStart(6, '0').slice(0, 6);
});

const brandName = computed(() => firstStringModuleProp('brandName') || 'Alexander Gill');
const brandTagline = computed(() => firstStringModuleProp('brandTagline') || 'Power plays.');
const brandBaseUrl = computed(() => firstStringModuleProp('brandBaseUrl') || 'https://alexanderjgill.com');
const brandIconUrl = computed(
  () => firstStringModuleProp('brandIconUrl') || 'https://alexanderjgill.com/wp-content/uploads/2025/09/A_icon_1_171f1f.png'
);
const brandSecondaryIconUrl = computed(
  () =>
    firstStringModuleProp('brandSecondaryIconUrl') ||
    'https://alexanderjgill.com/wp-content/uploads/2025/09/cropped-darkhorsevirtueio_icon_1.png'
);
const brandSections = computed(() => {
  const sections = firstStringArrayModuleProp('brandSections');
  if (sections.length > 0) {
    return sections.slice(0, 7);
  }

  return ['Work', 'Lab', 'Read', 'Bio', 'Markets'];
});

const focusTopics = computed(() => {
  const topics = firstStringArrayModuleProp('focusTopics');
  return topics.length > 0 ? topics.slice(0, 6) : ['Personal brand', 'Future web'];
});

const visualFx = computed(() => firstStringModuleProp('visualFx') || 'neon');
const textureFx = computed(() => firstStringModuleProp('textureFx') || 'glass');
const energyFx = computed(() => firstStringModuleProp('energyFx') || 'balanced');
const styleMotif = computed(() => firstStringModuleProp('styleMotif') || 'editorial');

const fxClass = computed(() => `fx-${visualFx.value}`);
const textureClass = computed(() => `texture-${textureFx.value}`);
const energyClass = computed(() => `energy-${energyFx.value}`);
const motifClass = computed(() => `motif-${styleMotif.value}`);

function toBrandSectionUrl(section: string): string {
  return `${brandBaseUrl.value}/#${section.toLowerCase()}`;
}

const shellStyle = computed(() => {
  const radius = 12 + (visualSeed.value % 9);
  const panelBlur = 2 + (visualSeed.value % 6);

  return {
    '--accent': blueprint.value?.theme.accent ?? '#0ea5e9',
    '--radius': `${radius}px`,
    '--panel-blur': `${panelBlur}px`,
    '--module-gap': `${0.7 + (visualSeed.value % 4) * 0.16}rem`
  };
});

const navItems = computed(() => (blueprint.value?.shortcuts ?? []).slice(0, 6));

const orderedModules = computed(() => {
  const modules = blueprint.value?.modules ?? [];
  return modules.map((module, index) => ({ module, index }));
});

const leadModuleEntry = computed(() => {
  const hero = orderedModules.value.find((entry) => entry.module.type === 'Hero');
  if (hero) {
    return hero;
  }

  return orderedModules.value[0] ?? null;
});

const remainingModuleEntries = computed(() => {
  if (!leadModuleEntry.value) {
    return orderedModules.value;
  }

  return orderedModules.value.filter((entry) => entry.module.id !== leadModuleEntry.value?.module.id);
});

function isSupportType(type: string): boolean {
  return type === 'QuickActions' || type === 'FAQ';
}

const supportModules = computed(() =>
  remainingModuleEntries.value.filter((entry) => isSupportType(entry.module.type))
);

const storyModules = computed(() =>
  remainingModuleEntries.value.filter((entry) => !isSupportType(entry.module.type))
);

const shellTitle = computed(() => {
  const labelByMode = {
    orbital: ['Orbiting Story Field', 'Immersive Signal Field', 'Curated Orbit Atelier'],
    editorial: ['Editorial Chronicle', 'Immersive Story Stream', 'Curated Atelier Feed'],
    kinetic: ['Kinetic Narrative Surface', 'Momentum Story Stream', 'High-Tempo Atelier Feed'],
    glass: ['Prism Narrative Layer', 'Glass Story Stream', 'Luminous Atelier Feed'],
    neo: ['Neo Editorial Grid', 'Neo Story Stream', 'Neo Atelier Feed']
  } as Record<string, string[]>;

  const titles = labelByMode[shellProfile.value] ?? labelByMode.editorial;
  return titles[experienceMode.value] ?? 'Headless WordPress Experience';
});

const wordpressStatus = computed(() => {
  if (!blueprint.value) {
    return '';
  }

  return 'Post-driven interface generated from alexanderjgill.com REST content.';
});

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.push('/onboarding?force=1&reset=1');
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
      <p>Composing a fresh editorial experience from live post data...</p>
    </section>
  </main>

  <main
    v-else-if="blueprint"
    class="shell"
    :class="[
      modeClass,
      toneClass,
      experienceClass,
      shellProfileClass,
      motionClass,
      fxClass,
      textureClass,
      energyClass,
      motifClass
    ]"
    :style="shellStyle"
  >
    <div class="backdrop-layer" aria-hidden="true">
      <span class="scan-grid"></span>
      <span class="noise-overlay"></span>
      <span class="shape shape-a"></span>
      <span class="shape shape-b"></span>
      <span class="shape shape-c"></span>
      <span class="shape shape-d"></span>
      <span class="shape shape-e"></span>
    </div>

    <header class="shell-header">
      <div>
        <a class="brand-lockup" :href="brandBaseUrl" target="_blank" rel="noopener noreferrer">
          <img class="brand-primary-icon" :src="brandIconUrl" alt="" loading="lazy" />
          <span class="brand-lockup-text">
            <strong>{{ brandName }}</strong>
            <em>{{ brandTagline }}</em>
          </span>
          <img class="brand-secondary-icon" :src="brandSecondaryIconUrl" alt="" loading="lazy" />
        </a>
        <p class="eyebrow">
          {{ experienceLabel }} · {{ shellProfile }} profile · Signature {{ designSignature }} · {{ BUILD_TAG }}
        </p>
        <h1>{{ shellTitle }}</h1>
        <p class="source-note">{{ wordpressStatus }}</p>
        <p class="persona-note">Designed around: {{ focusTopics.join(' · ') }}</p>
        <p v-if="initializationError" class="fallback-note">{{ initializationError }}</p>
        <p v-if="wordpressError" class="fallback-note">{{ wordpressError }}</p>
      </div>
      <div class="header-actions">
        <button type="button" class="secondary-btn" @click="openChatRefinement">Refine With Chat</button>
        <button type="button" class="reset-btn" @click="resetPersonalization">Reset Personalization</button>
      </div>
    </header>

    <section class="brand-rail" aria-label="Brand sections">
      <a
        v-for="section in brandSections"
        :key="section"
        class="brand-section-chip"
        :href="toBrandSectionUrl(section)"
        target="_blank"
        rel="noopener noreferrer"
      >
        {{ section }}
      </a>
    </section>

    <section class="persona-rail" aria-label="Personalization topics">
      <span v-for="topic in focusTopics" :key="topic" class="persona-chip">{{ topic }}</span>
    </section>

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

    <section v-if="experienceMode === 0" class="experience-scene scene-chronicle">
      <article
        v-if="leadModuleEntry"
        class="lead-slot"
        :style="{ '--stagger': '0ms' }"
      >
        <ModuleRenderer :module="leadModuleEntry.module" :shortcuts="blueprint.shortcuts" />
      </article>

      <div class="chronicle-body">
        <div class="story-column">
          <article
            v-for="(entry, idx) in storyModules"
            :key="entry.module.id"
            class="module-slot"
            :class="[`slot-type-${entry.module.type}`, idx % 2 === 0 ? 'slot-tilt-left' : 'slot-tilt-right']"
            :style="{ '--stagger': `${120 + idx * 80}ms` }"
          >
            <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
          </article>
        </div>

        <aside class="rail-column">
          <article
            v-for="(entry, idx) in supportModules"
            :key="entry.module.id"
            class="module-slot rail-slot"
            :class="`slot-type-${entry.module.type}`"
            :style="{ '--stagger': `${140 + idx * 90}ms` }"
          >
            <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
          </article>
        </aside>
      </div>
    </section>

    <section v-else-if="experienceMode === 1" class="experience-scene scene-cinematic">
      <article
        v-if="leadModuleEntry"
        class="module-slot cinematic-lead"
        :style="{ '--stagger': '0ms' }"
      >
        <ModuleRenderer :module="leadModuleEntry.module" :shortcuts="blueprint.shortcuts" />
      </article>

      <article
        v-for="(entry, idx) in remainingModuleEntries"
        :key="entry.module.id"
        class="module-slot cinematic-panel"
        :class="[
          `slot-type-${entry.module.type}`,
          idx % 2 === 0 ? 'panel-left' : 'panel-right',
          idx % 3 === 0 ? 'panel-wide' : ''
        ]"
        :style="{ '--stagger': `${110 + idx * 85}ms` }"
      >
        <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
      </article>
    </section>

    <section v-else class="experience-scene scene-atelier">
      <div class="atelier-grid">
        <article
          v-if="leadModuleEntry"
          class="module-slot atelier-lead"
          :style="{ '--stagger': '0ms' }"
        >
          <ModuleRenderer :module="leadModuleEntry.module" :shortcuts="blueprint.shortcuts" />
        </article>

        <article
          v-for="(entry, idx) in storyModules"
          :key="entry.module.id"
          class="module-slot atelier-story"
          :class="[`slot-type-${entry.module.type}`, `story-${(idx % 3) + 1}`]"
          :style="{ '--stagger': `${100 + idx * 75}ms` }"
        >
          <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
        </article>

        <aside class="atelier-rail">
          <article
            v-for="(entry, idx) in supportModules"
            :key="entry.module.id"
            class="module-slot rail-slot"
            :class="`slot-type-${entry.module.type}`"
            :style="{ '--stagger': `${160 + idx * 95}ms` }"
          >
            <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
          </article>
        </aside>
      </div>
    </section>

    <AssistantDock
      :visitor-id="visitorId"
      :variant-nonce="assistantVariantNonce"
      :design-signature="designSignature"
    />
  </main>
</template>

<style scoped>
.booting-shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #05080f;
  color: #dbeafe;
  padding: 1rem;
}

.boot-card {
  border: 1px solid #1e2a45;
  border-radius: 14px;
  background: #0d1524;
  padding: 1rem 1.25rem;
}

.shell {
  position: relative;
  overflow: hidden;
  min-height: 100vh;
  padding: 1rem 1rem 16rem;
  font-family: var(--shell-font, 'IBM Plex Sans', 'Segoe UI', sans-serif);
  background: var(--bg);
}

.backdrop-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.scan-grid {
  position: absolute;
  inset: 0;
  opacity: 0.28;
  background:
    linear-gradient(transparent 96%, color-mix(in srgb, var(--accent) 22%, transparent) 100%),
    linear-gradient(90deg, transparent 96%, color-mix(in srgb, var(--accent) 18%, transparent) 100%);
  background-size: 100% 34px, 34px 100%;
  mask-image: radial-gradient(circle at 50% 35%, black, transparent 85%);
}

.noise-overlay {
  position: absolute;
  inset: 0;
  opacity: 0.1;
  background-image:
    radial-gradient(circle at 16% 22%, rgba(255, 255, 255, 0.18) 0, transparent 1.5px),
    radial-gradient(circle at 81% 39%, rgba(255, 255, 255, 0.16) 0, transparent 1.5px),
    radial-gradient(circle at 40% 74%, rgba(255, 255, 255, 0.12) 0, transparent 1.5px);
  background-size: 170px 170px, 150px 150px, 130px 130px;
}

.shape {
  position: absolute;
  border-radius: 999px;
  filter: blur(0.5px);
  opacity: 0.35;
  animation: drift 18s ease-in-out infinite alternate;
}

.shape-a {
  width: 460px;
  height: 460px;
  top: -170px;
  left: -90px;
  background: color-mix(in srgb, var(--accent) 28%, transparent);
}

.shape-b {
  width: 320px;
  height: 320px;
  top: 8%;
  right: -90px;
  background: color-mix(in srgb, var(--accent) 19%, transparent);
}

.shape-c {
  width: 300px;
  height: 300px;
  bottom: -120px;
  left: 30%;
  background: color-mix(in srgb, var(--accent) 14%, transparent);
}

.shape-d {
  width: 220px;
  height: 220px;
  bottom: 18%;
  right: 18%;
  background: color-mix(in srgb, var(--accent) 10%, transparent);
}

.shape-e {
  width: 520px;
  height: 220px;
  top: 32%;
  left: 18%;
  border-radius: 30%;
  background: linear-gradient(
    95deg,
    color-mix(in srgb, var(--accent) 24%, transparent),
    color-mix(in srgb, var(--accent) 8%, transparent)
  );
  filter: blur(22px);
  opacity: 0.35;
  animation-duration: 22s;
}

.mode-light {
  --bg: linear-gradient(155deg, #f4f7ff, #e9f3ff 42%, #f4f7ff 100%);
  --surface: #ffffff;
  --surface-muted: #e9f1ff;
  --text-primary: #111827;
  --text-secondary: #4b5563;
  --border: #d1d5db;
}

.mode-dark {
  --bg:
    radial-gradient(circle at 84% -12%, rgba(22, 199, 207, 0.25), transparent 38%),
    linear-gradient(160deg, #050b18, #081225 44%, #0c1a32 100%);
  --surface: #111a2f;
  --surface-muted: #16233f;
  --text-primary: #e5e7eb;
  --text-secondary: #94a3b8;
  --border: #2a3a5f;
}

.tone-grotesk {
  --shell-font: 'Avenir Next', 'Trebuchet MS', sans-serif;
}

.tone-literary {
  --shell-font: 'Baskerville', 'Palatino Linotype', serif;
}

.tone-display {
  --shell-font: 'Franklin Gothic Medium', 'Arial Narrow', sans-serif;
}

.tone-mono {
  --shell-font: 'IBM Plex Mono', 'Fira Code', monospace;
}

.profile-orbital .shape-a {
  transform: rotate(18deg) scale(1.08);
}

.profile-orbital .shape-c {
  opacity: 0.5;
}

.profile-editorial .shell-header {
  border-left-width: 4px;
  border-left-color: color-mix(in srgb, var(--accent) 68%, var(--border));
}

.profile-editorial .shape-b {
  border-radius: 32% 68% 42% 58%;
}

.profile-kinetic .shape-b,
.profile-kinetic .shape-d {
  opacity: 0.58;
}

.profile-kinetic .panel-left {
  margin-right: 2%;
}

.profile-kinetic .panel-right {
  margin-left: 2%;
}

.profile-glass .shell-header,
.profile-glass .nav-item {
  background: color-mix(in srgb, var(--surface) 68%, transparent);
  backdrop-filter: blur(calc(var(--panel-blur) + 2px));
}

.profile-glass .shape-a,
.profile-glass .shape-d {
  opacity: 0.56;
}

.profile-neo .shell-header {
  border-width: 2px;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 12%, transparent);
}

.profile-neo .shape-b {
  border-radius: 16px;
}

.profile-neo .shape-c {
  border-radius: 22px;
}

.motion-calm .module-slot,
.motion-calm .lead-slot {
  animation-duration: 760ms;
}

.motion-balanced .module-slot,
.motion-balanced .lead-slot {
  animation-duration: 620ms;
}

.motion-kinetic .module-slot,
.motion-kinetic .lead-slot {
  animation-duration: 460ms;
}

.shell-header {
  position: relative;
  z-index: 2;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-start;
  padding: 1rem;
  border-radius: calc(var(--radius) + 2px);
  background:
    linear-gradient(
      140deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface)),
      color-mix(in srgb, var(--surface) 86%, transparent)
    );
  border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
  backdrop-filter: blur(var(--panel-blur));
}

.brand-lockup {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: inherit;
  text-decoration: none;
  margin-bottom: 0.45rem;
}

.brand-primary-icon,
.brand-secondary-icon {
  width: 26px;
  height: 26px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border));
  object-fit: cover;
}

.brand-lockup-text {
  display: grid;
  line-height: 1.02;
}

.brand-lockup-text strong {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.brand-lockup-text em {
  font-style: normal;
  font-size: 0.72rem;
  color: color-mix(in srgb, var(--accent) 76%, var(--text-secondary));
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
  font-size: clamp(1.35rem, 4.3vw, 2.25rem);
}

.source-note {
  margin: 0.35rem 0 0;
  color: var(--text-secondary);
  font-size: 0.9rem;
}

.persona-note {
  margin: 0.35rem 0 0;
  color: color-mix(in srgb, var(--accent) 76%, var(--text-secondary));
  font-size: 0.84rem;
  letter-spacing: 0.03em;
}

.fallback-note {
  margin: 0.45rem 0 0;
  color: color-mix(in srgb, var(--accent) 70%, var(--text-secondary));
  font-size: 0.84rem;
}

.header-actions {
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.secondary-btn,
.reset-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 54%, var(--border));
  border-radius: 999px;
  background:
    linear-gradient(
      140deg,
      color-mix(in srgb, var(--accent) 18%, var(--surface)),
      color-mix(in srgb, var(--accent) 4%, var(--surface))
    );
  color: var(--text-primary);
  padding: 0.55rem 0.95rem;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 12%, transparent);
}

.brand-rail {
  position: relative;
  z-index: 2;
  margin-top: 0.85rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.42rem;
}

.brand-section-chip {
  text-decoration: none;
  color: var(--text-primary);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 44%, var(--border));
  background: color-mix(in srgb, var(--accent) 11%, var(--surface));
  padding: 0.32rem 0.65rem;
}

.persona-rail {
  position: relative;
  z-index: 2;
  margin-top: 0.45rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.36rem;
}

.persona-chip {
  font-size: 0.7rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 38%, var(--border));
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
  padding: 0.25rem 0.58rem;
  letter-spacing: 0.06em;
}

.shell-nav {
  position: relative;
  z-index: 2;
  margin-top: 0.9rem;
  display: flex;
  gap: 0.55rem;
  flex-wrap: wrap;
}

.nav-item {
  border: 1px solid var(--border);
  border-radius: 999px;
  background:
    linear-gradient(
      140deg,
      color-mix(in srgb, var(--accent) 11%, var(--surface)),
      color-mix(in srgb, var(--surface) 88%, transparent)
    );
  color: var(--text-primary);
  padding: 0.48rem 0.82rem;
  text-decoration: none;
  font-size: 0.86rem;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 10%, transparent);
}

.experience-scene {
  position: relative;
  z-index: 1;
  margin-top: 1rem;
}

.module-slot,
.lead-slot {
  animation: rise-in 620ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
  animation-delay: var(--stagger, 0ms);
}

.lead-slot :deep(.hero-module) {
  border-radius: calc(var(--radius) + 5px);
}

.scene-chronicle .lead-slot {
  margin-bottom: var(--module-gap);
}

.chronicle-body {
  display: grid;
  gap: var(--module-gap);
}

.story-column,
.rail-column {
  display: grid;
  gap: var(--module-gap);
}

.slot-tilt-left {
  transform-origin: left center;
}

.slot-tilt-right {
  transform-origin: right center;
}

.scene-cinematic {
  display: grid;
  gap: var(--module-gap);
}

.cinematic-lead :deep(.hero-module) {
  border-width: 2px;
}

.cinematic-panel {
  border-radius: calc(var(--radius) + 3px);
}

.panel-left {
  margin-right: 6%;
}

.panel-right {
  margin-left: 6%;
}

.panel-wide {
  margin-left: 0;
  margin-right: 0;
}

.scene-atelier .atelier-grid {
  display: grid;
  gap: var(--module-gap);
}

.atelier-rail {
  display: grid;
  gap: var(--module-gap);
}

.experience-2 .shell-header {
  border-style: dashed;
}

.experience-1 .shape-b,
.experience-1 .shape-d {
  opacity: 0.46;
}

.experience-0 .shape-a,
.experience-0 .shape-c {
  opacity: 0.44;
}

.fx-neon .shape-a,
.fx-neon .shape-b {
  opacity: 0.56;
}

.fx-signal .shape-e {
  opacity: 0.5;
  filter: blur(16px);
}

.fx-prism .shape-a,
.fx-prism .shape-c {
  border-radius: 34% 66% 58% 42%;
}

.fx-matrix .scan-grid {
  opacity: 0.4;
}

.fx-zen .shape-a,
.fx-zen .shape-b,
.fx-zen .shape-c,
.fx-zen .shape-d,
.fx-zen .shape-e {
  opacity: 0.24;
}

.texture-grid .scan-grid {
  opacity: 0.42;
}

.texture-scan .noise-overlay {
  opacity: 0.04;
}

.texture-grain .noise-overlay {
  opacity: 0.18;
}

.texture-soft .scan-grid {
  opacity: 0.14;
}

.energy-high .module-slot,
.energy-high .lead-slot {
  animation-duration: 390ms;
}

.energy-high .shape {
  animation-duration: 12s;
}

.energy-low .module-slot,
.energy-low .lead-slot {
  animation-duration: 760ms;
}

.energy-low .shape {
  animation-duration: 24s;
}

.motif-cinematic .shell-header {
  border-width: 2px;
}

.motif-holographic .brand-section-chip,
.motif-holographic .persona-chip {
  background:
    linear-gradient(
      130deg,
      color-mix(in srgb, var(--accent) 20%, var(--surface)),
      color-mix(in srgb, var(--accent) 5%, var(--surface))
    );
}

.motif-signal-driven .shell-nav .nav-item {
  font-family: 'IBM Plex Mono', 'Fira Code', monospace;
  letter-spacing: 0.04em;
}

@keyframes drift {
  from {
    transform: translate3d(0, 0, 0) scale(1);
  }
  to {
    transform: translate3d(0, -12px, 0) scale(1.04);
  }
}

@keyframes rise-in {
  from {
    opacity: 0;
    transform: translateY(16px) scale(0.985);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (min-width: 900px) {
  .shell {
    padding: 1.3rem 2rem 6.5rem;
  }

  .shell-nav.nav-side {
    width: min(340px, 100%);
    display: grid;
    grid-template-columns: 1fr;
    justify-items: stretch;
    align-items: start;
    gap: 0.48rem;
  }

  .shell-nav.nav-side .nav-item {
    width: 100%;
  }

  .chronicle-body {
    grid-template-columns: minmax(0, 1fr) 320px;
    align-items: start;
  }

  .rail-column {
    position: sticky;
    top: 110px;
  }

  .scene-cinematic {
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }

  .scene-cinematic .cinematic-lead {
    grid-column: 1 / -1;
  }

  .scene-cinematic .cinematic-panel {
    grid-column: span 7;
  }

  .scene-cinematic .panel-right {
    grid-column: 6 / -1;
  }

  .scene-cinematic .panel-left {
    grid-column: 1 / span 7;
  }

  .scene-cinematic .panel-wide {
    grid-column: 1 / -1;
  }

  .scene-atelier .atelier-grid {
    grid-template-columns: repeat(12, minmax(0, 1fr));
  }

  .atelier-lead {
    grid-column: 1 / span 8;
  }

  .atelier-story.story-1 {
    grid-column: 9 / -1;
  }

  .atelier-story.story-2 {
    grid-column: 1 / span 5;
  }

  .atelier-story.story-3 {
    grid-column: 6 / span 7;
  }

  .atelier-rail {
    grid-column: 1 / -1;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
