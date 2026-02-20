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
      <details v-for="(item, idx) in items" :key="item.question">
        <summary>
          <span class="index">{{ (idx + 1).toString().padStart(2, '0') }}</span>
          <span>{{ item.question }}</span>
        </summary>
        <p>{{ item.answer }}</p>
      </details>
    </div>
  </section>
</template>

<style scoped>
.module-card {
  background:
    radial-gradient(circle at 90% -26%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 44%),
    var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem;
  box-shadow: 0 12px 26px color-mix(in srgb, var(--accent) 9%, transparent);
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
  background:
    linear-gradient(
      145deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface-muted)),
      color-mix(in srgb, var(--surface-muted) 76%, var(--surface))
    );
  padding: 0.65rem;
  border: 1px solid color-mix(in srgb, var(--accent) 15%, var(--border));
  transition: border-color 160ms ease, box-shadow 160ms ease;
}

details[open] {
  border-color: color-mix(in srgb, var(--accent) 42%, var(--border));
  box-shadow: 0 10px 20px color-mix(in srgb, var(--accent) 14%, transparent);
}

summary {
  cursor: pointer;
  font-weight: 600;
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.55rem;
  align-items: center;
}

.index {
  font-size: 0.72rem;
  color: var(--text-secondary);
  letter-spacing: 0.06em;
}

p {
  margin: 0.45rem 0 0;
  color: var(--text-secondary);
}
</style>
