<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { DashboardWidget } from '@/dashboard/engine';
import { refreshWidgetRuntime, type WidgetRuntimePayload } from '@/api/widgetRuntime';

const props = defineProps<{
  widget: DashboardWidget;
  signature: string;
}>();

const loading = ref(false);
const error = ref('');
const runtime = ref<WidgetRuntimePayload | null>(null);

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
}

onMounted(async () => {
  await refresh();
});

watch(
  () => [props.widget.id, props.widget.title, props.widget.type, JSON.stringify(props.widget.config), props.signature],
  async () => {
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
      <div class="html-preview" v-html="htmlPayload"></div>
      <p class="secondary">{{ textPayload }}</p>
    </template>
  </section>
</template>

<style scoped>
.widget-shell {
  border: 1px solid #1f2937;
  border-radius: 10px;
  background: rgba(3, 7, 18, 0.84);
  padding: 0.68rem;
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
  color: #67e8f9;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.68rem;
}

.refresh-meta {
  color: #86efac;
  font-size: 0.68rem;
}

.refresh-btn {
  border: 1px solid #134e4a;
  border-radius: 999px;
  background: #022c22;
  color: #99f6e4;
  padding: 0.2rem 0.55rem;
  font-size: 0.72rem;
}

.refresh-btn:disabled {
  opacity: 0.55;
}

.primary {
  margin: 0.34rem 0 0;
  color: #d1fae5;
  font-size: 0.92rem;
}

.secondary {
  margin: 0.36rem 0 0;
  color: #a7f3d0;
}

.error-line {
  margin: 0.4rem 0 0;
  color: #fca5a5;
}

ul {
  margin: 0.4rem 0 0;
  padding-left: 1rem;
  color: #a7f3d0;
  display: grid;
  gap: 0.24rem;
}

.html-preview :deep(h1),
.html-preview :deep(h2),
.html-preview :deep(h3),
.html-preview :deep(h4),
.html-preview :deep(p),
.html-preview :deep(li) {
  margin: 0.3rem 0 0;
  color: #bbf7d0;
}

.html-preview :deep(ul) {
  padding-left: 1rem;
}

.type-customHtml {
  border-color: #0f766e;
}
</style>
