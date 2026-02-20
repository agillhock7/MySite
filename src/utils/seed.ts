export function hashText(input: string): number {
  let hash = 2166136261;

  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }

  return hash >>> 0;
}

export function seededUnit(seed: number, salt: string): number {
  return hashText(`${seed}:${salt}`) / 4294967295;
}

export function seededChoice<T>(seed: number, salt: string, options: readonly T[]): T {
  if (options.length === 0) {
    throw new Error('seededChoice requires at least one option');
  }

  const index = Math.floor(seededUnit(seed, salt) * options.length) % options.length;
  return options[index] as T;
}
