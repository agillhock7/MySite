<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { fetchWordpressPostDetail, type WordpressPostDetailResponse } from '@/api/wp';
import { usePersonalizationStore } from '@/stores/personalization';
import AssistantDock from '@/components/AssistantDock.vue';
import HoloBackdrop from '@/components/effects/HoloBackdrop.vue';
import { getDesignIteration, getOrCreateVisitorId } from '@/personalization/visitor';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { hashText, seededUnit } from '@/utils/seed';

const route = useRoute();
const personalization = usePersonalizationStore();

const loading = ref(true);
const error = ref('');
const detail = ref<WordpressPostDetailResponse | null>(null);
const readingProgress = ref(0);
const { prefersReducedMotion } = useReducedMotion();

const visitorId = getOrCreateVisitorId();
const assistantVariantNonce = getDesignIteration();

const accent = computed(() => personalization.blueprint?.theme.accent ?? '#16c7cf');
const modeClass = computed(() => (personalization.blueprint?.theme.mode === 'light' ? 'mode-light' : 'mode-dark'));

const post = computed(() => detail.value?.post ?? null);
const related = computed(() => detail.value?.related ?? []);

const storySeed = computed(() => {
  const base = `${post.value?.id ?? 0}|${post.value?.title ?? ''}|${accent.value}`;
  return hashText(base);
});

const ambientNodes = computed(() => {
  const count = prefersReducedMotion.value ? 4 : 8;
  const nodes = [] as Array<{
    key: string;
    left: string;
    top: string;
    size: string;
    opacity: string;
    duration: string;
  }>;

  for (let index = 0; index < count; index += 1) {
    nodes.push({
      key: `story-node-${index}`,
      left: `${(seededUnit(storySeed.value, `left-${index}`) * 100).toFixed(2)}%`,
      top: `${(seededUnit(storySeed.value, `top-${index}`) * 100).toFixed(2)}%`,
      size: `${(160 + seededUnit(storySeed.value, `size-${index}`) * 300).toFixed(0)}px`,
      opacity: (0.12 + seededUnit(storySeed.value, `opacity-${index}`) * 0.34).toFixed(2),
      duration: `${(11 + seededUnit(storySeed.value, `duration-${index}`) * 14).toFixed(1)}s`
    });
  }

  return nodes;
});

const ambientRings = computed(() => {
  const count = prefersReducedMotion.value ? 1 : 3;
  const rings = [] as Array<{
    key: string;
    left: string;
    top: string;
    size: string;
    opacity: string;
    duration: string;
    rotate: string;
  }>;

  for (let index = 0; index < count; index += 1) {
    rings.push({
      key: `story-ring-${index}`,
      left: `${(10 + seededUnit(storySeed.value, `ring-left-${index}`) * 80).toFixed(2)}%`,
      top: `${(10 + seededUnit(storySeed.value, `ring-top-${index}`) * 76).toFixed(2)}%`,
      size: `${(180 + seededUnit(storySeed.value, `ring-size-${index}`) * 380).toFixed(0)}px`,
      opacity: (0.14 + seededUnit(storySeed.value, `ring-opacity-${index}`) * 0.28).toFixed(2),
      duration: `${(15 + seededUnit(storySeed.value, `ring-duration-${index}`) * 20).toFixed(1)}s`,
      rotate: `${(seededUnit(storySeed.value, `ring-rotate-${index}`) * 360).toFixed(2)}deg`
    });
  }

  return rings;
});

const shellStyle = computed(() => ({
  '--accent': accent.value
}));

function formatDate(value: string): string {
  if (!value) {
    return '';
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return parsed.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
}

function updateReadingProgress(): void {
  const article = document.querySelector<HTMLElement>('.article-content');
  if (!article) {
    readingProgress.value = 0;
    return;
  }

  const rect = article.getBoundingClientRect();
  const viewport = window.innerHeight;
  const total = rect.height + viewport;
  const consumed = Math.min(total, Math.max(0, viewport - rect.top));
  const ratio = total > 0 ? consumed / total : 0;
  readingProgress.value = Math.max(0, Math.min(1, ratio));
}

async function loadStory(): Promise<void> {
  loading.value = true;
  error.value = '';

  const id = Number.parseInt(String(route.params.id ?? ''), 10);
  if (!Number.isFinite(id) || id <= 0) {
    error.value = 'Invalid story id.';
    loading.value = false;
    return;
  }

  const payload = await fetchWordpressPostDetail(id);
  if (!payload || !payload.available || !payload.post) {
    error.value = 'Unable to load this story right now.';
    detail.value = null;
    loading.value = false;
    return;
  }

  detail.value = payload;
  loading.value = false;

  requestAnimationFrame(() => {
    updateReadingProgress();
  });
}

onMounted(async () => {
  await loadStory();
  window.addEventListener('scroll', updateReadingProgress, { passive: true });
});

onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateReadingProgress);
});

watch(
  () => route.params.id,
  async () => {
    await loadStory();
  }
);
</script>

<template>
  <main class="story-root" :class="[modeClass, prefersReducedMotion ? 'reduced-motion' : '']" :style="shellStyle">
    <div class="ambient-layer" aria-hidden="true">
      <HoloBackdrop class="holo-layer" :accent="accent" :seed="storySeed" :reduced-motion="prefersReducedMotion" />
      <span class="ambient-grid"></span>
      <span
        v-for="ring in ambientRings"
        :key="ring.key"
        class="ambient-ring"
        :style="{
          left: ring.left,
          top: ring.top,
          width: ring.size,
          height: ring.size,
          opacity: ring.opacity,
          transform: `translate(-50%, -50%) rotate(${ring.rotate})`,
          animationDuration: ring.duration
        }"
      ></span>
      <span
        v-for="node in ambientNodes"
        :key="node.key"
        class="ambient-node"
        :style="{
          left: node.left,
          top: node.top,
          width: node.size,
          height: node.size,
          opacity: node.opacity,
          animationDuration: node.duration
        }"
      ></span>
    </div>

    <div class="progress-wrap" aria-hidden="true">
      <span class="progress-bar" :style="{ transform: `scaleX(${readingProgress})` }"></span>
    </div>

    <section v-if="loading" class="story-loading">
      <p>Loading story stream...</p>
    </section>

    <section v-else-if="error" class="story-error">
      <h1>Story Unavailable</h1>
      <p>{{ error }}</p>
      <a href="/app">Return to experience</a>
    </section>

    <article v-else-if="post" class="story-shell">
      <header class="story-hero">
        <a href="/app" class="back-link">← Back to experience</a>
        <h1>{{ post.title }}</h1>
        <p class="deck" v-if="post.excerpt">{{ post.excerpt }}</p>

        <div class="meta-row">
          <span v-if="post.author">By {{ post.author }}</span>
          <span v-if="post.date">{{ formatDate(post.date) }}</span>
          <span>{{ post.readMinutes }} min read</span>
        </div>
      </header>

      <figure v-if="post.imageUrl" class="hero-image">
        <img :src="post.imageUrl" alt="" loading="lazy" />
      </figure>

      <div class="story-grid">
        <aside class="story-sidebar">
          <div class="sidebar-block" v-if="post.categories.length || post.tags.length">
            <p class="sidebar-label">Topics</p>
            <div class="chip-row">
              <span v-for="category in post.categories" :key="`c:${category}`" class="chip category">{{ category }}</span>
              <span v-for="tag in post.tags.slice(0, 10)" :key="`t:${tag}`" class="chip">{{ tag }}</span>
            </div>
          </div>

          <div class="sidebar-block" v-if="post.canonicalUrl">
            <p class="sidebar-label">Source</p>
            <a :href="post.canonicalUrl" target="_blank" rel="noopener noreferrer" class="source-link">View original on WordPress ↗</a>
          </div>

          <div class="sidebar-block" v-if="related.length > 0">
            <p class="sidebar-label">Next stories</p>
            <div class="related-inline">
              <a v-for="item in related.slice(0, 3)" :key="item.id" :href="item.href" class="related-inline-item">{{ item.title }}</a>
            </div>
          </div>
        </aside>

        <section class="article-content" v-html="post.contentHtml"></section>
      </div>
    </article>

    <section v-if="!loading && !error && related.length > 0" class="related-shell">
      <h2>Continue Reading</h2>
      <div class="related-grid">
        <a v-for="item in related" :key="item.id" :href="item.href" class="related-card">
          <img v-if="item.imageUrl" :src="item.imageUrl" alt="" loading="lazy" />
          <h3>{{ item.title }}</h3>
          <p>{{ item.excerpt }}</p>
        </a>
      </div>
    </section>

    <AssistantDock :visitor-id="visitorId" :variant-nonce="assistantVariantNonce" :design-signature="`POST-${route.params.id}`" />
  </main>
</template>

<style scoped>
.story-root {
  position: relative;
  overflow: hidden;
  min-height: 100vh;
  padding: 0 1rem 12rem;
  background:
    radial-gradient(circle at 88% -10%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 44%),
    linear-gradient(165deg, #040a17, #09162e 48%, #0f2142);
  color: #e8eefb;
}

.mode-light.story-root {
  background:
    radial-gradient(circle at 88% -10%, color-mix(in srgb, var(--accent) 14%, transparent), transparent 44%),
    linear-gradient(160deg, #f4f8ff, #e7efff 45%, #f4f8ff);
  color: #111827;
}

.ambient-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}

.holo-layer {
  position: absolute;
  inset: 0;
  opacity: 0.68;
}

.ambient-grid {
  position: absolute;
  inset: 0;
  opacity: 0.24;
  background:
    linear-gradient(transparent 95%, color-mix(in srgb, var(--accent) 20%, transparent) 100%),
    linear-gradient(90deg, transparent 95%, color-mix(in srgb, var(--accent) 16%, transparent) 100%);
  background-size: 100% 32px, 32px 100%;
}

.ambient-node {
  position: absolute;
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 38%, transparent);
  filter: blur(22px);
  transform: translate(-50%, -50%);
  animation: breathe 14s ease-in-out infinite alternate;
}

.ambient-ring {
  position: absolute;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 38%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 14%, transparent);
  animation: spin-slow 22s linear infinite;
}

.progress-wrap,
.story-loading,
.story-error,
.story-shell,
.related-shell {
  position: relative;
  z-index: 2;
}

.progress-wrap {
  position: sticky;
  top: 0;
  z-index: 30;
  height: 3px;
  background: color-mix(in srgb, var(--accent) 20%, transparent);
}

.progress-bar {
  display: block;
  height: 100%;
  width: 100%;
  background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 74%, #ffffff), var(--accent));
  transform-origin: 0 50%;
}

.story-loading,
.story-error {
  max-width: 980px;
  margin: 2.8rem auto 0;
  border-radius: 18px;
  border: 1px solid color-mix(in srgb, var(--accent) 36%, #334155);
  background: color-mix(in srgb, var(--accent) 8%, #0f172a);
  padding: 1rem 1.1rem;
}

.story-shell {
  max-width: 1100px;
  margin: 1.2rem auto 0;
  display: grid;
  gap: 0.9rem;
}

.story-hero {
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, var(--accent) 34%, #334155);
  background:
    linear-gradient(140deg, color-mix(in srgb, var(--accent) 10%, rgba(15, 23, 42, 0.9)), rgba(15, 23, 42, 0.84));
  padding: clamp(1rem, 2.4vw, 1.4rem);
}

.mode-light .story-hero {
  background: linear-gradient(140deg, color-mix(in srgb, var(--accent) 8%, #ffffff), color-mix(in srgb, var(--accent) 2%, #ffffff));
}

.back-link {
  display: inline-block;
  margin-bottom: 0.68rem;
  color: color-mix(in srgb, var(--accent) 84%, #9ca3af);
  text-decoration: none;
}

h1 {
  margin: 0;
  font-size: clamp(1.7rem, 5.3vw, 3.2rem);
  line-height: 1.04;
  text-wrap: balance;
}

.deck {
  margin: 0.72rem 0 0;
  color: color-mix(in srgb, var(--accent) 24%, #cbd5e1);
  font-size: 1rem;
}

.mode-light .deck {
  color: #4b5563;
}

.meta-row {
  margin-top: 0.8rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.48rem;
}

.meta-row span {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 32%, #334155);
  padding: 0.22rem 0.6rem;
  font-size: 0.78rem;
}

.hero-image {
  margin: 0;
  border-radius: 20px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--accent) 26%, #334155);
  max-height: 500px;
}

.hero-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.story-grid {
  display: grid;
  gap: 0.9rem;
}

.story-sidebar {
  display: grid;
  gap: 0.75rem;
}

.sidebar-block {
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--accent) 26%, #334155);
  background: color-mix(in srgb, var(--accent) 8%, rgba(15, 23, 42, 0.74));
  padding: 0.68rem;
}

.mode-light .sidebar-block {
  background: color-mix(in srgb, var(--accent) 4%, #ffffff);
}

.sidebar-label {
  margin: 0;
  font-size: 0.72rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 84%, #94a3b8);
}

.chip-row {
  margin-top: 0.45rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.36rem;
}

.chip {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 28%, #334155);
  padding: 0.2rem 0.58rem;
  font-size: 0.7rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.chip.category {
  background: color-mix(in srgb, var(--accent) 18%, transparent);
}

.source-link {
  display: inline-block;
  margin-top: 0.5rem;
  color: color-mix(in srgb, var(--accent) 82%, #cbd5e1);
  text-decoration: none;
}

.related-inline {
  margin-top: 0.48rem;
  display: grid;
  gap: 0.38rem;
}

.related-inline-item {
  color: inherit;
  text-decoration: none;
  border: 1px solid color-mix(in srgb, var(--accent) 26%, #334155);
  border-radius: 10px;
  background: color-mix(in srgb, var(--accent) 6%, transparent);
  padding: 0.4rem 0.5rem;
  font-size: 0.86rem;
}

.article-content {
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, #334155);
  background: linear-gradient(160deg, rgba(15, 23, 42, 0.88), rgba(15, 23, 42, 0.8));
  padding: clamp(1rem, 3.4vw, 2.15rem);
  line-height: 1.8;
  font-size: 1.05rem;
}

.mode-light .article-content {
  background: #ffffff;
}

.article-content :deep(h2),
.article-content :deep(h3),
.article-content :deep(h4) {
  line-height: 1.2;
  margin-top: 1.35em;
  margin-bottom: 0.38em;
}

.article-content :deep(p) {
  margin: 0.6em 0;
}

.article-content :deep(a) {
  color: color-mix(in srgb, var(--accent) 80%, #60a5fa);
}

.article-content :deep(blockquote) {
  margin: 1em 0;
  padding: 0.7em 0.9em;
  border-left: 3px solid color-mix(in srgb, var(--accent) 70%, transparent);
  background: color-mix(in srgb, var(--accent) 10%, transparent);
}

.related-shell {
  max-width: 1100px;
  margin: 1rem auto 0;
}

.related-shell h2 {
  margin: 0;
  font-size: 1.2rem;
}

.related-grid {
  margin-top: 0.7rem;
  display: grid;
  gap: 0.72rem;
  grid-template-columns: 1fr;
}

.related-card {
  text-decoration: none;
  color: inherit;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, #334155);
  background: color-mix(in srgb, var(--accent) 8%, rgba(15, 23, 42, 0.75));
  padding: 0.75rem;
  display: grid;
  gap: 0.5rem;
}

.mode-light .related-card {
  background: color-mix(in srgb, var(--accent) 4%, #ffffff);
}

.related-card img {
  width: 100%;
  height: 170px;
  object-fit: cover;
  border-radius: 12px;
}

.related-card h3 {
  margin: 0;
}

.related-card p {
  margin: 0;
  color: #9fb1d5;
}

.mode-light .related-card p {
  color: #4b5563;
}

.reduced-motion .ambient-node,
.reduced-motion .ambient-ring {
  animation-duration: 0.01ms !important;
  animation-iteration-count: 1 !important;
}

@keyframes breathe {
  from {
    transform: translate(-50%, -50%) scale(0.92);
  }
  to {
    transform: translate(-50%, -50%) scale(1.08);
  }
}

@keyframes spin-slow {
  from {
    transform: translate(-50%, -50%) rotate(0deg);
  }
  to {
    transform: translate(-50%, -50%) rotate(360deg);
  }
}

@media (min-width: 980px) {
  .story-root {
    padding: 0 2rem 8rem;
  }

  .story-grid {
    grid-template-columns: 300px minmax(0, 1fr);
    align-items: start;
  }

  .story-sidebar {
    position: sticky;
    top: 18px;
  }

  .related-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
