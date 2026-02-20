<script setup lang="ts">
import { computed } from 'vue';
import type { FaqItem } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: unknown[];
}>();

const title = computed(() => {
  const value = props.moduleProps.title;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'FAQ';
});

const items = computed(() => {
  const content = props.content as { items?: FaqItem[] };
  return Array.isArray(content.items) ? content.items : [];
});
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <div class="faq-list">
      <details v-for="item in items" :key="item.question">
        <summary>{{ item.question }}</summary>
        <p>{{ item.answer }}</p>
      </details>
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

.faq-list {
  margin-top: 0.75rem;
  display: grid;
  gap: 0.55rem;
}

details {
  border-radius: 10px;
  background: var(--surface-muted);
  padding: 0.65rem;
}

summary {
  cursor: pointer;
  font-weight: 600;
}

p {
  margin: 0.45rem 0 0;
  color: var(--text-secondary);
}
</style>
