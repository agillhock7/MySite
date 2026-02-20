<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import ModuleRenderer from '@/components/ModuleRenderer.vue';
import AssistantDock from '@/components/AssistantDock.vue';
import { fetchWordpressContentBundle } from '@/api/wp';
import { getContentByKey, setRuntimeContentOverrides } from '@/content/library';
import { BUILD_TAG } from '@/meta/build';
import { getDesignIteration, getOrCreateVisitorId } from '@/personalization/visitor';
import { usePersonalizationStore } from '@/stores/personalization';

interface VisualCard {
  key: string;
  title: string;
  imageUrl: string;
  href: string;
}

const router = useRouter();
const personalization = usePersonalizationStore();

const initializing = ref(true);
const initializationError = ref('');
const wordpressError = ref('');
const pointerX = ref(52);
const pointerY = ref(36);

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

function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
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

function seededUnit(seed: number, salt: string): number {
  return hashText(`${seed}:${salt}`) / 4294967295;
}

function seededChoice<T>(seed: number, salt: string, options: T[]): T {
  if (options.length === 0) {
    throw new Error('seededChoice requires at least one option');
  }

  const index = Math.floor(seededUnit(seed, salt) * options.length) % options.length;
  return options[index];
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

  if (!wpBundle) {
    wordpressError.value = 'WordPress fetch unavailable. Rendering from cached content when possible.';
  } else if (wpBundle.wordpress && !wpBundle.wordpress.available) {
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

const baseSeed = computed(() => {
  if (!blueprint.value) {
    return 0;
  }

  const moduleIds = blueprint.value.modules.map((module) => module.id).join('|');
  return hashText(`${blueprint.value.theme.accent}|${blueprint.value.layout.nav}|${moduleIds}|${blueprint.value.createdAt}`);
});

const focusTopics = computed(() => {
  const topics = firstStringArrayModuleProp('focusTopics');
  return topics.length > 0 ? topics.slice(0, 7) : ['Identity', 'Interests', 'Intent'];
});

const designSignature = computed(() => {
  const fromBlueprint = firstStringModuleProp('signature');
  if (fromBlueprint) {
    return fromBlueprint.slice(0, 10).toUpperCase();
  }

  return baseSeed.value.toString(36).toUpperCase().padStart(6, '0').slice(0, 8);
});

const visualSeed = computed(() => {
  if (!blueprint.value) {
    return 0;
  }

  const shellHint = firstStringModuleProp('shellProfile');
  const topicKey = focusTopics.value.join('|').toLowerCase();
  return hashText(`${baseSeed.value}|${designSignature.value}|${shellHint}|${topicKey}`);
});

const modeClass = computed(() => (blueprint.value?.theme.mode === 'dark' ? 'mode-dark' : 'mode-light'));

const shellProfile = computed(() => {
  const fromBlueprint = firstStringModuleProp('shellProfile').toLowerCase();
  const allowed = ['orbital', 'editorial', 'kinetic', 'glass', 'neo', 'atlas', 'spectral'];

  if (allowed.includes(fromBlueprint)) {
    return fromBlueprint;
  }

  return allowed[visualSeed.value % allowed.length];
});

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

  return allowed[(visualSeed.value >> 2) % allowed.length];
});

const experienceMode = computed(() => visualSeed.value % 4);

const toneClass = computed(() => `tone-${typographyProfile.value}`);
const motionClass = computed(() => `motion-${motionProfile.value}`);
const shellProfileClass = computed(() => `profile-${shellProfile.value}`);
const experienceClass = computed(() => `experience-${experienceMode.value}`);
const fxClass = computed(() => `fx-${firstStringModuleProp('visualFx') || 'neon'}`);
const textureClass = computed(() => `texture-${firstStringModuleProp('textureFx') || 'glass'}`);
const energyClass = computed(() => `energy-${firstStringModuleProp('energyFx') || 'balanced'}`);
const motifClass = computed(() => `motif-${firstStringModuleProp('styleMotif') || 'editorial'}`);
const layoutClass = computed(() => `layout-nav-${blueprint.value?.layout.nav ?? 'top'}`);

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
  return sections.length > 0 ? sections.slice(0, 8) : ['Work', 'Lab', 'Read', 'Bio', 'Markets'];
});

const shellTitle = computed(() => {
  const labelsByProfile: Record<string, string[]> = {
    orbital: ['Orbiting Story Field', 'Immersive Orbit Chronicle', 'Future Signal Stories', 'Curated Orbit Atelier'],
    editorial: ['Editorial Story Engine', 'Feature Narrative Stream', 'Curated Editorial Atlas', 'Signature Story Archive'],
    kinetic: ['High-Velocity Storyline', 'Kinetic Story Surface', 'Motion-Driven Editorial', 'Pulse Narrative Engine'],
    glass: ['Prism Story Layer', 'Luminous Story Atelier', 'Translucent Narrative Grid', 'Refraction Editorial Stream'],
    neo: ['Neo Chronicle System', 'Modern Signal Editorial', 'Neo Atlas Feed', 'Future Blog Interface'],
    atlas: ['Atlas Story Cartography', 'Map of Living Posts', 'Topographic Narrative Field', 'Explorer Story Grid'],
    spectral: ['Spectral Story Spectrum', 'Chromatic Narrative Field', 'Lightwave Editorial Surface', 'Prismatic Post Engine']
  };

  const labels = labelsByProfile[shellProfile.value] ?? labelsByProfile.editorial;
  return labels[experienceMode.value % labels.length];
});

const experienceLabel = computed(() => {
  const labels = ['Chronicle', 'Cinema', 'Atelier', 'Pulse'];
  return `${shellProfile.value.toUpperCase()} ${labels[experienceMode.value]}`;
});

const wordpressStatus = computed(() => {
  if (!blueprint.value) {
    return '';
  }

  return 'Live post stream from alexanderjgill.com WordPress REST content.';
});

const shellStyle = computed(() => {
  const radius = 12 + Math.floor(seededUnit(visualSeed.value, 'radius') * 12);
  const panelBlur = 4 + Math.floor(seededUnit(visualSeed.value, 'blur') * 8);

  return {
    '--accent': blueprint.value?.theme.accent ?? '#0ea5e9',
    '--radius': `${radius}px`,
    '--panel-blur': `${panelBlur}px`,
    '--module-gap': `${0.8 + seededUnit(visualSeed.value, 'gap') * 0.75}rem`,
    '--pointer-x': `${pointerX.value}%`,
    '--pointer-y': `${pointerY.value}%`
  };
});

const navItems = computed(() => (blueprint.value?.shortcuts ?? []).slice(0, 8));

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

const supportModules = computed(() => remainingModuleEntries.value.filter((entry) => isSupportType(entry.module.type)));
const featureModules = computed(() => remainingModuleEntries.value.filter((entry) => !isSupportType(entry.module.type)));

const heroVisualCards = computed<VisualCard[]>(() => {
  const rawFeatured = asRecord(getContentByKey('featuredGrid'));
  const rawItems = Array.isArray(rawFeatured?.items) ? (rawFeatured?.items as unknown[]) : [];

  const cards = rawItems
    .map((entry, index) => {
      const item = asRecord(entry);
      if (!item) {
        return null;
      }

      const title = typeof item.title === 'string' ? item.title.trim() : `Post ${index + 1}`;
      const imageUrl = typeof item.imageUrl === 'string' ? item.imageUrl.trim() : '';
      const hrefRaw = typeof item.href === 'string' ? item.href.trim() : '';
      const canonical = typeof item.canonicalUrl === 'string' ? item.canonicalUrl.trim() : '';
      const href = hrefRaw || canonical || brandBaseUrl.value;

      return {
        key: `${item.id ?? title}-${index}`,
        title,
        imageUrl,
        href
      };
    })
    .filter((item): item is VisualCard => item !== null)
    .slice(0, 5);

  if (cards.length > 0) {
    return cards;
  }

  return focusTopics.value.slice(0, 4).map((topic, index) => ({
    key: `${topic}-${index}`,
    title: topic,
    imageUrl: '',
    href: brandBaseUrl.value
  }));
});

const ambientNodes = computed(() => {
  const nodes = [] as Array<{
    key: string;
    size: string;
    left: string;
    top: string;
    opacity: string;
    duration: string;
    delay: string;
    blendClass: string;
  }>;

  const blendModes = ['blend-screen', 'blend-overlay', 'blend-plus'];

  for (let index = 0; index < 11; index += 1) {
    const size = 130 + seededUnit(visualSeed.value, `node-size-${index}`) * 360;
    const left = seededUnit(visualSeed.value, `node-left-${index}`) * 100;
    const top = seededUnit(visualSeed.value, `node-top-${index}`) * 100;
    const opacity = 0.16 + seededUnit(visualSeed.value, `node-opacity-${index}`) * 0.44;
    const duration = 11 + seededUnit(visualSeed.value, `node-duration-${index}`) * 18;
    const delay = seededUnit(visualSeed.value, `node-delay-${index}`) * -8;

    nodes.push({
      key: `ambient-${index}`,
      size: `${size.toFixed(0)}px`,
      left: `${left.toFixed(2)}%`,
      top: `${top.toFixed(2)}%`,
      opacity: opacity.toFixed(2),
      duration: `${duration.toFixed(1)}s`,
      delay: `${delay.toFixed(1)}s`,
      blendClass: seededChoice(visualSeed.value, `node-mix-${index}`, blendModes)
    });
  }

  return nodes;
});

function toBrandSectionUrl(section: string): string {
  return `${brandBaseUrl.value}/#${section.toLowerCase()}`;
}

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

function handlePointerMove(event: PointerEvent): void {
  const target = event.currentTarget;
  if (!(target instanceof HTMLElement)) {
    return;
  }

  const rect = target.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) {
    return;
  }

  pointerX.value = ((event.clientX - rect.left) / rect.width) * 100;
  pointerY.value = ((event.clientY - rect.top) / rect.height) * 100;
}

function handlePointerLeave(): void {
  pointerX.value = 52;
  pointerY.value = 36;
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
      <p>Composing a fresh personalized interface from live post signals...</p>
    </section>
  </main>

  <main
    v-else-if="blueprint"
    class="shell"
    :class="[
      modeClass,
      toneClass,
      motionClass,
      shellProfileClass,
      experienceClass,
      fxClass,
      textureClass,
      energyClass,
      motifClass,
      layoutClass
    ]"
    :style="shellStyle"
    @pointermove="handlePointerMove"
    @pointerleave="handlePointerLeave"
  >
    <div class="ambient-layer" aria-hidden="true">
      <span class="ambient-grid"></span>
      <span class="ambient-noise"></span>
      <span
        v-for="node in ambientNodes"
        :key="node.key"
        class="ambient-node"
        :class="node.blendClass"
        :style="{
          width: node.size,
          height: node.size,
          left: node.left,
          top: node.top,
          opacity: node.opacity,
          animationDuration: node.duration,
          animationDelay: node.delay
        }"
      ></span>
      <span class="ambient-sweep"></span>
    </div>

    <header class="command-header">
      <a class="brand-lockup" :href="brandBaseUrl" target="_blank" rel="noopener noreferrer">
        <img class="brand-primary-icon" :src="brandIconUrl" alt="" loading="lazy" />
        <span class="brand-lockup-text">
          <strong>{{ brandName }}</strong>
          <em>{{ brandTagline }}</em>
        </span>
        <img class="brand-secondary-icon" :src="brandSecondaryIconUrl" alt="" loading="lazy" />
      </a>

      <div class="header-meta">
        <p class="eyebrow">{{ experienceLabel }} · Signature {{ designSignature }} · {{ BUILD_TAG }}</p>
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

    <section class="hero-stage">
      <article class="identity-card">
        <p class="identity-title">Experience DNA</p>
        <div class="topic-cluster" aria-label="Personalization topics">
          <span v-for="topic in focusTopics" :key="topic" class="topic-chip">{{ topic }}</span>
        </div>

        <div class="brand-rail" aria-label="Brand sections">
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
        </div>
      </article>

      <article class="artboard-card">
        <p class="identity-title">Live Post Canvas</p>
        <div class="poster-grid" :class="`count-${heroVisualCards.length}`">
          <a
            v-for="(card, idx) in heroVisualCards"
            :key="card.key"
            class="poster-card"
            :class="`poster-${(idx % 5) + 1}`"
            :href="card.href"
            :target="isUrlAction(card.href) ? '_blank' : '_self'"
            :rel="isUrlAction(card.href) ? 'noopener noreferrer' : undefined"
          >
            <img v-if="card.imageUrl" :src="card.imageUrl" alt="" loading="lazy" />
            <div v-else class="poster-fallback" aria-hidden="true">
              <span>{{ card.title.slice(0, 28) }}</span>
            </div>
            <p>{{ card.title }}</p>
          </a>
        </div>
      </article>
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

    <section class="module-stage">
      <article v-if="leadModuleEntry" class="lead-slot" :style="{ '--stagger': '0ms' }">
        <ModuleRenderer :module="leadModuleEntry.module" :shortcuts="blueprint.shortcuts" />
      </article>

      <div class="module-columns">
        <div class="feature-stack">
          <article
            v-for="(entry, idx) in featureModules"
            :key="entry.module.id"
            class="module-slot"
            :class="[`slot-type-${entry.module.type}`, idx % 2 === 0 ? 'slot-left' : 'slot-right']"
            :style="{ '--stagger': `${90 + idx * 80}ms` }"
          >
            <ModuleRenderer :module="entry.module" :shortcuts="blueprint.shortcuts" />
          </article>
        </div>

        <aside v-if="supportModules.length > 0" class="support-stack">
          <article
            v-for="(entry, idx) in supportModules"
            :key="entry.module.id"
            class="module-slot support-slot"
            :class="`slot-type-${entry.module.type}`"
            :style="{ '--stagger': `${120 + idx * 90}ms` }"
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
  background: #03060f;
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
  padding: 1rem 1rem 15rem;
  font-family: var(--shell-font, 'Sora', 'Avenir Next', 'Segoe UI', sans-serif);
  color: var(--text-primary);
  background: var(--bg);
}

.ambient-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.ambient-grid {
  position: absolute;
  inset: 0;
  opacity: 0.26;
  background:
    linear-gradient(transparent 96%, color-mix(in srgb, var(--accent) 26%, transparent) 100%),
    linear-gradient(90deg, transparent 96%, color-mix(in srgb, var(--accent) 20%, transparent) 100%);
  background-size: 100% 32px, 32px 100%;
  mask-image: radial-gradient(circle at var(--pointer-x) var(--pointer-y), black, transparent 85%);
}

.ambient-noise {
  position: absolute;
  inset: 0;
  opacity: 0.1;
  background-image:
    radial-gradient(circle at 12% 22%, rgba(255, 255, 255, 0.18) 0, transparent 1.5px),
    radial-gradient(circle at 86% 37%, rgba(255, 255, 255, 0.16) 0, transparent 1.6px),
    radial-gradient(circle at 44% 74%, rgba(255, 255, 255, 0.12) 0, transparent 1.5px);
  background-size: 160px 160px, 150px 150px, 130px 130px;
}

.ambient-node {
  position: absolute;
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 42%, transparent);
  filter: blur(16px);
  transform: translate(-50%, -50%);
  animation: breathe 15s ease-in-out infinite alternate;
}

.blend-screen {
  mix-blend-mode: screen;
}

.blend-overlay {
  mix-blend-mode: overlay;
}

.blend-plus {
  mix-blend-mode: plus-lighter;
}

.ambient-sweep {
  position: absolute;
  inset: -20% -20% auto -20%;
  height: 56%;
  background: radial-gradient(circle at var(--pointer-x) var(--pointer-y), color-mix(in srgb, var(--accent) 24%, transparent), transparent 58%);
  opacity: 0.75;
}

.mode-light {
  --bg:
    radial-gradient(circle at var(--pointer-x) var(--pointer-y), color-mix(in srgb, var(--accent) 12%, transparent), transparent 46%),
    linear-gradient(150deg, #f6f9ff, #e9f2ff 42%, #f5f8ff);
  --surface: rgba(255, 255, 255, 0.9);
  --surface-muted: #e8f1ff;
  --text-primary: #0f172a;
  --text-secondary: #475569;
  --border: #cbd5e1;
}

.mode-dark {
  --bg:
    radial-gradient(circle at var(--pointer-x) var(--pointer-y), color-mix(in srgb, var(--accent) 19%, transparent), transparent 44%),
    linear-gradient(165deg, #040a17, #08132a 45%, #0c1d3a 100%);
  --surface: rgba(12, 22, 43, 0.84);
  --surface-muted: rgba(25, 39, 70, 0.9);
  --text-primary: #e5edf7;
  --text-secondary: #93a7c9;
  --border: #2a3c63;
}

.tone-grotesk {
  --shell-font: 'Sora', 'Avenir Next', sans-serif;
}

.tone-literary {
  --shell-font: 'Iowan Old Style', 'Baskerville', serif;
}

.tone-display {
  --shell-font: 'Bebas Neue', 'Franklin Gothic Medium', sans-serif;
}

.tone-mono {
  --shell-font: 'IBM Plex Mono', 'Fira Code', monospace;
}

.command-header,
.hero-stage,
.shell-nav,
.module-stage {
  position: relative;
  z-index: 2;
}

.command-header {
  display: grid;
  gap: 0.85rem;
  border-radius: calc(var(--radius) + 6px);
  border: 1px solid color-mix(in srgb, var(--accent) 28%, var(--border));
  background:
    linear-gradient(
      138deg,
      color-mix(in srgb, var(--accent) 10%, var(--surface)),
      color-mix(in srgb, var(--surface) 88%, transparent)
    );
  backdrop-filter: blur(var(--panel-blur));
  padding: 1rem;
}

.brand-lockup {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  width: fit-content;
  text-decoration: none;
  color: inherit;
}

.brand-primary-icon,
.brand-secondary-icon {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 36%, var(--border));
  object-fit: cover;
}

.brand-lockup-text {
  display: grid;
  line-height: 1.02;
}

.brand-lockup-text strong {
  font-size: 0.82rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.brand-lockup-text em {
  font-style: normal;
  font-size: 0.72rem;
  color: color-mix(in srgb, var(--accent) 82%, var(--text-secondary));
}

.header-meta {
  min-width: 0;
}

.eyebrow {
  margin: 0;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.09em;
  font-size: 0.76rem;
}

h1 {
  margin: 0.2rem 0 0;
  font-size: clamp(1.45rem, 4.8vw, 2.8rem);
  line-height: 1.03;
  text-wrap: balance;
}

.source-note,
.persona-note,
.fallback-note {
  margin: 0.35rem 0 0;
  color: var(--text-secondary);
}

.persona-note {
  color: color-mix(in srgb, var(--accent) 78%, var(--text-secondary));
}

.fallback-note {
  color: color-mix(in srgb, var(--accent) 86%, var(--text-secondary));
  font-size: 0.86rem;
}

.header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.52rem;
}

.secondary-btn,
.reset-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 44%, var(--border));
  border-radius: 999px;
  background:
    linear-gradient(
      140deg,
      color-mix(in srgb, var(--accent) 16%, var(--surface)),
      color-mix(in srgb, var(--surface) 92%, transparent)
    );
  color: var(--text-primary);
  padding: 0.52rem 0.9rem;
}

.hero-stage {
  margin-top: 0.95rem;
  display: grid;
  gap: 0.75rem;
}

.identity-card,
.artboard-card {
  border-radius: calc(var(--radius) + 2px);
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  background:
    linear-gradient(
      145deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface)),
      color-mix(in srgb, var(--surface) 90%, transparent)
    );
  padding: 0.9rem;
  backdrop-filter: blur(var(--panel-blur));
}

.identity-title {
  margin: 0;
  font-size: 0.76rem;
  letter-spacing: 0.11em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 86%, var(--text-secondary));
}

.topic-cluster {
  margin-top: 0.55rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.topic-chip {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--border));
  background: color-mix(in srgb, var(--accent) 12%, var(--surface));
  padding: 0.24rem 0.56rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.brand-rail {
  margin-top: 0.62rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.brand-section-chip {
  text-decoration: none;
  color: inherit;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 36%, var(--border));
  padding: 0.24rem 0.56rem;
  font-size: 0.7rem;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}

.poster-grid {
  margin-top: 0.58rem;
  display: grid;
  gap: 0.5rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.poster-card {
  position: relative;
  border-radius: 12px;
  overflow: hidden;
  min-height: 114px;
  border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border));
  text-decoration: none;
  color: inherit;
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.poster-card:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--accent) 52%, var(--border));
  box-shadow: 0 12px 22px color-mix(in srgb, var(--accent) 20%, transparent);
}

.poster-card img,
.poster-fallback {
  width: 100%;
  height: 100%;
  min-height: 114px;
  object-fit: cover;
}

.poster-fallback {
  display: grid;
  place-items: center;
  padding: 0.8rem;
  text-align: center;
  background:
    radial-gradient(circle at 18% 18%, color-mix(in srgb, var(--accent) 32%, transparent), transparent 44%),
    linear-gradient(145deg, color-mix(in srgb, var(--accent) 24%, #0b1222), #0f172a 60%, #111827);
}

.poster-fallback span {
  color: color-mix(in srgb, var(--accent) 32%, #ffffff);
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.25;
}

.poster-card p {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  margin: 0;
  padding: 0.42rem 0.52rem;
  font-size: 0.75rem;
  line-height: 1.3;
  color: #ffffff;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.68), rgba(0, 0, 0, 0));
}

.poster-1,
.poster-4 {
  transform: translateY(-2px);
}

.shell-nav {
  margin-top: 0.9rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.nav-item {
  border: 1px solid color-mix(in srgb, var(--accent) 34%, var(--border));
  border-radius: 999px;
  background:
    linear-gradient(
      140deg,
      color-mix(in srgb, var(--accent) 12%, var(--surface)),
      color-mix(in srgb, var(--surface) 90%, transparent)
    );
  color: inherit;
  text-decoration: none;
  padding: 0.45rem 0.8rem;
  font-size: 0.84rem;
}

.module-stage {
  margin-top: 1rem;
  display: grid;
  gap: var(--module-gap);
}

.lead-slot,
.module-slot {
  animation: rise-in 620ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
  animation-delay: var(--stagger, 0ms);
}

.lead-slot :deep(.hero-module) {
  border-radius: calc(var(--radius) + 8px);
}

.module-columns {
  display: grid;
  gap: var(--module-gap);
}

.feature-stack,
.support-stack {
  display: grid;
  gap: var(--module-gap);
}

.slot-left {
  transform-origin: left center;
}

.slot-right {
  transform-origin: right center;
}

.layout-nav-side .shell-nav.nav-side {
  border-radius: calc(var(--radius) + 2px);
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  padding: 0.6rem;
  background: color-mix(in srgb, var(--surface) 88%, transparent);
}

.profile-atlas .poster-grid,
.profile-spectral .poster-grid,
.profile-orbital .poster-grid {
  grid-auto-flow: dense;
}

.profile-atlas .poster-1,
.profile-spectral .poster-3 {
  grid-column: span 2;
}

.profile-neo .command-header {
  border-width: 2px;
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 26%, transparent);
}

.profile-glass .command-header,
.profile-glass .identity-card,
.profile-glass .artboard-card {
  background: color-mix(in srgb, var(--surface) 70%, transparent);
  backdrop-filter: blur(calc(var(--panel-blur) + 3px));
}

.fx-matrix .ambient-grid {
  opacity: 0.46;
}

.fx-prism .ambient-node {
  border-radius: 36% 64% 56% 44%;
}

.fx-zen .ambient-node {
  opacity: 0.22;
}

.texture-grain .ambient-noise {
  opacity: 0.18;
}

.texture-soft .ambient-grid {
  opacity: 0.14;
}

.energy-high .ambient-node {
  animation-duration: 10s;
}

.energy-low .ambient-node {
  animation-duration: 24s;
  opacity: 0.24;
}

.motion-calm .lead-slot,
.motion-calm .module-slot {
  animation-duration: 780ms;
}

.motion-kinetic .lead-slot,
.motion-kinetic .module-slot {
  animation-duration: 420ms;
}

.experience-1 .module-columns {
  align-items: start;
}

.experience-2 .lead-slot :deep(.hero-module) {
  border-style: dashed;
}

.experience-3 .poster-card:nth-child(odd) {
  transform: rotate(-0.35deg);
}

.experience-3 .poster-card:nth-child(even) {
  transform: rotate(0.35deg);
}

@keyframes breathe {
  from {
    transform: translate(-50%, -50%) scale(0.9);
  }
  to {
    transform: translate(-50%, -50%) scale(1.08);
  }
}

@keyframes rise-in {
  from {
    opacity: 0;
    transform: translateY(14px) scale(0.99);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

@media (min-width: 900px) {
  .shell {
    padding: 1.2rem 2rem 6.5rem;
  }

  .command-header {
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: start;
    gap: 1rem;
  }

  .hero-stage {
    grid-template-columns: minmax(300px, 0.9fr) minmax(0, 1.1fr);
    align-items: stretch;
  }

  .shell-nav.nav-side {
    width: 340px;
    display: grid;
    grid-template-columns: 1fr;
  }

  .shell-nav.nav-side .nav-item {
    width: 100%;
  }

  .module-columns {
    grid-template-columns: minmax(0, 1fr) 320px;
    align-items: start;
  }

  .support-stack {
    position: sticky;
    top: 96px;
  }
}
</style>
