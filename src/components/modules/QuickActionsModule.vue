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

function isUrlAction(action: string): boolean {
  return /^https?:\/\//i.test(action);
}

function runInlineAction(action: string): void {
  if (action === 'refresh-personalization') {
    window.location.reload();
  }
}
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <div class="actions-grid">
      <template v-for="action in actions" :key="action.action">
        <a
          v-if="isUrlAction(action.action)"
          class="action-btn"
          :href="action.action"
          target="_blank"
          rel="noopener noreferrer"
        >
          <span>{{ action.label }}</span>
          <small>{{ action.action }}</small>
        </a>
        <button v-else type="button" class="action-btn" @click="runInlineAction(action.action)">
          <span>{{ action.label }}</span>
          <small>{{ action.action }}</small>
        </button>
      </template>
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
  text-decoration: none;
}

small {
  color: var(--text-secondary);
}
</style>
