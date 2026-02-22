<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchWordpressContentBundle } from '@/api/wp';
import AiPromptGame from '@/components/AiPromptGame.vue';
import DashboardWidgetRenderer from '@/components/DashboardWidgetRenderer.vue';
import {
  MAX_DASHBOARD_WIDGETS,
  createPresetWidget,
  loadWidgets,
  saveWidgets,
  sanitizeWidgetHtml,
  type DashboardWidget,
  type DashboardWidgetType
} from '@/dashboard/engine';
import { handleNaturalLanguageWidgetRequest } from '@/dashboard/assistant';
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
const widgets = ref<DashboardWidget[]>([]);
const widgetRefreshNonce = ref(0);
const widgetRefreshKeys = ref<Record<string, number>>({});

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

function persistWidgets(): void {
  saveWidgets(widgets.value);
}

function deployWidget(widget: DashboardWidget, sourceLabel = 'AI CLI'): boolean {
  if (widgets.value.length >= MAX_DASHBOARD_WIDGETS) {
    addLine(
      'system',
      `Widget limit reached (${MAX_DASHBOARD_WIDGETS}/${MAX_DASHBOARD_WIDGETS}). Remove one with /widget remove <id> or /widget clear.`
    );
    return false;
  }

  widgets.value = [widget, ...widgets.value].slice(0, MAX_DASHBOARD_WIDGETS);
  persistWidgets();
  addLine('signal', `${sourceLabel} deployed widget ${widget.id} · ${widget.title}`);
  return true;
}

function parseWidgetType(rawType: string): DashboardWidgetType | null {
  const normalized = rawType.trim().toLowerCase().replace('-', '');
  if (
    normalized === 'weather' ||
    normalized === 'horoscope' ||
    normalized === 'fashion' ||
    normalized === 'sports' ||
    normalized === 'customhtml'
  ) {
    return normalized === 'customhtml' ? 'customHtml' : (normalized as DashboardWidgetType);
  }

  return null;
}

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

function removeWidgetById(widgetId: string): boolean {
  const nextWidgets = widgets.value.filter((widget) => widget.id.toLowerCase() !== widgetId.toLowerCase());
  if (nextWidgets.length === widgets.value.length) {
    return false;
  }

  widgets.value = nextWidgets;
  persistWidgets();
  if (widgetRefreshKeys.value[widgetId] !== undefined) {
    const nextRefreshKeys = { ...widgetRefreshKeys.value };
    delete nextRefreshKeys[widgetId];
    widgetRefreshKeys.value = nextRefreshKeys;
  }
  return true;
}

function refreshWidgetRuntime(widgetId: string): string | null {
  const target = widgets.value.find((widget) => widget.id.toLowerCase() === widgetId.toLowerCase());
  if (!target) {
    return null;
  }

  widgetRefreshKeys.value = {
    ...widgetRefreshKeys.value,
    [target.id]: (widgetRefreshKeys.value[target.id] ?? 0) + 1
  };
  return target.id;
}

function refreshAllWidgetRuntime(): void {
  widgetRefreshNonce.value += 1;
}

function widgetRuntimeSignature(widgetId: string): string {
  const widgetToken = widgetRefreshKeys.value[widgetId] ?? 0;
  return `${designSignature.value}:${widgetRefreshNonce.value}:${widgetToken}`;
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

  widgets.value = loadWidgets();

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

const readinessScore = computed(() => {
  const topicWeight = Math.min(30, focusTopics.value.length * 7);
  const postWeight = Math.min(30, posts.value.length * 5);
  const widgetWeight = Math.min(30, widgets.value.length * 6);
  const trackWeight = Math.min(10, scene.value.tracks.length * 3);
  return Math.min(100, topicWeight + postWeight + widgetWeight + trackWeight);
});

const dashboardStats = computed(() => [
  {
    label: 'Experience Readiness',
    value: `${readinessScore.value}%`,
    detail: readinessScore.value >= 80 ? 'High signal' : 'Building signal'
  },
  {
    label: 'Live Story Nodes',
    value: `${posts.value.length}`,
    detail: 'Pulled from runtime WP feed'
  },
  {
    label: 'Deployed Widgets',
    value: `${widgets.value.length}/${MAX_DASHBOARD_WIDGETS}`,
    detail: widgets.value.length > 0 ? widgets.value[0].title : 'No widgets yet'
  },
  {
    label: 'Mission Tracks',
    value: `${scene.value.tracks.length}`,
    detail: 'Action lanes available now'
  }
]);

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.push('/onboarding?force=1&reset=1');
}

function showWidgetListInTerminal(): void {
  if (widgets.value.length === 0) {
    addLine('system', 'No widgets deployed yet. Try /widget add weather Austin or say "build sports scores widget".');
    return;
  }

  addLine('signal', `Widgets active: ${widgets.value.length}/${MAX_DASHBOARD_WIDGETS}`);
  for (const widget of widgets.value.slice(0, 10)) {
    addLine('system', `${widget.id} · ${widget.type} · ${widget.title}`);
  }
}

function handleWidgetCommand(input: string): boolean {
  if (!input.startsWith('/widget')) {
    return false;
  }

  if (input === '/widget list') {
    showWidgetListInTerminal();
    return true;
  }

  if (input === '/widget clear') {
    widgets.value = [];
    widgetRefreshKeys.value = {};
    persistWidgets();
    addLine('signal', 'All deployed widgets cleared.');
    return true;
  }

  if (input === '/widget refresh' || input === '/widget refresh all') {
    if (widgets.value.length === 0) {
      addLine('system', 'No widgets to refresh yet.');
      return true;
    }

    refreshAllWidgetRuntime();
    addLine('signal', `Refreshing all widgets (${widgets.value.length}/${MAX_DASHBOARD_WIDGETS})...`);
    return true;
  }

  if (input.startsWith('/widget refresh ')) {
    const widgetId = input.slice('/widget refresh '.length).trim();
    if (!widgetId) {
      addLine('system', 'Usage: /widget refresh <widget-id|all>');
      return true;
    }

    if (widgetId.toLowerCase() === 'all') {
      refreshAllWidgetRuntime();
      addLine('signal', `Refreshing all widgets (${widgets.value.length}/${MAX_DASHBOARD_WIDGETS})...`);
      return true;
    }

    const refreshedWidgetId = refreshWidgetRuntime(widgetId);
    addLine(
      refreshedWidgetId ? 'signal' : 'system',
      refreshedWidgetId ? `Refreshing widget ${refreshedWidgetId}...` : `Widget ${widgetId} not found.`
    );
    return true;
  }

  if (input.startsWith('/widget remove ')) {
    const id = input.slice('/widget remove '.length).trim();
    if (!id) {
      addLine('system', 'Usage: /widget remove <widget-id>');
      return true;
    }

    const removed = removeWidgetById(id);
    addLine(removed ? 'signal' : 'system', removed ? `Removed widget ${id}.` : `Widget ${id} not found.`);
    return true;
  }

  if (input.startsWith('/widget add ')) {
    const payload = input.slice('/widget add '.length).trim();
    if (!payload) {
      addLine('system', 'Usage: /widget add <weather|horoscope|fashion|sports|customHtml> [hint]');
      return true;
    }

    const [rawType, ...rest] = payload.split(' ');
    const widgetType = parseWidgetType(rawType);
    if (!widgetType) {
      addLine('system', 'Widget types: weather, horoscope, fashion, sports, customHtml');
      return true;
    }

    const hint = rest.join(' ').trim();
    const widget = createPresetWidget(widgetType, `${visitorId}:${designSignature.value}:${sceneNonce.value}`, hint);
    deployWidget(widget);
    return true;
  }

  if (input.startsWith('/widget html ')) {
    const payload = input.slice('/widget html '.length).trim();
    const splitToken = '||';
    const splitIndex = payload.indexOf(splitToken);

    if (splitIndex === -1) {
      addLine('system', 'Usage: /widget html <title> || <html>');
      addLine('system', 'Example: /widget html Quick Card || <section><h4>Today</h4><p>Ship one feature.</p></section>');
      return true;
    }

    const title = payload.slice(0, splitIndex).trim() || 'Custom HTML Widget';
    const html = payload.slice(splitIndex + splitToken.length).trim();

    if (!html) {
      addLine('system', 'HTML content missing. Usage: /widget html <title> || <html>');
      return true;
    }

    const widget: DashboardWidget = {
      ...createPresetWidget('customHtml', `${visitorId}:${designSignature.value}:${sceneNonce.value}`, title),
      html: sanitizeWidgetHtml(html)
    };

    deployWidget(widget);
    return true;
  }

  addLine(
    'system',
    'Widget commands: /widget list, /widget add <type>, /widget html <title> || <html>, /widget refresh <id|all>, /widget remove <id>, /widget clear'
  );
  return true;
}

async function handleCommand(raw: string): Promise<void> {
  const input = raw.trim();
  if (!input) {
    return;
  }

  addLine('user', input);
  commandInput.value = '';

  if (input === '/help') {
    addLine('system', 'Core: /help, /shuffle, /focus <topic>, /open <1-3>, /reset');
    addLine(
      'system',
      'Widgets: /widget list, /widget add <type>, /widget html <title> || <html>, /widget refresh <id|all>, /widget remove <id>, /widget clear'
    );
    addLine('system', `Widget limit: ${MAX_DASHBOARD_WIDGETS} total. Tip: "create a weather widget for Chicago".`);
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

  if (handleWidgetCommand(input)) {
    return;
  }

  const assistant = handleNaturalLanguageWidgetRequest(
    input,
    `${visitorId}:${designSignature.value}:${sceneNonce.value}`,
    widgets.value.length
  );

  if (assistant.widget) {
    deployWidget(assistant.widget, 'Assistant');
  }

  if (assistant.action === 'list') {
    showWidgetListInTerminal();
  }

  addLine('system', assistant.reply);
}

function removeWidget(widgetId: string): void {
  const removed = removeWidgetById(widgetId);
  if (!removed) {
    return;
  }

  addLine('signal', `Widget ${widgetId} removed from dashboard.`);
}

onMounted(async () => {
  await initializePersonalization();

  if (!blueprint.value) {
    return;
  }

  addLine('system', `Visitor experience terminal active · Signature ${designSignature.value}`);
  addLine('system', scene.value.mission);
  addLine('signal', scene.value.pulse);
  addLine('system', 'You can now build your own dashboard with AI CLI commands. Type /help.');
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

    <section class="dashboard-shell">
      <article class="dashboard-card">
        <p class="mission-kicker">Visitor Dashboard</p>
        <div class="stats-grid">
          <section v-for="stat in dashboardStats" :key="stat.label" class="stat-card">
            <p class="stat-label">{{ stat.label }}</p>
            <h2 class="stat-value">{{ stat.value }}</h2>
            <p class="stat-detail">{{ stat.detail }}</p>
          </section>
        </div>
      </article>

      <AiPromptGame :signature="designSignature" :topics="focusTopics" />
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
          placeholder="Use AI CLI to build widgets (type /help)"
        />
      </form>
    </section>

    <section class="widget-studio">
      <header class="studio-head">
        <p class="mission-kicker">Widget Studio</p>
        <p class="studio-meta">Deployable widgets: {{ widgets.length }}/{{ MAX_DASHBOARD_WIDGETS }}</p>
      </header>

      <div v-if="widgets.length === 0" class="empty-widgets">
        <p>No widgets yet. Try:</p>
        <p>/widget add weather Austin</p>
        <p>/widget add horoscope</p>
        <p>/widget add sports NHL</p>
        <p>/widget html Daily Brief || &lt;section&gt;&lt;h4&gt;Daily Brief&lt;/h4&gt;&lt;p&gt;Write one clear prompt goal.&lt;/p&gt;&lt;/section&gt;</p>
        <p>Limit: {{ MAX_DASHBOARD_WIDGETS }} widgets total</p>
      </div>

      <article v-for="widget in widgets" :key="widget.id" class="widget-row">
        <div class="widget-head">
          <p class="widget-id">{{ widget.id }}</p>
          <button type="button" class="remove-widget" @click="removeWidget(widget.id)">Remove</button>
        </div>
        <h3>{{ widget.title }}</h3>
        <DashboardWidgetRenderer :widget="widget" :signature="widgetRuntimeSignature(widget.id)" />
      </article>
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

.dashboard-shell {
  margin-top: 0.85rem;
  display: grid;
  gap: 0.7rem;
}

.mission-card,
.prompt-card,
.dashboard-card {
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

.stats-grid {
  margin-top: 0.55rem;
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.stat-card {
  border: 1px solid #1f2937;
  border-radius: 10px;
  background: rgba(3, 7, 18, 0.84);
  padding: 0.58rem;
}

.stat-label {
  margin: 0;
  color: #67e8f9;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.stat-value {
  margin: 0.25rem 0 0;
  font-size: 1.1rem;
  line-height: 1.05;
}

.stat-detail {
  margin: 0.28rem 0 0;
  color: #a7f3d0;
  font-size: 0.8rem;
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

.widget-studio {
  margin-top: 0.85rem;
  border: 1px solid #1f2937;
  border-radius: 14px;
  background: rgba(2, 6, 23, 0.82);
  padding: 0.9rem;
  display: grid;
  gap: 0.68rem;
}

.studio-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.65rem;
}

.studio-meta {
  margin: 0;
  color: #86efac;
  font-size: 0.76rem;
}

.empty-widgets {
  border: 1px dashed #334155;
  border-radius: 10px;
  padding: 0.65rem;
}

.empty-widgets p {
  margin: 0.25rem 0 0;
  color: #a7f3d0;
  font-size: 0.82rem;
}

.widget-row {
  border: 1px solid #1f2937;
  border-radius: 10px;
  background: rgba(3, 7, 18, 0.84);
  padding: 0.66rem;
  display: grid;
  gap: 0.45rem;
}

.widget-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
}

.widget-id {
  margin: 0;
  color: #67e8f9;
  font-size: 0.74rem;
  letter-spacing: 0.07em;
}

.remove-widget {
  border: 1px solid #7f1d1d;
  border-radius: 999px;
  background: #450a0a;
  color: #fecaca;
  padding: 0.24rem 0.58rem;
  font-size: 0.72rem;
}

.widget-row h3 {
  margin: 0;
  font-size: 0.98rem;
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

  .dashboard-shell {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }

  .widget-studio {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .studio-head,
  .empty-widgets {
    grid-column: 1 / -1;
  }

  .tracks-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .content-stream {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
