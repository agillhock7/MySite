<script setup lang="ts">
import { computed } from 'vue';
import type { BlueprintShortcut } from '@/blueprint/schema';
import type { HeroContent } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: BlueprintShortcut[];
}>();

const heroContent = computed(() => props.content as HeroContent);
const title = computed(() => {
  const fromModule = props.moduleProps.title;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule;
  }

  return heroContent.value.title;
});

const kicker = computed(() => {
  const fromModule = props.moduleProps.kicker;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule;
  }

  return 'Personalized Journey';
});

const subtitle = computed(() => {
  const fromModule = props.moduleProps.subtitle;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule;
  }

  return heroContent.value.subtitle;
});

const ctaUrl = computed(() => {
  const fromModule = props.moduleProps.ctaUrl;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule;
  }

  if (typeof heroContent.value.ctaUrl === 'string' && heroContent.value.ctaUrl.trim().length > 0) {
    return heroContent.value.ctaUrl;
  }

  return '';
});

const isExternalCta = computed(() => /^https?:\/\//i.test(ctaUrl.value));
</script>

<template>
  <section class="hero-module">
    <p class="eyebrow">{{ kicker }}</p>
    <h2>{{ title }}</h2>
    <p class="subtitle">{{ subtitle }}</p>
    <div class="actions">
      <a
        v-if="ctaUrl"
        class="primary-btn"
        :href="ctaUrl"
        :target="isExternalCta ? '_blank' : '_self'"
        :rel="isExternalCta ? 'noopener noreferrer' : undefined"
      >
        {{ heroContent.ctaLabel }}
      </a>
      <button v-else type="button" class="primary-btn">{{ heroContent.ctaLabel }}</button>
      <span class="shortcut-hint" v-if="shortcuts.length > 0">
        {{ shortcuts[0]?.label }}: {{ shortcuts[0]?.action }}
      </span>
    </div>
  </section>
</template>

<style scoped>
.hero-module {
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 18%, white), var(--surface));
  border-radius: var(--radius);
  border: 1px solid var(--border);
  padding: 1.25rem;
}

.eyebrow {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.75rem;
  color: var(--text-secondary);
}

h2 {
  margin: 0.5rem 0;
  font-size: clamp(1.3rem, 4.4vw, 2rem);
}

.subtitle {
  margin: 0;
  color: var(--text-secondary);
}

.actions {
  margin-top: 1rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  align-items: center;
}

.primary-btn {
  display: inline-flex;
  align-items: center;
  border: 0;
  border-radius: 999px;
  padding: 0.55rem 0.95rem;
  background: var(--accent);
  color: #ffffff;
  text-decoration: none;
}

.shortcut-hint {
  color: var(--text-secondary);
  font-size: 0.9rem;
}
</style>
