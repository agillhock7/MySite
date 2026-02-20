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
</script>

<template>
  <section class="module-card" :class="`variant-${variant}`">
    <h3>{{ title }}</h3>
    <p v-if="intro" class="intro">{{ intro }}</p>
    <div class="grid" :class="gridColumnsClass">
      <article v-for="item in items" :key="item.title" class="grid-item">
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

@media (min-width: 700px) {
  .grid.grid-cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .grid.grid-cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
