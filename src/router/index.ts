import type { Pinia } from 'pinia';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import OnboardingTerminal from '@/views/OnboardingTerminal.vue';
import PersonalizedShell from '@/views/PersonalizedShell.vue';
import { usePersonalizationStore } from '@/stores/personalization';

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    redirect: '/app'
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
    const shouldResetPersonalization = to.query.reset === '1' || to.query.clear === '1';
    const forceOnboarding = to.query.force === '1';

    if (!personalization.blueprint) {
      personalization.loadFromStorage();
    }

    if (shouldResetPersonalization) {
      personalization.resetPersonalization();

      if (to.path !== '/onboarding') {
        return {
          path: '/onboarding',
          query: { force: '1', reset: '1' }
        };
      }
    }

    if (to.path === '/app' && !personalization.blueprint) {
      return '/onboarding';
    }

    if (to.path === '/onboarding' && personalization.blueprint && !forceOnboarding) {
      return '/app';
    }

    return true;
  });

  guardInstalled = true;
}
