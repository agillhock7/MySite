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

function formatActionHint(action: string): string {
  if (!isUrlAction(action)) {
    return action;
  }

  try {
    const url = new URL(action);
    return `${url.host}${url.pathname === '/' ? '' : url.pathname}`;
  } catch {
    return action;
  }
}

function runInlineAction(action: string): void {
  if (action === 'reopen-onboarding') {
    window.location.assign('/onboarding?force=1');
    return;
  }

  if (action === 'refresh-personalization') {
    window.location.reload();
  }
}
</script>

<template>
  <section class="module-card">
    <h3>{{ title }}</h3>
    <div class="actions-grid">
      <template v-for="(action, idx) in actions" :key="action.action">
        <a
          v-if="isUrlAction(action.action)"
          class="action-btn"
          :href="action.action"
          target="_blank"
          rel="noopener noreferrer"
        >
          <em>{{ (idx + 1).toString().padStart(2, '0') }}</em>
          <span>{{ action.label }}</span>
          <small>{{ formatActionHint(action.action) }}</small>
          <strong class="arrow">↗</strong>
        </a>
        <button v-else type="button" class="action-btn" @click="runInlineAction(action.action)">
          <em>{{ (idx + 1).toString().padStart(2, '0') }}</em>
          <span>{{ action.label }}</span>
          <small>{{ formatActionHint(action.action) }}</small>
          <strong class="arrow">→</strong>
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
  gap: 0.22rem;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  text-decoration: none;
  transition: transform 160ms ease, border-color 160ms ease;
}

.action-btn:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--accent) 60%, var(--border));
}

em {
  font-style: normal;
  color: var(--text-secondary);
  font-size: 0.72rem;
  letter-spacing: 0.06em;
}

span {
  font-weight: 600;
}

small {
  grid-column: 2 / 4;
  color: var(--text-secondary);
}

.arrow {
  font-weight: 500;
  color: color-mix(in srgb, var(--accent) 84%, var(--text-primary));
}
</style>
