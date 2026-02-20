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
const brandName = computed(() => {
  const fromModule = props.moduleProps.brandName;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  return '';
});

const brandTagline = computed(() => {
  const fromModule = props.moduleProps.brandTagline;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  return '';
});

const brandIconUrl = computed(() => {
  const fromModule = props.moduleProps.brandIconUrl;
  if (typeof fromModule === 'string' && fromModule.trim().length > 0) {
    return fromModule.trim();
  }

  return '';
});
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
      <div v-if="brandName || brandTagline" class="brand-lockup">
        <img v-if="brandIconUrl" :src="brandIconUrl" alt="" loading="lazy" />
        <p>
          <strong v-if="brandName">{{ brandName }}</strong>
          <span v-if="brandTagline">{{ brandTagline }}</span>
        </p>
      </div>
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
  background:
    radial-gradient(circle at 88% -10%, color-mix(in srgb, var(--accent) 24%, transparent), transparent 44%),
    linear-gradient(135deg, color-mix(in srgb, var(--accent) 14%, white), var(--surface));
  border-radius: var(--radius);
  border: 1px solid var(--border);
  padding: 1.25rem;
  overflow: hidden;
  display: grid;
  gap: 1rem;
  box-shadow: 0 18px 38px color-mix(in srgb, var(--accent) 12%, transparent);
}

.hero-copy {
  min-width: 0;
}

.brand-lockup {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.brand-lockup img {
  width: 24px;
  height: 24px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--border));
}

.brand-lockup p {
  margin: 0;
  display: grid;
  line-height: 1.03;
}

.brand-lockup strong {
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.brand-lockup span {
  font-size: 0.72rem;
  color: color-mix(in srgb, var(--accent) 78%, var(--text-secondary));
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

.variant-neon {
  background:
    radial-gradient(circle at 18% -5%, color-mix(in srgb, var(--accent) 34%, transparent), transparent 45%),
    radial-gradient(circle at 100% 100%, color-mix(in srgb, var(--accent) 20%, transparent), transparent 48%),
    linear-gradient(130deg, color-mix(in srgb, var(--accent) 12%, var(--surface)), var(--surface));
  border-width: 2px;
  box-shadow:
    0 0 0 1px color-mix(in srgb, var(--accent) 32%, transparent),
    0 16px 34px color-mix(in srgb, var(--accent) 20%, transparent);
}

.variant-holo {
  background:
    linear-gradient(
      115deg,
      color-mix(in srgb, var(--accent) 24%, transparent) 0%,
      transparent 34%,
      color-mix(in srgb, var(--accent) 14%, transparent) 34%,
      transparent 64%,
      color-mix(in srgb, var(--accent) 20%, transparent) 64%,
      transparent 100%
    ),
    linear-gradient(135deg, color-mix(in srgb, var(--accent) 14%, white), var(--surface));
  border-width: 2px;
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
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 65%, #ffffff));
  color: #ffffff;
  text-decoration: none;
  box-shadow: 0 8px 18px color-mix(in srgb, var(--accent) 30%, transparent);
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
