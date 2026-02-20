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

const items = computed(() => {
  const content = props.content as { items?: GridItem[] };
  return Array.isArray(content.items) ? content.items : [];
});
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <div class="grid">
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
  padding: 1rem;
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
  border-radius: 12px;
  background: var(--surface-muted);
  padding: 0.75rem;
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

@media (min-width: 700px) {
  .grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
