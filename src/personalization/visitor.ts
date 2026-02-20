const VISITOR_ID_STORAGE_KEY = 'terminal-visitor-id-v1';

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
