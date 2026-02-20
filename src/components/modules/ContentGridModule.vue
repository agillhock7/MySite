<script setup lang="ts">
import { computed } from 'vue';
import type { GridItem } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: unknown[];
}>();

function asCleanString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function parseNumberProp(value: unknown, fallback: number): number {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return Math.max(0, Math.floor(value));
  }

  if (typeof value === 'string' && value.trim().length > 0) {
    const parsed = Number.parseInt(value, 10);
    if (Number.isFinite(parsed)) {
      return Math.max(0, parsed);
    }
  }

  return fallback;
}

function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

const title = computed(() => asCleanString(props.moduleProps.title) || 'Content Grid');
const intro = computed(() => asCleanString(props.moduleProps.intro));
const variant = computed(() => asCleanString(props.moduleProps.variant) || 'default');
const visualFx = computed(() => asCleanString(props.moduleProps.visualFx) || 'neon');

const gridColumnsClass = computed(() => {
  const value = props.moduleProps.columns;
  if (typeof value === 'number' && value >= 3) {
    return 'grid-cols-3';
  }

  if (typeof value === 'string' && value === '3') {
    return 'grid-cols-3';
  }

  return 'grid-cols-2';
});

const items = computed(() => {
  const content = props.content as { items?: GridItem[] };
  return Array.isArray(content.items) ? content.items : [];
});

const visibleItems = computed(() => {
  const offset = parseNumberProp(props.moduleProps.offset, 0);
  const limit = parseNumberProp(props.moduleProps.limit, items.value.length || 6);

  return items.value.slice(offset, offset + Math.max(1, limit));
});

const gridSeed = computed(() => hashText(`${title.value}|${variant.value}|${visibleItems.value.length}`));

function isExternalHref(href: string | undefined): boolean {
  if (!href) {
    return false;
  }

  return /^https?:\/\//i.test(href);
}

function tileClass(index: number): string[] {
  const classes = [`tile-${(index % 6) + 1}`];

  if (index === 0) {
    classes.push('tile-lead');
  }

  if ((gridSeed.value + index) % 3 === 0) {
    classes.push('tile-lift');
  }

  return classes;
}

function initials(input: string): string {
  const trimmed = input.trim();
  if (!trimmed) {
    return 'AG';
  }

  const words = trimmed.split(/\s+/).slice(0, 2);
  return words.map((word) => word.charAt(0).toUpperCase()).join('');
}
</script>

<template>
  <section class="module-card" :class="[`variant-${variant}`, `fx-${visualFx}`]">
    <div class="card-head">
      <h3>{{ title }}</h3>
      <p v-if="intro" class="intro">{{ intro }}</p>
    </div>

    <div class="grid" :class="gridColumnsClass">
      <article
        v-for="(item, idx) in visibleItems"
        :key="item.id ?? `${item.title}-${idx}`"
        class="grid-item"
        :class="tileClass(idx)"
        :style="{ '--stagger': `${idx * 70}ms` }"
      >
        <a
          v-if="item.href && item.href.length > 0"
          class="tile-link-wrap"
          :href="item.href"
          :target="isExternalHref(item.href) ? '_blank' : '_self'"
          :rel="isExternalHref(item.href) ? 'noopener noreferrer' : undefined"
        >
          <div class="cover-wrap">
            <img v-if="item.imageUrl && item.imageUrl.length > 0" class="cover" :src="item.imageUrl" alt="" loading="lazy" />
            <div v-else class="cover-fallback" aria-hidden="true">
              <span>{{ initials(item.title) }}</span>
            </div>
            <span class="cover-glow"></span>
          </div>
          <div class="tile-copy">
            <p v-if="item.meta" class="meta">{{ item.meta }}</p>
            <h4>{{ item.title }}</h4>
            <p class="desc">{{ item.description }}</p>
          </div>
          <div class="item-actions">
            <span class="read-btn">{{ isExternalHref(item.href) ? 'Open Source' : 'Read Story' }}</span>
            <a
              v-if="item.canonicalUrl && item.canonicalUrl.length > 0 && !isExternalHref(item.href)"
              class="source-btn"
              :href="item.canonicalUrl"
              target="_blank"
              rel="noopener noreferrer"
              @click.stop
            >
              Source
            </a>
          </div>
        </a>

        <div v-else class="tile-link-wrap static-tile">
          <div class="cover-wrap">
            <img v-if="item.imageUrl && item.imageUrl.length > 0" class="cover" :src="item.imageUrl" alt="" loading="lazy" />
            <div v-else class="cover-fallback" aria-hidden="true">
              <span>{{ initials(item.title) }}</span>
            </div>
            <span class="cover-glow"></span>
          </div>
          <div class="tile-copy">
            <p v-if="item.meta" class="meta">{{ item.meta }}</p>
            <h4>{{ item.title }}</h4>
            <p class="desc">{{ item.description }}</p>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.module-card {
  border: 1px solid color-mix(in srgb, var(--accent) 26%, var(--border));
  border-radius: calc(var(--radius) + 2px);
  padding: 1rem;
  background:
    radial-gradient(circle at 94% -24%, color-mix(in srgb, var(--accent) 22%, transparent), transparent 44%),
    var(--surface);
  box-shadow: 0 16px 34px color-mix(in srgb, var(--accent) 12%, transparent);
}

.card-head {
  display: grid;
  gap: 0.45rem;
}

h3 {
  margin: 0;
  font-size: clamp(1.12rem, 2.6vw, 1.42rem);
}

.intro {
  margin: 0;
  color: var(--text-secondary);
}

.grid {
  margin-top: 0.8rem;
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.72rem;
}

.grid-item {
  animation: float-in 560ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
  animation-delay: var(--stagger, 0ms);
}

.tile-link-wrap {
  display: grid;
  gap: 0.58rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border));
  background:
    linear-gradient(
      152deg,
      color-mix(in srgb, var(--accent) 10%, var(--surface-muted)),
      color-mix(in srgb, var(--surface) 86%, var(--surface-muted))
    );
  padding: 0.72rem;
  text-decoration: none;
  color: inherit;
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.tile-link-wrap:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--accent) 58%, var(--border));
  box-shadow: 0 14px 26px color-mix(in srgb, var(--accent) 18%, transparent);
}

.static-tile {
  cursor: default;
}

.cover-wrap {
  position: relative;
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  min-height: 155px;
}

.cover {
  width: 100%;
  height: 100%;
  min-height: 155px;
  object-fit: cover;
  display: block;
}

.cover-fallback {
  min-height: 155px;
  display: grid;
  place-items: center;
  background:
    radial-gradient(circle at 18% 22%, color-mix(in srgb, var(--accent) 32%, transparent), transparent 44%),
    linear-gradient(140deg, color-mix(in srgb, var(--accent) 24%, #131b2f), #0f172a 60%, #111827);
}

.cover-fallback span {
  font-size: clamp(1.2rem, 3.3vw, 1.9rem);
  font-weight: 700;
  letter-spacing: 0.08em;
  color: color-mix(in srgb, var(--accent) 34%, #ffffff);
}

.cover-glow {
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.35), transparent 45%);
  pointer-events: none;
}

.tile-copy {
  min-width: 0;
}

.meta {
  margin: 0;
  font-size: 0.72rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 76%, var(--text-secondary));
}

h4 {
  margin: 0.32rem 0 0;
  font-size: 1rem;
  text-wrap: balance;
}

.desc {
  margin: 0.36rem 0 0;
  color: var(--text-secondary);
}

.item-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}

.read-btn,
.source-btn {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 34%, var(--border));
  padding: 0.2rem 0.55rem;
  font-size: 0.72rem;
}

.read-btn {
  color: #ffffff;
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 64%, #ffffff));
}

.source-btn {
  text-decoration: none;
  color: var(--text-primary);
  background: color-mix(in srgb, var(--accent) 9%, var(--surface));
}

.tile-lift .tile-link-wrap {
  transform: translateY(-2px);
}

.tile-lead .tile-link-wrap {
  border-width: 2px;
}

.variant-cards .tile-link-wrap,
.variant-neon .tile-link-wrap {
  border-width: 2px;
}

.variant-zigzag .grid-item:nth-child(odd) .tile-link-wrap {
  transform: rotate(-0.45deg) translateY(-2px);
}

.variant-zigzag .grid-item:nth-child(even) .tile-link-wrap {
  transform: rotate(0.45deg) translateY(2px);
}

.variant-zigzag .grid-item .tile-link-wrap:hover {
  transform: rotate(0deg) translateY(-3px);
}

.fx-matrix .tile-link-wrap {
  border-style: dashed;
}

.fx-prism .tile-link-wrap {
  background:
    linear-gradient(
      120deg,
      color-mix(in srgb, var(--accent) 20%, transparent),
      transparent 26%,
      color-mix(in srgb, var(--accent) 8%, transparent) 26%,
      transparent 62%,
      color-mix(in srgb, var(--accent) 16%, transparent) 62%,
      transparent 100%
    ),
    color-mix(in srgb, var(--surface-muted) 84%, var(--surface));
}

@keyframes float-in {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (min-width: 720px) {
  .grid.grid-cols-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .grid.grid-cols-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .grid.grid-cols-2 .tile-lead {
    grid-column: 1 / -1;
  }

  .grid.grid-cols-3 .tile-lead {
    grid-column: span 2;
  }
}
</style>
