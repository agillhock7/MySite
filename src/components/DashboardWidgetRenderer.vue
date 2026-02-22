<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { DashboardWidget } from '@/dashboard/engine';
import { refreshWidgetRuntime, type WidgetRuntimePayload } from '@/api/widgetRuntime';

const props = defineProps<{
  widget: DashboardWidget;
  signature: string;
}>();

const loading = ref(false);
const error = ref('');
const runtime = ref<WidgetRuntimePayload | null>(null);
const autoRefreshEnabled = ref(false);
const autoRefreshCountdown = ref(0);
let autoRefreshTimer: number | null = null;

const AUTO_REFRESH_SECONDS = 45;

const refreshedAtLabel = computed(() => {
  if (!runtime.value?.refreshedAt) {
    return '';
  }

  const parsed = new Date(runtime.value.refreshedAt);
  if (Number.isNaN(parsed.getTime())) {
    return runtime.value.refreshedAt;
  }

  return parsed.toLocaleTimeString(undefined, {
    hour: '2-digit',
    minute: '2-digit'
  });
});

const sourceLabel = computed(() => {
  if (!runtime.value) {
    return '';
  }

  if (runtime.value.source === 'ai') {
    return 'AI';
  }

  if (runtime.value.source === 'external') {
    return 'External Data';
  }

  return 'Fallback';
});

const weatherPayload = computed(() => runtime.value?.payload ?? {});
const listPayload = computed(() => {
  const value = runtime.value?.payload.items;
  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter((item): item is string => typeof item === 'string').slice(0, 5);
});

const textPayload = computed(() => {
  const value = runtime.value?.payload.text;
  return typeof value === 'string' ? value : '';
});

const promptPayload = computed(() => {
  const payload = runtime.value?.payload ?? {};
  const prompt = typeof payload.prompt === 'string' ? payload.prompt : '';
  const response = typeof payload.response === 'string' ? payload.response : '';
  const mode = typeof payload.mode === 'string' ? payload.mode : '';
  const capability = typeof payload.capability === 'string' ? payload.capability : 'generic';
  const actions = Array.isArray(payload.items)
    ? payload.items.filter((item): item is string => typeof item === 'string').slice(0, 4)
    : [];
  const facts =
    payload.facts && typeof payload.facts === 'object' && !Array.isArray(payload.facts)
      ? (payload.facts as Record<string, unknown>)
      : {};

  return {
    mode,
    capability,
    prompt,
    response,
    actions,
    facts
  };
});

const protocolLabel = computed(() => {
  if (promptPayload.value.mode !== 'prompt') {
    return '';
  }

  const capability = promptPayload.value.capability;
  if (capability === 'time') {
    return 'Protocol: Time Runtime';
  }

  if (capability === 'weather') {
    return 'Protocol: Weather Runtime';
  }

  if (capability === 'crypto') {
    return 'Protocol: Market Runtime';
  }

  return 'Protocol: AI Prompt';
});

const promptFacts = computed(() => {
  if (promptPayload.value.mode !== 'prompt') {
    return [];
  }

  const entries = Object.entries(promptPayload.value.facts)
    .filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value))
    .slice(0, 6)
    .map(([key, value]) => {
      const normalizedKey = key
        .replace(/([A-Z])/g, ' $1')
        .replace(/_/g, ' ')
        .trim()
        .replace(/^./, (char) => char.toUpperCase());
      return {
        key,
        label: normalizedKey,
        value: String(value)
      };
    });

  return entries;
});

const htmlPayload = computed(() => {
  const value = runtime.value?.payload.html;
  return typeof value === 'string' ? value : '<p>No widget output yet.</p>';
});

async function refresh(): Promise<void> {
  loading.value = true;
  error.value = '';

  const payload = await refreshWidgetRuntime(props.widget, props.signature);

  loading.value = false;

  if (!payload) {
    error.value = 'Refresh failed. Try again.';
    return;
  }

  runtime.value = payload;
  autoRefreshCountdown.value = AUTO_REFRESH_SECONDS;
}

function stopAutoRefresh(): void {
  if (autoRefreshTimer !== null) {
    window.clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
}

function startAutoRefresh(): void {
  stopAutoRefresh();
  autoRefreshCountdown.value = AUTO_REFRESH_SECONDS;
  autoRefreshTimer = window.setInterval(async () => {
    if (!autoRefreshEnabled.value) {
      return;
    }

    if (loading.value) {
      return;
    }

    autoRefreshCountdown.value -= 1;
    if (autoRefreshCountdown.value <= 0) {
      await refresh();
    }
  }, 1000);
}

function toggleAutoRefresh(): void {
  autoRefreshEnabled.value = !autoRefreshEnabled.value;
}

onMounted(async () => {
  await refresh();
});

onBeforeUnmount(() => {
  stopAutoRefresh();
});

watch(autoRefreshEnabled, (enabled) => {
  if (enabled) {
    startAutoRefresh();
    return;
  }

  stopAutoRefresh();
  autoRefreshCountdown.value = 0;
});

watch(
  () => [props.widget.id, props.widget.title, props.widget.type, JSON.stringify(props.widget.config), props.signature],
  async () => {
    autoRefreshCountdown.value = AUTO_REFRESH_SECONDS;
    await refresh();
  }
);
</script>

<template>
  <section class="widget-shell" :class="`type-${widget.type}`">
    <header class="widget-topbar">
      <p class="widget-type">{{ widget.type }}</p>
      <div class="meta-right">
        <span v-if="refreshedAtLabel" class="refresh-meta">{{ sourceLabel }} · {{ refreshedAtLabel }}</span>
        <button type="button" class="refresh-btn ghost" @click="toggleAutoRefresh">
          {{ autoRefreshEnabled ? `Auto ${autoRefreshCountdown}s` : 'Auto' }}
        </button>
        <button type="button" class="refresh-btn" :disabled="loading" @click="refresh">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
      </div>
    </header>

    <p v-if="error" class="error-line">{{ error }}</p>

    <template v-else-if="widget.type === 'weather'">
      <p class="primary">{{ (weatherPayload.city as string) || widget.config.city || 'City' }}</p>
      <p class="secondary">
        {{ weatherPayload.temperatureC ?? '--' }}°C · feels {{ weatherPayload.feelsLikeC ?? '--' }}°C · wind {{ weatherPayload.windKph ?? '--' }} kph
      </p>
      <p class="secondary">{{ (weatherPayload.condition as string) || 'Condition unavailable' }}</p>
      <p class="secondary">{{ (weatherPayload.insight as string) || '' }}</p>
    </template>

    <template v-else-if="widget.type === 'horoscope'">
      <p class="primary">{{ (runtime?.payload.sign as string) || widget.config.sign || 'aries' }}</p>
      <p class="secondary">{{ textPayload }}</p>
    </template>

    <template v-else-if="widget.type === 'sports'">
      <p class="primary">{{ (runtime?.payload.league as string) || widget.config.league || 'NHL' }}</p>
      <ul>
        <li v-for="item in listPayload" :key="item">{{ item }}</li>
      </ul>
      <p class="secondary">{{ textPayload }}</p>
    </template>

    <template v-else-if="widget.type === 'fashion'">
      <p class="primary">{{ (runtime?.payload.mood as string) || widget.config.mood || 'style' }}</p>
      <ul>
        <li v-for="item in listPayload" :key="item">{{ item }}</li>
      </ul>
      <p class="secondary">{{ textPayload }}</p>
    </template>

    <template v-else>
      <template v-if="promptPayload.mode === 'prompt'">
        <p class="protocol">{{ protocolLabel }}</p>
        <p v-if="promptPayload.prompt" class="secondary"><strong>Prompt:</strong> {{ promptPayload.prompt }}</p>
        <p class="primary">{{ promptPayload.response || textPayload || 'No response yet.' }}</p>
        <div v-if="promptFacts.length > 0" class="facts-grid">
          <p v-for="fact in promptFacts" :key="fact.key" class="fact">
            <span>{{ fact.label }}</span>
            <strong>{{ fact.value }}</strong>
          </p>
        </div>
        <ul v-if="promptPayload.actions.length > 0">
          <li v-for="item in promptPayload.actions" :key="item">{{ item }}</li>
        </ul>
      </template>

      <div v-else class="html-preview" v-html="htmlPayload"></div>
      <p v-if="textPayload" class="secondary">{{ textPayload }}</p>
    </template>
  </section>
</template>

<style scoped>
.widget-shell {
  border: 1px solid rgba(var(--accent-rgb, 22, 199, 207), 0.36);
  border-radius: 10px;
  background: linear-gradient(
    155deg,
    rgba(var(--accent-rgb, 22, 199, 207), 0.12),
    rgba(2, 6, 23, 0.78) 42%,
    rgba(2, 6, 23, 0.9)
  );
  padding: 0.68rem;
  backdrop-filter: blur(12px);
}

.widget-topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.45rem;
}

.meta-right {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.widget-type {
  margin: 0;
  color: rgb(var(--accent-soft-rgb, 120, 224, 228));
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.68rem;
}

.refresh-meta {
  color: var(--text-secondary, #86efac);
  font-size: 0.68rem;
}

.refresh-btn {
  border: 1px solid rgba(var(--accent-rgb, 22, 199, 207), 0.48);
  border-radius: 999px;
  background: rgba(var(--accent-rgb, 22, 199, 207), 0.16);
  color: var(--text-primary, #99f6e4);
  padding: 0.2rem 0.55rem;
  font-size: 0.72rem;
}

.refresh-btn.ghost {
  background: rgba(var(--accent-rgb, 22, 199, 207), 0.08);
  border-color: rgba(var(--accent-rgb, 22, 199, 207), 0.35);
  color: rgb(var(--accent-soft-rgb, 120, 224, 228));
}

.refresh-btn:disabled {
  opacity: 0.55;
}

.primary {
  margin: 0.34rem 0 0;
  color: var(--text-primary, #d1fae5);
  font-size: 0.92rem;
}

.secondary {
  margin: 0.36rem 0 0;
  color: var(--text-secondary, #a7f3d0);
}

.protocol {
  margin: 0.34rem 0 0;
  color: rgb(var(--accent-soft-rgb, 120, 224, 228));
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.error-line {
  margin: 0.4rem 0 0;
  color: #fca5a5;
}

ul {
  margin: 0.4rem 0 0;
  padding-left: 1rem;
  color: var(--text-secondary, #a7f3d0);
  display: grid;
  gap: 0.24rem;
}

.facts-grid {
  margin-top: 0.45rem;
  display: grid;
  gap: 0.38rem;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
}

.fact {
  margin: 0;
  border: 1px solid rgba(var(--accent-rgb, 22, 199, 207), 0.38);
  border-radius: 8px;
  padding: 0.34rem 0.42rem;
  background: rgba(var(--accent-rgb, 22, 199, 207), 0.14);
  display: grid;
  gap: 0.2rem;
}

.fact span {
  font-size: 0.68rem;
  color: rgb(var(--accent-soft-rgb, 120, 224, 228));
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.fact strong {
  font-size: 0.8rem;
  color: var(--text-primary, #d1fae5);
}

.html-preview :deep(h1),
.html-preview :deep(h2),
.html-preview :deep(h3),
.html-preview :deep(h4),
.html-preview :deep(p),
.html-preview :deep(li) {
  margin: 0.3rem 0 0;
  color: var(--text-secondary, #bbf7d0);
}

.html-preview :deep(ul) {
  padding-left: 1rem;
}

.type-customHtml {
  border-color: rgba(var(--accent-rgb, 22, 199, 207), 0.5);
}
</style>
