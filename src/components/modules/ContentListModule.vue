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

const items = computed(() => {
  const content = props.content as { items?: ListItem[] };
  return Array.isArray(content.items) ? content.items : [];
});
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <ul>
      <li v-for="item in items" :key="item.title">
        <strong>{{ item.title }}</strong>
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
  padding: 1rem;
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
  border-radius: 10px;
  padding: 0.7rem;
  background: var(--surface-muted);
}

p {
  margin: 0.35rem 0 0;
  color: var(--text-secondary);
}
</style>
