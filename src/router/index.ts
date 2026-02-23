import type { Pinia } from 'pinia';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import OnboardingTerminal from '@/views/OnboardingTerminal.vue';
import PersonalizedShell from '@/views/PersonalizedShell.vue';
import PostStory from '@/views/PostStory.vue';
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
    redirect: '/app/home'
  },
  {
    path: '/app/home',
    name: 'app-home',
    component: PersonalizedShell
  },
  {
    path: '/app/conversations',
    name: 'app-conversations',
    component: PersonalizedShell
  },
  {
    path: '/app/skill-game',
    name: 'app-skill-game',
    component: PersonalizedShell
  },
  {
    path: '/app/widgets',
    name: 'app-widgets',
    component: PersonalizedShell
  },
  {
    path: '/app/blog',
    name: 'app-blog',
    component: PersonalizedShell
  },
  {
    path: '/story/:id(\\d+)',
    name: 'story',
    component: PostStory
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

    if ((to.path.startsWith('/app') || to.path.startsWith('/story/')) && !personalization.blueprint) {
      return '/onboarding';
    }

    if (to.path === '/onboarding' && personalization.blueprint && !forceOnboarding) {
      return '/app/home';
    }

    return true;
  });

  guardInstalled = true;
}
