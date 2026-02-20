<script setup lang="ts">
import { computed } from 'vue';
import type { ListItem } from '@/content/library';

const props = defineProps<{
  moduleProps: Record<string, unknown>;
  content: unknown;
  shortcuts: unknown[];
}>();

const title = computed(() => {
  const value = props.moduleProps.title;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'Content List';
});

const intro = computed(() => {
  const value = props.moduleProps.intro;
  return typeof value === 'string' ? value : '';
});

const variant = computed(() => {
  const value = props.moduleProps.variant;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'default';
});

const visualFx = computed(() => {
  const value = props.moduleProps.visualFx;
  return typeof value === 'string' && value.trim().length > 0 ? value : 'neon';
});

const items = computed(() => {
  const content = props.content as { items?: ListItem[] };
  return Array.isArray(content.items) ? content.items : [];
});

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
      <li v-for="item in visibleItems" :key="item.title">
        <img
          v-if="item.imageUrl && item.imageUrl.length > 0"
          class="thumb"
          :src="item.imageUrl"
          alt=""
          loading="lazy"
        />
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
      </li>
    </ul>
  </section>
</template>

<style scoped>
.module-card {
  background:
    radial-gradient(circle at 100% -18%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 44%),
    var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.15rem;
  box-shadow: 0 14px 30px color-mix(in srgb, var(--accent) 10%, transparent);
}

h3 {
  margin: 0;
}

ul {
  margin: 0.8rem 0 0;
  padding: 0;
  list-style: none;
  display: grid;
  gap: 0.65rem;
}

li {
  border-radius: 12px;
  padding: 0.78rem;
  background:
    linear-gradient(
      155deg,
      color-mix(in srgb, var(--accent) 8%, var(--surface-muted)),
      color-mix(in srgb, var(--surface-muted) 76%, var(--surface))
    );
  border: 1px solid color-mix(in srgb, var(--accent) 16%, var(--border));
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.thumb {
  width: 100%;
  max-height: 170px;
  object-fit: cover;
  border-radius: 10px;
  margin-bottom: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--accent) 20%, var(--border));
}

li:hover {
  transform: translateY(-1px);
  border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
  box-shadow: 0 10px 20px color-mix(in srgb, var(--accent) 14%, transparent);
}

p {
  margin: 0.35rem 0 0;
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
  background: linear-gradient(135deg, var(--accent), color-mix(in srgb, var(--accent) 60%, #ffffff));
}

.source-btn {
  color: var(--text-primary);
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
}

.item-link {
  color: inherit;
  text-decoration: none;
  border-bottom: 1px dashed color-mix(in srgb, var(--accent) 45%, transparent);
}

.intro {
  margin: 0.55rem 0 0;
  color: var(--text-secondary);
}

.variant-timeline li {
  border-left: 3px solid color-mix(in srgb, var(--accent) 55%, transparent);
}

.variant-checklist li {
  position: relative;
  padding-left: 1.5rem;
}

.variant-checklist li::before {
  content: '';
  position: absolute;
  left: 0.55rem;
  top: 0.98rem;
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: var(--accent);
}

.variant-stacked li {
  border-width: 2px;
  background: var(--surface);
  box-shadow: 0 8px 20px color-mix(in srgb, var(--accent) 8%, transparent);
}

.variant-neon li {
  border-width: 2px;
  background:
    linear-gradient(
      135deg,
      color-mix(in srgb, var(--accent) 14%, var(--surface)),
      color-mix(in srgb, var(--accent) 4%, var(--surface-muted))
    );
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--accent) 16%, transparent);
}

.variant-river li {
  border-left: 4px solid color-mix(in srgb, var(--accent) 62%, transparent);
  border-right: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
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
</style>
