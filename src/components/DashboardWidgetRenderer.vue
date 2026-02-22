<script setup lang="ts">
import { computed } from 'vue';
import {
  fashionSnapshots,
  horoscopeInsight,
  sportsSnapshots,
  type DashboardWidget
} from '@/dashboard/engine';

const props = defineProps<{
  widget: DashboardWidget;
  signature: string;
}>();

const sign = computed(() => props.widget.config.sign || 'aries');
const horoscopeText = computed(() => horoscopeInsight(sign.value, props.signature));
const sportsItems = computed(() => sportsSnapshots(props.widget.config.league || 'NHL', props.signature));
const fashionItems = computed(() => fashionSnapshots(props.widget.config.mood || 'street-minimal', props.signature));
const safeHtml = computed(() => props.widget.html || '<p>No HTML content provided.</p>');
</script>

<template>
  <section class="widget-shell" :class="`type-${widget.type}`">
    <p class="widget-type">{{ widget.type }}</p>

    <template v-if="widget.type === 'horoscope'">
      <p class="primary">{{ sign.toUpperCase() }} forecast</p>
      <p class="secondary">{{ horoscopeText }}</p>
    </template>

    <template v-else-if="widget.type === 'sports'">
      <p class="primary">{{ widget.config.league || 'NHL' }} live pulse</p>
      <ul>
        <li v-for="item in sportsItems" :key="item">{{ item }}</li>
      </ul>
    </template>

    <template v-else-if="widget.type === 'fashion'">
      <p class="primary">{{ widget.config.mood || 'street-minimal' }} trend pulse</p>
      <ul>
        <li v-for="item in fashionItems" :key="item">{{ item }}</li>
      </ul>
    </template>

    <template v-else>
      <div class="html-preview" v-html="safeHtml"></div>
    </template>
  </section>
</template>

<style scoped>
.widget-shell {
  border: 1px solid #1f2937;
  border-radius: 10px;
  background: rgba(3, 7, 18, 0.84);
  padding: 0.68rem;
}

.widget-type {
  margin: 0;
  color: #67e8f9;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.68rem;
}

.primary {
  margin: 0.34rem 0 0;
  color: #d1fae5;
  font-size: 0.92rem;
}

.secondary {
  margin: 0.36rem 0 0;
  color: #a7f3d0;
}

ul {
  margin: 0.4rem 0 0;
  padding-left: 1rem;
  color: #a7f3d0;
  display: grid;
  gap: 0.24rem;
}

.html-preview :deep(h1),
.html-preview :deep(h2),
.html-preview :deep(h3),
.html-preview :deep(h4),
.html-preview :deep(p),
.html-preview :deep(li) {
  margin: 0.3rem 0 0;
  color: #bbf7d0;
}

.html-preview :deep(ul) {
  padding-left: 1rem;
}

.type-customHtml {
  border-color: #0f766e;
}
</style>
