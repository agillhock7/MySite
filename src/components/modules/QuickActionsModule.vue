<script setup lang="ts">
import { computed } from 'vue';
import type { ActionItem } from '@/content/library';
import type { BlueprintShortcut } from '@/blueprint/schema';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: BlueprintShortcut[];
}>();

const title = computed(() => {
  const value = props.moduleProps.title;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'Quick Actions';
});

const actions = computed(() => {
  const contentActions = (props.content as { actions?: ActionItem[] }).actions;
  if (Array.isArray(contentActions) && contentActions.length > 0) {
    return contentActions;
  }

  return props.shortcuts.map((shortcut) => ({ label: shortcut.label, action: shortcut.action }));
});
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <div class="actions-grid">
      <button v-for="action in actions" :key="action.action" type="button" class="action-btn">
        <span>{{ action.label }}</span>
        <small>{{ action.action }}</small>
      </button>
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

.actions-grid {
  margin-top: 0.75rem;
  display: grid;
  gap: 0.65rem;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
}

.action-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border));
  border-radius: 10px;
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
  color: inherit;
  text-align: left;
  padding: 0.65rem 0.75rem;
  display: grid;
  gap: 0.2rem;
}

small {
  color: var(--text-secondary);
}
</style>
