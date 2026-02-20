import { defineStore } from 'pinia';
import { clearBlueprint, loadBlueprint, saveBlueprint } from '@/blueprint/engine';
import type { Blueprint } from '@/blueprint/schema';

interface PersonalizationState {
  blueprint: Blueprint | null;
}

export const usePersonalizationStore = defineStore('personalization', {
  state: (): PersonalizationState => ({
    blueprint: null
  }),
  getters: {
    hasBlueprint: (state) => state.blueprint !== null
  },
  actions: {
    loadFromStorage(): Blueprint | null {
      const blueprint = loadBlueprint();
      this.blueprint = blueprint;
      return blueprint;
    },
    setBlueprint(blueprint: Blueprint): void {
      this.blueprint = blueprint;
      saveBlueprint(blueprint);
    },
    resetPersonalization(): void {
      this.blueprint = null;
      clearBlueprint();
    }
  }
});
