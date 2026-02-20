const VISITOR_ID_STORAGE_KEY = 'terminal-visitor-id-v1';
const DESIGN_ITERATION_STORAGE_KEY = 'terminal-design-iteration-v1';

function randomToken(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  return `visitor-${Date.now()}-${Math.floor(Math.random() * 1_000_000)}`;
}

export function getOrCreateVisitorId(): string {
  if (typeof window === 'undefined' || typeof localStorage === 'undefined') {
    return 'visitor-server-fallback';
  }

  try {
    const existing = localStorage.getItem(VISITOR_ID_STORAGE_KEY);
    if (existing && existing.trim().length > 0) {
      return existing;
    }

    const created = randomToken();
    localStorage.setItem(VISITOR_ID_STORAGE_KEY, created);
    return created;
  } catch {
    return randomToken();
  }
}

export function getDesignIteration(): number {
  if (typeof window === 'undefined' || typeof localStorage === 'undefined') {
    return 0;
  }

  try {
    const raw = localStorage.getItem(DESIGN_ITERATION_STORAGE_KEY);
    if (!raw) {
      localStorage.setItem(DESIGN_ITERATION_STORAGE_KEY, '0');
      return 0;
    }

    const parsed = Number.parseInt(raw, 10);
    if (Number.isNaN(parsed) || parsed < 0) {
      localStorage.setItem(DESIGN_ITERATION_STORAGE_KEY, '0');
      return 0;
    }

    return parsed;
  } catch {
    return 0;
  }
}

export function bumpDesignIteration(): number {
  if (typeof window === 'undefined' || typeof localStorage === 'undefined') {
    return 1;
  }

  try {
    const current = getDesignIteration();
    const next = current + 1;
    localStorage.setItem(DESIGN_ITERATION_STORAGE_KEY, String(next));
    return next;
  } catch {
    return 1;
  }
}
