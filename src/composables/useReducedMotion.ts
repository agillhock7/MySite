import { onBeforeUnmount, onMounted, ref } from 'vue';

export function useReducedMotion() {
  const prefersReducedMotion = ref(false);
  let mediaQuery: MediaQueryList | null = null;

  function updatePreference(event?: MediaQueryListEvent): void {
    if (event) {
      prefersReducedMotion.value = event.matches;
      return;
    }

    prefersReducedMotion.value = Boolean(mediaQuery?.matches);
  }

  onMounted(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
      return;
    }

    mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    updatePreference();

    if (typeof mediaQuery.addEventListener === 'function') {
      mediaQuery.addEventListener('change', updatePreference);
      return;
    }

    mediaQuery.addListener(updatePreference);
  });

  onBeforeUnmount(() => {
    if (!mediaQuery) {
      return;
    }

    if (typeof mediaQuery.removeEventListener === 'function') {
      mediaQuery.removeEventListener('change', updatePreference);
      return;
    }

    mediaQuery.removeListener(updatePreference);
  });

  return {
    prefersReducedMotion
  };
}
