<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchWordpressContentBundle } from '@/api/wp';
import { getContentByKey, setRuntimeContentOverrides } from '@/content/library';
import { BUILD_TAG } from '@/meta/build';
import { getOrCreateVisitorId } from '@/personalization/visitor';
import { usePersonalizationStore } from '@/stores/personalization';
import { buildExperienceScene, type ExperiencePost } from '@/experience/session';
import { hashText } from '@/utils/seed';

interface TerminalLine {
  id: number;
  tone: 'system' | 'user' | 'signal';
  text: string;
}

const router = useRouter();
const personalization = usePersonalizationStore();

const initializing = ref(true);
const initializationError = ref('');
const wordpressError = ref('');
const sceneNonce = ref(0);
const forcedFocus = ref('');
const commandInput = ref('');
const transcriptRef = ref<HTMLElement | null>(null);
const transcript = ref<TerminalLine[]>([]);

const blueprint = computed(() => personalization.blueprint);
const visitorId = getOrCreateVisitorId();

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

function addLine(tone: TerminalLine['tone'], text: string): void {
  transcript.value.push({
    id: Date.now() + Math.floor(Math.random() * 1000),
    tone,
    text
  });

  nextTick(() => {
    if (!transcriptRef.value) {
      return;
    }

    transcriptRef.value.scrollTop = transcriptRef.value.scrollHeight;
  });
}

function isExternalUrl(url: string): boolean {
  return /^https?:\/\//i.test(url);
}

async function openAction(url: string): Promise<void> {
  if (!url) {
    return;
  }

  if (url.startsWith('/')) {
    await router.push(url);
    return;
  }

  if (isExternalUrl(url)) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
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
    wordpressError.value = 'WordPress fetch unavailable. Running from cached runtime content.';
  } else if (wpBundle.wordpress && !wpBundle.wordpress.available) {
    const firstError = wpBundle.wordpress.errors[0] ?? '';
    wordpressError.value = firstError
      ? `WordPress REST warning: ${firstError}`
      : 'WordPress REST warning: no posts were returned.';
  }

  if (!personalization.blueprint) {
    initializing.value = false;
    await router.replace('/onboarding');
    return;
  }

  initializing.value = false;
}

const brandName = computed(() => firstStringModuleProp('brandName') || 'Alexander Gill');
const brandTagline = computed(() => firstStringModuleProp('brandTagline') || 'Power plays.');
const brandBaseUrl = computed(() => firstStringModuleProp('brandBaseUrl') || 'https://alexanderjgill.com');
const brandIconUrl = computed(
  () => firstStringModuleProp('brandIconUrl') || 'https://alexanderjgill.com/wp-content/uploads/2025/09/A_icon_1_171f1f.png'
);

const focusTopics = computed(() => {
  const fromProps = firstStringArrayModuleProp('focusTopics');
  if (fromProps.length > 0) {
    return fromProps.slice(0, 6);
  }

  return ['Identity', 'Ideas', 'Momentum'];
});

const designSignature = computed(() => {
  const fromBlueprint = firstStringModuleProp('signature');
  if (fromBlueprint) {
    return fromBlueprint.slice(0, 10).toUpperCase();
  }

  if (!blueprint.value) {
    return 'SIG-0000';
  }

  const moduleIds = blueprint.value.modules.map((module) => module.id).join('|');
  return hashText(`${blueprint.value.createdAt}|${moduleIds}`).toString(36).toUpperCase().padStart(8, '0').slice(0, 8);
});

const goalSignal = computed(() => {
  const heroTitle = firstStringModuleProp('title');
  if (heroTitle) {
    return heroTitle;
  }

  return 'Guide each visitor through a distinctive content journey.';
});

const posts = computed<ExperiencePost[]>(() => {
  const featured = asRecord(getContentByKey('featuredGrid'));
  const items = Array.isArray(featured?.items) ? featured.items : [];

  const mapped = items
    .map((entry, index) => {
      const item = asRecord(entry);
      if (!item) {
        return null;
      }

      const id = String(item.id ?? `post-${index + 1}`);
      const title = typeof item.title === 'string' ? item.title.trim() : '';
      if (!title) {
        return null;
      }

      const description = typeof item.description === 'string' ? item.description.trim() : 'No summary available yet.';
      const hrefRaw = typeof item.href === 'string' ? item.href.trim() : '';
      const canonical = typeof item.canonicalUrl === 'string' ? item.canonicalUrl.trim() : '';
      const href = hrefRaw || canonical || brandBaseUrl.value;
      const imageUrl = typeof item.imageUrl === 'string' ? item.imageUrl.trim() : '';
      const meta = typeof item.meta === 'string' ? item.meta.trim() : 'Live stream';

      return {
        id,
        title,
        description,
        href,
        imageUrl,
        meta
      };
    })
    .filter((item): item is ExperiencePost => item !== null)
    .slice(0, 8);

  if (mapped.length > 0) {
    return mapped;
  }

  return [
    {
      id: 'fallback-post',
      title: 'Open alexanderjgill.com',
      description: 'No feed detected in runtime cache. Open the source blog directly.',
      href: brandBaseUrl.value,
      imageUrl: '',
      meta: 'Fallback stream'
    }
  ];
});

const shortcuts = computed(() => (blueprint.value?.shortcuts ?? []).slice(0, 6));

const scene = computed(() =>
  buildExperienceScene({
    visitorId,
    signature: designSignature.value,
    goal: goalSignal.value,
    topics: focusTopics.value,
    posts: posts.value,
    nonce: sceneNonce.value,
    forcedFocus: forcedFocus.value
  })
);

function parseFocusInput(input: string): string {
  const words = input
    .split(/[\s,]+/)
    .map((token) => token.trim())
    .filter((token) => token.length > 2);

  if (words.length === 0) {
    return '';
  }

  return words.slice(0, 2).join(' ');
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.push('/onboarding?force=1&reset=1');
}

async function handleCommand(raw: string): Promise<void> {
  const input = raw.trim();
  if (!input) {
    return;
  }

  addLine('user', input);
  commandInput.value = '';

  if (input === '/help') {
    addLine('system', 'Commands: /help, /shuffle, /focus <topic>, /open <1-3>, /clear, /reset');
    return;
  }

  if (input === '/clear') {
    transcript.value = [];
    addLine('system', `Transcript cleared. ${scene.value.codename} remains active.`);
    return;
  }

  if (input === '/shuffle') {
    sceneNonce.value += 1;
    forcedFocus.value = '';
    addLine('signal', `Scene recompiled -> ${scene.value.codename}`);
    return;
  }

  if (input.startsWith('/focus ')) {
    const candidate = parseFocusInput(input.slice('/focus '.length));
    if (!candidate) {
      addLine('system', 'Focus command requires a topic. Example: /focus hockey');
      return;
    }

    forcedFocus.value = candidate;
    sceneNonce.value += 1;
    addLine('signal', `Focus lane locked -> ${candidate}`);
    return;
  }

  if (input.startsWith('/open ')) {
    const token = input.slice('/open '.length).trim();
    const index = Number.parseInt(token, 10) - 1;
    const track = scene.value.tracks[index];
    if (!track) {
      addLine('system', 'Track not found. Use /open 1, /open 2, or /open 3.');
      return;
    }

    addLine('signal', `Opening ${track.label}...`);
    await openAction(track.ctaHref);
    return;
  }

  if (input === '/reset') {
    addLine('system', 'Resetting personalization and returning to onboarding terminal...');
    await resetPersonalization();
    return;
  }

  const inferredFocus = parseFocusInput(input);
  if (inferredFocus) {
    forcedFocus.value = inferredFocus;
    sceneNonce.value += 1;
    addLine('signal', `Captured signal -> ${inferredFocus}. Scene updated.`);
    return;
  }

  sceneNonce.value += 1;
  addLine('signal', 'Signal captured. Try /help for direct commands.');
}

onMounted(async () => {
  await initializePersonalization();

  if (!blueprint.value) {
    return;
  }

  addLine('system', `Visitor experience terminal active · Signature ${designSignature.value}`);
  addLine('system', scene.value.mission);
  addLine('signal', scene.value.pulse);
  addLine('system', 'Type /help to control your experience stream.');
});
</script>

<template>
  <main v-if="initializing" class="experience-root loading-root">
    <section class="loading-card">
      <p>Booting visitor experience terminal...</p>
    </section>
  </main>

  <main v-else-if="blueprint" class="experience-root">
    <header class="topbar">
      <a class="brand" :href="brandBaseUrl" target="_blank" rel="noopener noreferrer">
        <img :src="brandIconUrl" alt="" loading="lazy" />
        <span>
          <strong>{{ brandName }}</strong>
          <em>{{ brandTagline }}</em>
        </span>
      </a>

      <div class="topbar-meta">
        <p>{{ scene.codename }} · {{ BUILD_TAG }}</p>
        <button type="button" @click="resetPersonalization">Reset Personalization</button>
      </div>
    </header>

    <section class="mission-shell">
      <article class="mission-card">
        <p class="mission-kicker">Mission Brief</p>
        <h1>{{ scene.mission }}</h1>
        <p class="voice-line">{{ scene.voice }}</p>
        <p class="pulse-line">{{ scene.pulse }}</p>
        <p v-if="wordpressError" class="warning-line">{{ wordpressError }}</p>
        <p v-if="initializationError" class="warning-line">{{ initializationError }}</p>
      </article>

      <article class="prompt-card">
        <p class="mission-kicker">Prompt Suggestions</p>
        <ul>
          <li v-for="prompt in scene.prompts" :key="prompt">{{ prompt }}</li>
        </ul>
      </article>
    </section>

    <section class="terminal-shell">
      <div ref="transcriptRef" class="transcript" aria-live="polite">
        <p v-for="line in transcript" :key="line.id" class="line" :class="`tone-${line.tone}`">
          <span class="glyph">{{ line.tone === 'user' ? '>' : line.tone === 'signal' ? '#' : '$' }}</span>
          {{ line.text }}
        </p>
      </div>

      <form class="command-row" @submit.prevent="handleCommand(commandInput)">
        <span class="glyph">></span>
        <input
          v-model="commandInput"
          type="text"
          autocomplete="off"
          placeholder="Type command or intent, then press Enter"
        />
      </form>
    </section>

    <section class="tracks-grid">
      <article v-for="track in scene.tracks" :key="track.id" class="track-card">
        <p class="track-signal">{{ track.signal }}</p>
        <h2>{{ track.label }}</h2>
        <p>{{ track.summary }}</p>
        <button type="button" @click="openAction(track.ctaHref)">{{ track.ctaLabel }}</button>
      </article>
    </section>

    <section class="content-stream">
      <article v-for="post in posts" :key="post.id" class="post-row">
        <img v-if="post.imageUrl" :src="post.imageUrl" alt="" loading="lazy" />
        <div>
          <p class="post-meta">{{ post.meta }}</p>
          <h3>{{ post.title }}</h3>
          <p>{{ post.description }}</p>
          <a :href="post.href" :target="isExternalUrl(post.href) ? '_blank' : '_self'" :rel="isExternalUrl(post.href) ? 'noopener noreferrer' : undefined">Open</a>
        </div>
      </article>
    </section>

    <section class="shortcut-row">
      <a
        v-for="shortcut in shortcuts"
        :key="`${shortcut.label}:${shortcut.action}`"
        class="shortcut-chip"
        :href="shortcut.action"
        :target="isExternalUrl(shortcut.action) ? '_blank' : '_self'"
        :rel="isExternalUrl(shortcut.action) ? 'noopener noreferrer' : undefined"
      >
        {{ shortcut.label }}
      </a>
    </section>
  </main>
</template>

<style scoped>
.experience-root {
  min-height: 100vh;
  background:
    radial-gradient(circle at 90% -16%, rgba(16, 185, 129, 0.2), transparent 42%),
    radial-gradient(circle at -12% 88%, rgba(14, 165, 233, 0.15), transparent 50%),
    #000000;
  color: #d1fae5;
  font-family: 'IBM Plex Mono', 'Fira Code', monospace;
  padding: 1rem;
}

.loading-root {
  display: grid;
  place-items: center;
}

.loading-card {
  border: 1px solid #1f2937;
  border-radius: 14px;
  padding: 0.95rem 1rem;
  background: #040404;
}

.topbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.8rem;
  border: 1px solid #1f2937;
  border-radius: 14px;
  background: rgba(3, 7, 18, 0.78);
  padding: 0.8rem 0.9rem;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: inherit;
  text-decoration: none;
}

.brand img {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  border: 1px solid #334155;
}

.brand span {
  display: grid;
  line-height: 1.02;
}

.brand strong {
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.brand em {
  font-style: normal;
  font-size: 0.72rem;
  color: #67e8f9;
}

.topbar-meta {
  display: grid;
  gap: 0.4rem;
  justify-items: end;
}

.topbar-meta p {
  margin: 0;
  color: #86efac;
  font-size: 0.74rem;
  letter-spacing: 0.06em;
}

.topbar-meta button {
  border: 1px solid #134e4a;
  border-radius: 999px;
  background: #022c22;
  color: #99f6e4;
  padding: 0.38rem 0.75rem;
}

.mission-shell {
  margin-top: 0.85rem;
  display: grid;
  gap: 0.7rem;
}

.mission-card,
.prompt-card {
  border: 1px solid #1f2937;
  border-radius: 14px;
  background: rgba(2, 6, 23, 0.82);
  padding: 0.9rem;
}

.mission-kicker {
  margin: 0;
  font-size: 0.73rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: #67e8f9;
}

h1 {
  margin: 0.38rem 0 0;
  font-size: clamp(1.2rem, 3.5vw, 2rem);
  line-height: 1.08;
}

.voice-line,
.pulse-line,
.warning-line {
  margin: 0.45rem 0 0;
  color: #86efac;
}

.warning-line {
  color: #fca5a5;
}

.prompt-card ul {
  margin: 0.55rem 0 0;
  padding-left: 1.1rem;
  display: grid;
  gap: 0.35rem;
  color: #a7f3d0;
}

.terminal-shell {
  margin-top: 0.85rem;
  border: 1px solid #1f2937;
  border-radius: 14px;
  background: #020617;
  overflow: hidden;
}

.transcript {
  max-height: 240px;
  overflow: auto;
  padding: 0.85rem;
  display: grid;
  gap: 0.42rem;
}

.line {
  margin: 0;
  display: flex;
  gap: 0.48rem;
  align-items: flex-start;
  font-size: 0.92rem;
}

.tone-system {
  color: #bbf7d0;
}

.tone-user {
  color: #e2e8f0;
}

.tone-signal {
  color: #67e8f9;
}

.glyph {
  color: #10b981;
  min-width: 0.8rem;
}

.command-row {
  border-top: 1px solid #0f172a;
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.45rem;
  align-items: center;
  padding: 0.75rem 0.85rem;
}

.command-row input {
  width: 100%;
  border: none;
  outline: none;
  background: transparent;
  color: #d1fae5;
}

.command-row input::placeholder {
  color: #64748b;
}

.tracks-grid {
  margin-top: 0.85rem;
  display: grid;
  gap: 0.68rem;
  grid-template-columns: 1fr;
}

.track-card {
  border: 1px solid #1f2937;
  border-radius: 12px;
  background: rgba(3, 7, 18, 0.84);
  padding: 0.85rem;
}

.track-signal {
  margin: 0;
  color: #67e8f9;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
}

.track-card h2 {
  margin: 0.35rem 0 0;
  font-size: 1rem;
}

.track-card p {
  margin: 0.4rem 0 0;
  color: #a7f3d0;
}

.track-card button {
  margin-top: 0.62rem;
  border: 1px solid #134e4a;
  border-radius: 999px;
  background: #022c22;
  color: #99f6e4;
  padding: 0.34rem 0.72rem;
}

.content-stream {
  margin-top: 0.85rem;
  display: grid;
  gap: 0.65rem;
}

.post-row {
  border: 1px solid #1f2937;
  border-radius: 12px;
  background: rgba(2, 6, 23, 0.82);
  padding: 0.7rem;
  display: grid;
  gap: 0.65rem;
}

.post-row img {
  width: 100%;
  max-height: 220px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid #334155;
}

.post-meta {
  margin: 0;
  color: #67e8f9;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.post-row h3 {
  margin: 0.32rem 0 0;
  font-size: 1rem;
}

.post-row p {
  margin: 0.35rem 0 0;
  color: #a7f3d0;
}

.post-row a {
  display: inline-block;
  margin-top: 0.5rem;
  color: #bbf7d0;
  text-decoration: none;
  border-bottom: 1px dashed #67e8f9;
}

.shortcut-row {
  margin-top: 0.85rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.shortcut-chip {
  text-decoration: none;
  color: #99f6e4;
  border: 1px solid #134e4a;
  border-radius: 999px;
  background: #052e2b;
  padding: 0.3rem 0.65rem;
  font-size: 0.78rem;
}

@media (min-width: 860px) {
  .experience-root {
    padding: 1.2rem 1.8rem 2rem;
  }

  .mission-shell {
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 0.7fr);
  }

  .tracks-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .content-stream {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
