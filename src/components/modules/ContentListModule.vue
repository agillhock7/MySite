<script setup lang="ts">
import { computed } from 'vue';
import type { ListItem } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: unknown[];
}>();

const title = computed(() => {
  const value = props.moduleProps.title;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'Content List';
});

const intro = computed(() => {
  const value = props.moduleProps.intro;
  return typeof value === 'string' ? value : '';
});

const variant = computed(() => {
  const value = props.moduleProps.variant;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'default';
});

const items = computed(() => {
  const content = props.content as { items?: ListItem[] };
  return Array.isArray(content.items) ? content.items : [];
});

function parseNumberProp(value: unknown, fallback: number): number {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.max(0, Math.floor(value));
  }

  if (typeof value === 'string' && value.trim().length > 0) {
    const parsed = Number.parseInt(value, 10);
    if (Number.isFinite(parsed)) {
      return Math.max(0, parsed);
    }
  }

  return fallback;
}

const visibleItems = computed(() => {
  const offset = parseNumberProp(props.moduleProps.offset, 0);
  const limit = parseNumberProp(props.moduleProps.limit, items.value.length || 6);

  return items.value.slice(offset, offset + Math.max(1, limit));
});
</script>

<template>
  <section class="module-card" :class="`variant-${variant}`">
    <h3>{{ title }}</h3>
    <p v-if="intro" class="intro">{{ intro }}</p>
    <ul>
      <li v-for="item in visibleItems" :key="item.title">
        <strong>
          <a
            v-if="item.href && item.href.length > 0"
            :href="item.href"
            target="_blank"
            rel="noopener noreferrer"
            class="item-link"
          >
            {{ item.title }}
          </a>
          <template v-else>{{ item.title }}</template>
        </strong>
        <p>{{ item.detail }}</p>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.module-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.15rem;
}

h3 {
  margin: 0;
}

ul {
  margin: 0.8rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.65rem;
}

li {
  border-radius: 12px;
  padding: 0.78rem;
  background: color-mix(in srgb, var(--surface-muted) 72%, var(--surface));
  border: 1px solid color-mix(in srgb, var(--accent) 16%, var(--border));
}

p {
  margin: 0.35rem 0 0;
  color: var(--text-secondary);
}

.item-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dashed color-mix(in srgb, var(--accent) 45%, transparent);
}

.intro {
  margin: 0.55rem 0 0;
  color: var(--text-secondary);
}

.variant-timeline li {
  border-left: 3px solid color-mix(in srgb, var(--accent) 55%, transparent);
}

.variant-checklist li {
  position: relative;
  padding-left: 1.5rem;
}

.variant-checklist li::before {
  content: '';
  position: absolute;
  left: 0.55rem;
  top: 0.98rem;
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: var(--accent);
}

.variant-stacked li {
  border-width: 2px;
  background: var(--surface);
}
</style>
