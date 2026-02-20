<script setup lang="ts">
import { computed } from 'vue';
import type { GridItem } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: unknown[];
}>();

const title = computed(() => {
  const value = props.moduleProps.title;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'Content Grid';
});

const intro = computed(() => {
  const value = props.moduleProps.intro;
  return typeof value === 'string' ? value : '';
});

const variant = computed(() => {
  const value = props.moduleProps.variant;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'default';
});

const gridColumnsClass = computed(() => {
  const value = props.moduleProps.columns;
  if (typeof value === 'number' && value >= 3) {
    return 'grid-cols-3';
  }

  if (typeof value === 'string' && value === '3') {
    return 'grid-cols-3';
  }

  return 'grid-cols-2';
});

const items = computed(() => {
  const content = props.content as { items?: GridItem[] };
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
    <div class="grid" :class="gridColumnsClass">
      <article v-for="item in visibleItems" :key="item.title" class="grid-item">
        <img
          v-if="item.imageUrl && item.imageUrl.length > 0"
          class="cover"
          :src="item.imageUrl"
          alt=""
          loading="lazy"
        />
        <h4>
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
        </h4>
        <p v-if="item.meta" class="meta">{{ item.meta }}</p>
        <p>{{ item.description }}</p>
      </article>
    </div>
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

.grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.grid-item {
  border-radius: 14px;
  background: color-mix(in srgb, var(--surface-muted) 70%, var(--surface));
  border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
  padding: 0.8rem;
  transition: transform 180ms ease, border-color 180ms ease, background 180ms ease;
}

.cover {
  width: 100%;
  height: 148px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--accent) 20%, var(--border));
  margin-bottom: 0.6rem;
}

.grid-item:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
}

h4 {
  margin: 0;
}

.item-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dashed color-mix(in srgb, var(--accent) 45%, transparent);
}

p {
  margin: 0.4rem 0 0;
  color: var(--text-secondary);
}

.meta {
  margin-top: 0.3rem;
  font-size: 0.75rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.intro {
  margin: 0.55rem 0 0;
  color: var(--text-secondary);
}

.variant-magazine {
  background: linear-gradient(
    140deg,
    color-mix(in srgb, var(--accent) 8%, var(--surface)),
    var(--surface)
  );
}

.variant-magazine .grid-item:first-child {
  grid-column: 1 / -1;
  padding: 1rem;
  border-width: 2px;
}

.variant-mosaic .grid-item:nth-child(odd) {
  transform: translateY(-2px);
}

.variant-mosaic .grid-item:nth-child(3n + 2) {
  transform: translateY(3px);
}

.variant-cards .grid-item {
  border-width: 2px;
  background: var(--surface);
}

.variant-cards .grid-item h4 {
  font-size: 1.05rem;
}

@media (min-width: 700px) {
  .grid.grid-cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .grid.grid-cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
