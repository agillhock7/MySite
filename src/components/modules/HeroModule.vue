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

const heroImageUrl = computed(() => {
  const fromModule = props.moduleProps.heroImage;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  if (typeof heroContent.value.imageUrl === 'string' && heroContent.value.imageUrl.trim().length > 0) {
    return heroContent.value.imageUrl.trim();
  }

  return '';
});

const variant = computed(() => {
  const fromModule = props.moduleProps.variant;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  return 'default';
});

const ctaLabel = computed(() => {
  const fromModule = props.moduleProps.ctaLabel;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  return heroContent.value.ctaLabel;
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
  <section class="hero-module" :class="`variant-${variant}`">
    <div class="hero-copy">
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
          {{ ctaLabel }}
        </a>
        <button v-else type="button" class="primary-btn">{{ ctaLabel }}</button>
        <span class="shortcut-hint" v-if="shortcuts.length > 0">
          {{ shortcuts[0]?.label }}: {{ shortcuts[0]?.action }}
        </span>
      </div>
    </div>
    <figure v-if="heroImageUrl" class="hero-visual">
      <img :src="heroImageUrl" alt="" loading="lazy" />
    </figure>
  </section>
</template>

<style scoped>
.hero-module {
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 18%, white), var(--surface));
  border-radius: var(--radius);
  border: 1px solid var(--border);
  padding: 1.25rem;
  overflow: hidden;
  display: grid;
  gap: 1rem;
}

.hero-copy {
  min-width: 0;
}

.hero-visual {
  margin: 0;
  border-radius: calc(var(--radius) - 2px);
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  min-height: 180px;
}

.hero-visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.variant-spotlight {
  background:
    radial-gradient(circle at 90% 0%, color-mix(in srgb, var(--accent) 30%, transparent), transparent 38%),
    linear-gradient(135deg, color-mix(in srgb, var(--accent) 18%, white), var(--surface));
}

.variant-split {
  background:
    linear-gradient(
      100deg,
      color-mix(in srgb, var(--accent) 20%, transparent) 0%,
      color-mix(in srgb, var(--accent) 6%, transparent) 38%,
      var(--surface) 38%,
      var(--surface) 100%
    );
}

.variant-poster {
  background:
    radial-gradient(circle at 0% 0%, color-mix(in srgb, var(--accent) 30%, transparent), transparent 40%),
    radial-gradient(circle at 100% 80%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 38%),
    linear-gradient(135deg, color-mix(in srgb, var(--accent) 22%, var(--surface)), var(--surface));
  border-width: 2px;
}

.variant-frame {
  background: var(--surface);
  border-width: 2px;
  box-shadow: inset 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
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
  font-size: clamp(1.5rem, 5vw, 2.45rem);
  line-height: 1.08;
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

@media (min-width: 900px) {
  .hero-module {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
    align-items: stretch;
  }

  .hero-visual {
    min-height: 220px;
  }
}
</style>
