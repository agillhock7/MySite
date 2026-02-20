import { defaultBlueprint, DEFAULT_BLUEPRINT_VERSION } from './defaultBlueprint';
import { blueprintSchema, type Blueprint } from './schema';

const STORAGE_KEY = 'terminal-ui-blueprint-v1';

export function validateBlueprint(candidate: unknown): Blueprint | null {
  const parsed = blueprintSchema.safeParse(candidate);
  if (!parsed.success) {
    return null;
  }

  return parsed.data;
}

export function migrateBlueprintIfNeeded(candidate: unknown): unknown {
  if (!candidate || typeof candidate !== 'object') {
    return candidate;
  }

  const record = { ...(candidate as Record<string, unknown>) };

  if (typeof record.version !== 'number') {
    record.version = DEFAULT_BLUEPRINT_VERSION;
  }

  if ((record.version as number) < DEFAULT_BLUEPRINT_VERSION) {
    record.version = DEFAULT_BLUEPRINT_VERSION;
  }

  if (!record.createdAt || typeof record.createdAt !== 'string') {
    record.createdAt = new Date().toISOString();
  }

  record.updatedAt = new Date().toISOString();
  return record;
}

export function loadBlueprint(): Blueprint | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as unknown;
    const migrated = migrateBlueprintIfNeeded(parsed);
    const valid = validateBlueprint(migrated);

    if (!valid) {
      clearBlueprint();
      return null;
    }

    return valid;
  } catch {
    clearBlueprint();
    return null;
  }
}

export function saveBlueprint(blueprint: Blueprint): void {
  const valid = validateBlueprint(blueprint);
  if (!valid) {
    const fallback = defaultBlueprint();
    localStorage.setItem(STORAGE_KEY, JSON.stringify(fallback));
    return;
  }

  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      ...valid,
      updatedAt: new Date().toISOString()
    })
  );
}

export function clearBlueprint(): void {
  localStorage.removeItem(STORAGE_KEY);
}
