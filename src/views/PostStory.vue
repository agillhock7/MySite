<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { fetchWordpressPostDetail, type WordpressPostDetailResponse } from '@/api/wp';
import { usePersonalizationStore } from '@/stores/personalization';
import AssistantDock from '@/components/AssistantDock.vue';
import { getDesignIteration, getOrCreateVisitorId } from '@/personalization/visitor';

const route = useRoute();
const personalization = usePersonalizationStore();

const loading = ref(true);
const error = ref('');
const detail = ref<WordpressPostDetailResponse | null>(null);
const readingProgress = ref(0);

const visitorId = getOrCreateVisitorId();
const assistantVariantNonce = getDesignIteration();

const accent = computed(() => personalization.blueprint?.theme.accent ?? '#16c7cf');
const modeClass = computed(() => (personalization.blueprint?.theme.mode === 'light' ? 'mode-light' : 'mode-dark'));

const post = computed(() => detail.value?.post ?? null);
const related = computed(() => detail.value?.related ?? []);

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
  <main class="story-root" :class="modeClass" :style="shellStyle">
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
      <header class="story-header">
        <a href="/app" class="back-link">← Back to experience</a>
        <h1>{{ post.title }}</h1>
        <p class="deck" v-if="post.excerpt">{{ post.excerpt }}</p>
        <div class="meta-row">
          <span v-if="post.author">By {{ post.author }}</span>
          <span v-if="post.date">{{ formatDate(post.date) }}</span>
          <span>{{ post.readMinutes }} min read</span>
        </div>
        <div class="chip-row" v-if="post.categories.length || post.tags.length">
          <span v-for="category in post.categories" :key="`c:${category}`" class="chip category">{{ category }}</span>
          <span v-for="tag in post.tags.slice(0, 8)" :key="`t:${tag}`" class="chip">{{ tag }}</span>
        </div>
      </header>

      <figure v-if="post.imageUrl" class="hero-image">
        <img :src="post.imageUrl" alt="" loading="lazy" />
      </figure>

      <section class="article-content" v-html="post.contentHtml"></section>

      <footer class="story-footer">
        <a v-if="post.canonicalUrl" :href="post.canonicalUrl" target="_blank" rel="noopener noreferrer" class="source-link">
          View original on WordPress ↗
        </a>
      </footer>
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
  min-height: 100vh;
  padding: 0 1rem 12rem;
  background:
    radial-gradient(circle at 90% -10%, color-mix(in srgb, var(--accent) 22%, transparent), transparent 44%),
    linear-gradient(165deg, #050b18, #0a1429 50%, #101d39);
  color: #e8eefb;
}

.mode-light.story-root {
  background:
    radial-gradient(circle at 90% -10%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 44%),
    linear-gradient(160deg, #f4f8ff, #e7efff 45%, #f3f8ff);
  color: #111827;
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
  background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 70%, #ffffff), var(--accent));
  transform-origin: 0 50%;
}

.story-loading,
.story-error {
  max-width: 900px;
  margin: 3rem auto 0;
  border-radius: 18px;
  border: 1px solid color-mix(in srgb, var(--accent) 36%, #334155);
  background: color-mix(in srgb, var(--accent) 8%, #0f172a);
  padding: 1rem 1.1rem;
}

.story-shell {
  max-width: 980px;
  margin: 1.25rem auto 0;
  display: grid;
  gap: 1rem;
}

.story-header {
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, var(--accent) 32%, #334155);
  background:
    linear-gradient(140deg, color-mix(in srgb, var(--accent) 10%, rgba(15, 23, 42, 0.94)), rgba(15, 23, 42, 0.88));
  padding: 1.2rem;
}

.mode-light .story-header {
  background:
    linear-gradient(140deg, color-mix(in srgb, var(--accent) 8%, #ffffff), color-mix(in srgb, var(--accent) 2%, #ffffff));
}

.back-link {
  display: inline-block;
  margin-bottom: 0.75rem;
  color: color-mix(in srgb, var(--accent) 84%, #9ca3af);
  text-decoration: none;
}

h1 {
  margin: 0;
  font-size: clamp(1.8rem, 5.2vw, 3rem);
  line-height: 1.06;
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
  border: 1px solid color-mix(in srgb, var(--accent) 30%, #334155);
  padding: 0.22rem 0.6rem;
  font-size: 0.78rem;
}

.chip-row {
  margin-top: 0.75rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.38rem;
}

.chip {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 28%, #334155);
  padding: 0.2rem 0.58rem;
  font-size: 0.72rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.chip.category {
  background: color-mix(in srgb, var(--accent) 18%, transparent);
}

.hero-image {
  margin: 0;
  border-radius: 20px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, #334155);
  max-height: 480px;
}

.hero-image img {
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.article-content {
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, #334155);
  background:
    linear-gradient(160deg, rgba(15, 23, 42, 0.88), rgba(15, 23, 42, 0.8));
  padding: clamp(1rem, 3.6vw, 2.1rem);
  line-height: 1.78;
  font-size: 1.04rem;
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
  padding: 0.65em 0.85em;
  border-left: 3px solid color-mix(in srgb, var(--accent) 70%, transparent);
  background: color-mix(in srgb, var(--accent) 10%, transparent);
}

.story-footer {
  display: flex;
  justify-content: flex-end;
}

.source-link {
  color: color-mix(in srgb, var(--accent) 86%, #9ca3af);
  text-decoration: none;
}

.related-shell {
  max-width: 980px;
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

.related-card img {
  width: 100%;
  height: 160px;
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

@media (min-width: 880px) {
  .story-root {
    padding: 0 2rem 8rem;
  }

  .related-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
