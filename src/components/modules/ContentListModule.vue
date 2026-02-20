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
        <img
          v-if="item.imageUrl && item.imageUrl.length > 0"
          class="thumb"
          :src="item.imageUrl"
          alt=""
          loading="lazy"
        />
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
  background:
    radial-gradient(circle at 100% -18%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 44%),
    var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.15rem;
  box-shadow: 0 14px 30px color-mix(in srgb, var(--accent) 10%, transparent);
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
  background:
    linear-gradient(
      155deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface-muted)),
      color-mix(in srgb, var(--surface-muted) 76%, var(--surface))
    );
  border: 1px solid color-mix(in srgb, var(--accent) 16%, var(--border));
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.thumb {
  width: 100%;
  max-height: 170px;
  object-fit: cover;
  border-radius: 10px;
  margin-bottom: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--accent) 20%, var(--border));
}

li:hover {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
  box-shadow: 0 10px 20px color-mix(in srgb, var(--accent) 14%, transparent);
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
  box-shadow: 0 8px 20px color-mix(in srgb, var(--accent) 8%, transparent);
}

.variant-neon li {
  border-width: 2px;
  background:
    linear-gradient(
      135deg,
      color-mix(in srgb, var(--accent) 14%, var(--surface)),
      color-mix(in srgb, var(--accent) 4%, var(--surface-muted))
    );
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 16%, transparent);
}

.variant-river li {
  border-left: 4px solid color-mix(in srgb, var(--accent) 62%, transparent);
  border-right: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
}
</style>
