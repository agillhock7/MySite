<script setup lang="ts">
import { computed } from 'vue';
import type { BlueprintShortcut } from '@/blueprint/schema';
import type { HeroContent } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: BlueprintShortcut[];
}>();

function asCleanString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

const heroContent = computed(() => props.content as HeroContent);

const brandName = computed(() => asCleanString(props.moduleProps.brandName));
const brandTagline = computed(() => asCleanString(props.moduleProps.brandTagline));
const brandIconUrl = computed(() => asCleanString(props.moduleProps.brandIconUrl));

const title = computed(() => {
  const fromModule = asCleanString(props.moduleProps.title);
  return fromModule || heroContent.value.title;
});

const kicker = computed(() => {
  const fromModule = asCleanString(props.moduleProps.kicker);
  return fromModule || 'Personalized Journey';
});

const subtitle = computed(() => {
  const fromModule = asCleanString(props.moduleProps.subtitle);
  return fromModule || heroContent.value.subtitle;
});

const heroImageUrl = computed(() => {
  const fromModule = asCleanString(props.moduleProps.heroImage);
  if (fromModule) {
    return fromModule;
  }

  const fromContent = asCleanString(heroContent.value.imageUrl);
  return fromContent;
});

const variant = computed(() => asCleanString(props.moduleProps.variant) || 'default');
const visualFx = computed(() => asCleanString(props.moduleProps.visualFx) || 'neon');
const textureFx = computed(() => asCleanString(props.moduleProps.textureFx) || 'glass');
const energyFx = computed(() => asCleanString(props.moduleProps.energyFx) || 'balanced');

const ctaLabel = computed(() => {
  const fromModule = asCleanString(props.moduleProps.ctaLabel);
  return fromModule || heroContent.value.ctaLabel;
});

const ctaUrl = computed(() => {
  const fromModule = asCleanString(props.moduleProps.ctaUrl);
  if (fromModule) {
    return fromModule;
  }

  return asCleanString(heroContent.value.ctaUrl);
});

const focusTopics = computed(() => {
  const value = props.moduleProps.focusTopics;
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .filter((item): item is string => typeof item === 'string')
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, 4);
});

const isExternalCta = computed(() => /^https?:\/\//i.test(ctaUrl.value));

const artSeed = computed(() => hashText(`${title.value}|${kicker.value}|${focusTopics.value.join('|')}`));
const artPoints = computed(() => {
  const first = (artSeed.value % 1000) / 1000;
  const second = ((artSeed.value >> 3) % 1000) / 1000;
  const third = ((artSeed.value >> 6) % 1000) / 1000;

  return {
    x1: `${12 + Math.round(first * 64)}%`,
    y1: `${18 + Math.round(second * 58)}%`,
    x2: `${38 + Math.round(second * 54)}%`,
    y2: `${6 + Math.round(third * 44)}%`,
    x3: `${58 + Math.round(third * 32)}%`,
    y3: `${42 + Math.round(first * 42)}%`
  };
});
</script>

<template>
  <section class="hero-module" :class="[`variant-${variant}`, `fx-${visualFx}`, `texture-${textureFx}`, `energy-${energyFx}`]">
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

      <div v-if="focusTopics.length > 0" class="topic-row">
        <span v-for="topic in focusTopics" :key="topic" class="topic-chip">{{ topic }}</span>
      </div>

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

    <figure class="hero-visual" :class="{ 'visual-fallback': !heroImageUrl }">
      <img v-if="heroImageUrl" :src="heroImageUrl" alt="" loading="lazy" />
      <div v-else class="hero-art" aria-hidden="true">
        <span class="art-orb orb-a" :style="{ left: artPoints.x1, top: artPoints.y1 }"></span>
        <span class="art-orb orb-b" :style="{ left: artPoints.x2, top: artPoints.y2 }"></span>
        <span class="art-orb orb-c" :style="{ left: artPoints.x3, top: artPoints.y3 }"></span>
        <span class="art-grid"></span>
        <span class="art-ring ring-a"></span>
        <span class="art-ring ring-b"></span>
      </div>
    </figure>
  </section>
</template>

<style scoped>
.hero-module {
  position: relative;
  display: grid;
  gap: 1rem;
  border-radius: calc(var(--radius) + 6px);
  border: 1px solid color-mix(in srgb, var(--accent) 34%, var(--border));
  padding: clamp(1rem, 2.3vw, 1.45rem);
  overflow: hidden;
  background:
    radial-gradient(circle at 92% -12%, color-mix(in srgb, var(--accent) 26%, transparent), transparent 42%),
    linear-gradient(132deg, color-mix(in srgb, var(--accent) 14%, var(--surface)), var(--surface));
  box-shadow:
    0 0 0 1px color-mix(in srgb, var(--accent) 14%, transparent),
    0 20px 45px color-mix(in srgb, var(--accent) 18%, transparent);
}

.hero-copy {
  min-width: 0;
  position: relative;
  z-index: 2;
}

.brand-lockup {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0.52rem;
}

.brand-lockup img {
  width: 25px;
  height: 25px;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 45%, var(--border));
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
  color: color-mix(in srgb, var(--accent) 80%, var(--text-secondary));
}

.eyebrow {
  margin: 0;
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: color-mix(in srgb, var(--accent) 80%, var(--text-secondary));
}

h2 {
  margin: 0.36rem 0 0;
  font-size: clamp(1.5rem, 4.2vw, 2.7rem);
  line-height: 1.04;
  text-wrap: balance;
}

.subtitle {
  margin: 0.62rem 0 0;
  color: var(--text-secondary);
  max-width: 60ch;
}

.topic-row {
  margin-top: 0.68rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.38rem;
}

.topic-chip {
  font-size: 0.7rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--border));
  background: color-mix(in srgb, var(--accent) 14%, var(--surface));
  padding: 0.24rem 0.58rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.actions {
  margin-top: 0.95rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.55rem;
}

.primary-btn {
  border: 1px solid color-mix(in srgb, var(--accent) 44%, var(--border));
  border-radius: 999px;
  background:
    linear-gradient(
      138deg,
      color-mix(in srgb, var(--accent) 75%, #ffffff),
      color-mix(in srgb, var(--accent) 52%, #121826)
    );
  color: #ffffff;
  font-weight: 700;
  text-decoration: none;
  padding: 0.56rem 0.98rem;
  box-shadow: 0 10px 24px color-mix(in srgb, var(--accent) 26%, transparent);
}

.shortcut-hint {
  font-size: 0.78rem;
  color: var(--text-secondary);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hero-visual {
  margin: 0;
  border-radius: calc(var(--radius) + 2px);
  border: 1px solid color-mix(in srgb, var(--accent) 34%, var(--border));
  overflow: hidden;
  min-height: 230px;
  position: relative;
}

.hero-visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.hero-art {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 18% 0%, color-mix(in srgb, var(--accent) 34%, transparent), transparent 40%),
    linear-gradient(150deg, color-mix(in srgb, var(--accent) 25%, #0b1020), #0a1224 55%, #0c152d 100%);
}

.art-grid {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(transparent 95%, rgba(255, 255, 255, 0.09) 100%),
    linear-gradient(90deg, transparent 95%, rgba(255, 255, 255, 0.08) 100%);
  background-size: 100% 28px, 28px 100%;
  opacity: 0.44;
}

.art-orb {
  position: absolute;
  width: 44%;
  aspect-ratio: 1;
  border-radius: 999px;
  background: color-mix(in srgb, var(--accent) 52%, transparent);
  filter: blur(24px);
  opacity: 0.5;
  transform: translate(-50%, -50%);
  animation: drift 14s ease-in-out infinite alternate;
}

.orb-b {
  width: 32%;
  animation-duration: 18s;
}

.orb-c {
  width: 24%;
  animation-duration: 21s;
}

.art-ring {
  position: absolute;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 44%, rgba(255, 255, 255, 0.3));
  opacity: 0.6;
}

.ring-a {
  width: 68%;
  height: 68%;
  left: 8%;
  top: 12%;
}

.ring-b {
  width: 36%;
  height: 36%;
  right: 10%;
  bottom: 12%;
}

.variant-poster {
  border-width: 2px;
}

.variant-frame {
  background: var(--surface);
  box-shadow:
    inset 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent),
    0 20px 45px color-mix(in srgb, var(--accent) 14%, transparent);
}

.variant-neon,
.variant-holo,
.variant-spotlight {
  border-width: 2px;
}

.variant-holo {
  background:
    linear-gradient(
      118deg,
      color-mix(in srgb, var(--accent) 20%, transparent) 0%,
      transparent 28%,
      color-mix(in srgb, var(--accent) 10%, transparent) 28%,
      transparent 58%,
      color-mix(in srgb, var(--accent) 22%, transparent) 58%,
      transparent 100%
    ),
    var(--surface);
}

.fx-matrix .art-grid {
  opacity: 0.6;
}

.fx-prism .art-orb {
  border-radius: 34% 66% 58% 42%;
}

.energy-high .art-orb {
  animation-duration: 9s;
}

.energy-low .art-orb {
  animation-duration: 22s;
  opacity: 0.32;
}

@keyframes drift {
  from {
    transform: translate(-50%, -50%) scale(0.96);
  }
  to {
    transform: translate(-50%, -50%) scale(1.08);
  }
}

@media (min-width: 860px) {
  .hero-module {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
    align-items: stretch;
  }

  .hero-visual {
    min-height: 100%;
  }
}
</style>
