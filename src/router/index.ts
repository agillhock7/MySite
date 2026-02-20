import type { Pinia } from 'pinia';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { loadBlueprint } from '@/blueprint/engine';
import OnboardingTerminal from '@/views/OnboardingTerminal.vue';
import PersonalizedShell from '@/views/PersonalizedShell.vue';
import { usePersonalizationStore } from '@/stores/personalization';

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    redirect: (to) => {
      if (to.query.reset === '1' || to.query.clear === '1') {
        return '/onboarding?force=1&reset=1';
      }

      return loadBlueprint() ? '/app' : '/onboarding';
    }
  },
  {
    path: '/reset',
    name: 'reset',
    redirect: '/onboarding?force=1&reset=1'
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
    const shouldForceOnboarding = to.path === '/onboarding' && to.query.force === '1';
    const shouldResetOnboardingState =
      to.path === '/onboarding' && (to.query.reset === '1' || to.query.clear === '1');

    if (!personalization.blueprint) {
      personalization.loadFromStorage();
    }

    if (shouldResetOnboardingState) {
      personalization.resetPersonalization();
    }

    if (shouldForceOnboarding) {
      return true;
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
