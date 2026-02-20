import type { Pinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import { loadBlueprint } from '@/blueprint/engine';
import OnboardingTerminal from '@/views/OnboardingTerminal.vue';
import PersonalizedShell from '@/views/PersonalizedShell.vue';
import { usePersonalizationStore } from '@/stores/personalization';

const routes = [
  {
    path: '/',
    redirect: () => (loadBlueprint() ? '/app' : '/onboarding')
  },
  {
    path: '/onboarding',
    name: 'onboarding',
    component: OnboardingTerminal
  },
  {
    path: '/app',
    name: 'app',
    component: PersonalizedShell
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
];

export const router = createRouter({
  history: createWebHistory(),
  routes
});

let guardInstalled = false;

export function installRouterGuards(pinia: Pinia): void {
  if (guardInstalled) {
    return;
  }

  router.beforeEach((to) => {
    const personalization = usePersonalizationStore(pinia);

    if (!personalization.blueprint) {
      personalization.loadFromStorage();
    }

    if (to.path === '/app' && !personalization.hasBlueprint) {
      return '/onboarding';
    }

    if (to.path === '/onboarding' && personalization.hasBlueprint) {
      return '/app';
    }

    return true;
  });

  guardInstalled = true;
}
