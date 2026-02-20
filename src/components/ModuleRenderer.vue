<script setup lang="ts">
import { computed, type Component } from 'vue';
import type { BlueprintModule, BlueprintShortcut } from '@/blueprint/schema';
import { getContentByKey } from '@/content/library';
import HeroModule from '@/components/modules/HeroModule.vue';
import ContentGridModule from '@/components/modules/ContentGridModule.vue';
import ContentListModule from '@/components/modules/ContentListModule.vue';
import QuickActionsModule from '@/components/modules/QuickActionsModule.vue';
import FAQModule from '@/components/modules/FAQModule.vue';

const props = defineProps<{
  module: BlueprintModule;
  shortcuts: BlueprintShortcut[];
}>();

const componentMap: Record<string, Component> = {
  Hero: HeroModule,
  ContentGrid: ContentGridModule,
  ContentList: ContentListModule,
  QuickActions: QuickActionsModule,
  FAQ: FAQModule
};

const resolvedComponent = computed(() => componentMap[props.module.type]);
const boundContent = computed(() => getContentByKey(props.module.contentKey));
const hasBoundContent = computed(() => Boolean(boundContent.value));
</script>

<template>
  <template v-if="resolvedComponent">
    <section v-if="!hasBoundContent" class="module-placeholder">No content bound</section>
    <component
      :is="resolvedComponent"
      v-else
      :module-props="module.props"
      :content="boundContent"
      :shortcuts="shortcuts"
    />
  </template>
</template>

<style scoped>
.module-placeholder {
  border: 1px dashed var(--border);
  border-radius: var(--radius);
  padding: 1rem;
  color: var(--text-secondary);
  background: var(--surface);
}
</style>
