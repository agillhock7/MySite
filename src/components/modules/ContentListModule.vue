<script setup lang="ts">
import { computed } from 'vue';
import type { ListItem } from '@/content/library';

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

function initials(input: string): string {
  const trimmed = input.trim();
  if (!trimmed) {
    return 'AG';
  }

  const words = trimmed.split(/\s+/).slice(0, 2);
  return words.map((word) => word.charAt(0).toUpperCase()).join('');
}

const title = computed(() => asCleanString(props.moduleProps.title) || 'Content List');
const intro = computed(() => asCleanString(props.moduleProps.intro));
const variant = computed(() => asCleanString(props.moduleProps.variant) || 'default');
const visualFx = computed(() => asCleanString(props.moduleProps.visualFx) || 'neon');

const items = computed(() => {
  const content = props.content as { items?: ListItem[] };
  return Array.isArray(content.items) ? content.items : [];
});

const visibleItems = computed(() => {
  const offset = parseNumberProp(props.moduleProps.offset, 0);
  const limit = parseNumberProp(props.moduleProps.limit, items.value.length || 6);

  return items.value.slice(offset, offset + Math.max(1, limit));
});

function isExternalHref(href: string | undefined): boolean {
  if (!href) {
    return false;
  }

  return /^https?:\/\//i.test(href);
}
</script>

<template>
  <section class="module-card" :class="[`variant-${variant}`, `fx-${visualFx}`]">
    <h3>{{ title }}</h3>
    <p v-if="intro" class="intro">{{ intro }}</p>

    <ul>
      <li v-for="(item, idx) in visibleItems" :key="item.id ?? `${item.title}-${idx}`" :style="{ '--stagger': `${idx * 55}ms` }">
        <span class="index">{{ (idx + 1).toString().padStart(2, '0') }}</span>

        <a
          v-if="item.imageUrl && item.imageUrl.length > 0"
          class="thumb-wrap"
          :href="item.href && item.href.length > 0 ? item.href : '#'"
          :target="isExternalHref(item.href) ? '_blank' : '_self'"
          :rel="isExternalHref(item.href) ? 'noopener noreferrer' : undefined"
        >
          <img class="thumb" :src="item.imageUrl" alt="" loading="lazy" />
        </a>
        <div v-else class="thumb-fallback" aria-hidden="true">{{ initials(item.title) }}</div>

        <div class="item-copy">
          <strong>
            <a
              v-if="item.href && item.href.length > 0"
              :href="item.href"
              :target="isExternalHref(item.href) ? '_blank' : '_self'"
              :rel="isExternalHref(item.href) ? 'noopener noreferrer' : undefined"
              class="item-link"
            >
              {{ item.title }}
            </a>
            <template v-else>{{ item.title }}</template>
          </strong>
          <p>{{ item.detail }}</p>

          <div class="item-actions">
            <a
              v-if="item.href && item.href.length > 0"
              class="read-btn"
              :href="item.href"
              :target="isExternalHref(item.href) ? '_blank' : '_self'"
              :rel="isExternalHref(item.href) ? 'noopener noreferrer' : undefined"
            >
              {{ isExternalHref(item.href) ? 'Open Source' : 'Read Story' }}
            </a>
            <a
              v-if="item.canonicalUrl && item.canonicalUrl.length > 0 && !isExternalHref(item.href)"
              class="source-btn"
              :href="item.canonicalUrl"
              target="_blank"
              rel="noopener noreferrer"
            >
              Source
            </a>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.module-card {
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  border-radius: calc(var(--radius) + 2px);
  padding: 1rem;
  background:
    radial-gradient(circle at 100% -26%, color-mix(in srgb, var(--accent) 18%, transparent), transparent 44%),
    var(--surface);
  box-shadow: 0 16px 32px color-mix(in srgb, var(--accent) 10%, transparent);
}

h3 {
  margin: 0;
  font-size: clamp(1.1rem, 2.6vw, 1.42rem);
}

.intro {
  margin: 0.5rem 0 0;
  color: var(--text-secondary);
}

ul {
  margin: 0.82rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.62rem;
}

li {
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  background:
    linear-gradient(
      152deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface-muted)),
      color-mix(in srgb, var(--surface) 86%, var(--surface-muted))
    );
  padding: 0.65rem;
  display: grid;
  gap: 0.62rem;
  grid-template-columns: auto 78px minmax(0, 1fr);
  align-items: start;
  animation: rise 500ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
  animation-delay: var(--stagger, 0ms);
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

li:hover {
  transform: translateY(-2px);
  border-color: color-mix(in srgb, var(--accent) 52%, var(--border));
  box-shadow: 0 12px 22px color-mix(in srgb, var(--accent) 15%, transparent);
}

.index {
  font-size: 0.72rem;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--accent) 76%, var(--text-secondary));
  align-self: center;
}

.thumb-wrap,
.thumb-fallback {
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
  overflow: hidden;
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
  min-height: 70px;
}

.thumb-wrap {
  display: block;
}

.thumb {
  width: 100%;
  height: 100%;
  min-height: 70px;
  object-fit: cover;
  display: block;
}

.thumb-fallback {
  display: grid;
  place-items: center;
  font-size: 0.9rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: color-mix(in srgb, var(--accent) 40%, var(--text-primary));
}

.item-copy {
  min-width: 0;
}

.item-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dashed color-mix(in srgb, var(--accent) 45%, transparent);
}

p {
  margin: 0.34rem 0 0;
  color: var(--text-secondary);
}

.item-actions {
  margin-top: 0.45rem;
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}

.read-btn,
.source-btn {
  text-decoration: none;
  font-size: 0.72rem;
  border-radius: 999px;
  padding: 0.2rem 0.55rem;
  border: 1px solid color-mix(in srgb, var(--accent) 34%, var(--border));
}

.read-btn {
  color: #ffffff;
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 62%, #ffffff));
}

.source-btn {
  color: var(--text-primary);
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
}

.variant-timeline li,
.variant-river li {
  border-left: 3px solid color-mix(in srgb, var(--accent) 60%, transparent);
}

.variant-stacked li,
.variant-neon li {
  border-width: 2px;
}

.variant-checklist li {
  position: relative;
}

.variant-checklist .index {
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--accent) 44%, var(--border));
  padding: 0.2rem 0.45rem;
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
}

.fx-matrix li {
  border-style: dashed;
}

.fx-prism li {
  background:
    linear-gradient(
      130deg,
      color-mix(in srgb, var(--accent) 16%, transparent),
      transparent 32%,
      color-mix(in srgb, var(--accent) 8%, transparent) 32%,
      transparent 62%,
      color-mix(in srgb, var(--accent) 14%, transparent) 62%,
      transparent
    ),
    color-mix(in srgb, var(--surface-muted) 76%, var(--surface));
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 640px) {
  li {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .thumb-wrap,
  .thumb-fallback {
    display: none;
  }
}
</style>
