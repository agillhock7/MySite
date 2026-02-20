import type { Blueprint } from '@/blueprint/schema';
import { hashText, seededChoice, seededUnit } from '@/utils/seed';

export type ShellProfile = 'orbital' | 'editorial' | 'kinetic' | 'glass' | 'neo' | 'atlas' | 'spectral';
export type TypographyProfile = 'grotesk' | 'literary' | 'display' | 'mono';
export type MotionProfile = 'calm' | 'balanced' | 'kinetic';

export interface ExperienceHints {
  shellProfile?: string;
  typographyProfile?: string;
  motionProfile?: string;
  visualFx?: string;
  textureFx?: string;
  energyFx?: string;
  styleMotif?: string;
}

export interface AmbientNodeToken {
  key: string;
  size: string;
  left: string;
  top: string;
  opacity: string;
  duration: string;
  delay: string;
  blendClass: string;
}

export interface AmbientRingToken {
  key: string;
  size: string;
  left: string;
  top: string;
  opacity: string;
  rotate: string;
  duration: string;
}

export interface PosterTransformToken {
  key: string;
  rotateDeg: string;
  liftPx: string;
  scale: string;
}

export interface ExperienceSystem {
  visualSeed: number;
  shellProfile: ShellProfile;
  typographyProfile: TypographyProfile;
  motionProfile: MotionProfile;
  experienceMode: number;
  visualFx: string;
  textureFx: string;
  energyFx: string;
  styleMotif: string;
  shellTitle: string;
  experienceLabel: string;
  radius: number;
  panelBlur: number;
  moduleGapRem: number;
  ambientNodes: AmbientNodeToken[];
  ambientRings: AmbientRingToken[];
  posterTransforms: PosterTransformToken[];
}

const SHELL_PROFILES: readonly ShellProfile[] = ['orbital', 'editorial', 'kinetic', 'glass', 'neo', 'atlas', 'spectral'];
const TYPOGRAPHY_PROFILES: readonly TypographyProfile[] = ['grotesk', 'literary', 'display', 'mono'];
const MOTION_PROFILES: readonly MotionProfile[] = ['calm', 'balanced', 'kinetic'];

function coerceShellProfile(value: string | undefined, seed: number): ShellProfile {
  const normalized = (value ?? '').trim().toLowerCase();
  if (SHELL_PROFILES.includes(normalized as ShellProfile)) {
    return normalized as ShellProfile;
  }

  return seededChoice(seed, 'shell-profile', SHELL_PROFILES);
}

function coerceTypographyProfile(value: string | undefined, seed: number): TypographyProfile {
  const normalized = (value ?? '').trim().toLowerCase();
  if (TYPOGRAPHY_PROFILES.includes(normalized as TypographyProfile)) {
    return normalized as TypographyProfile;
  }

  return seededChoice(seed, 'typography-profile', TYPOGRAPHY_PROFILES);
}

function coerceMotionProfile(value: string | undefined, seed: number): MotionProfile {
  const normalized = (value ?? '').trim().toLowerCase();
  if (MOTION_PROFILES.includes(normalized as MotionProfile)) {
    return normalized as MotionProfile;
  }

  return seededChoice(seed, 'motion-profile', MOTION_PROFILES);
}

function shellTitleByProfile(profile: ShellProfile, mode: number): string {
  const labelsByProfile: Record<ShellProfile, string[]> = {
    orbital: ['Orbiting Story Field', 'Immersive Orbit Chronicle', 'Future Signal Stories', 'Curated Orbit Atelier'],
    editorial: ['Editorial Story Engine', 'Feature Narrative Stream', 'Curated Editorial Atlas', 'Signature Story Archive'],
    kinetic: ['High-Velocity Storyline', 'Kinetic Story Surface', 'Motion-Driven Editorial', 'Pulse Narrative Engine'],
    glass: ['Prism Story Layer', 'Luminous Story Atelier', 'Translucent Narrative Grid', 'Refraction Editorial Stream'],
    neo: ['Neo Chronicle System', 'Modern Signal Editorial', 'Neo Atlas Feed', 'Future Blog Interface'],
    atlas: ['Atlas Story Cartography', 'Map of Living Posts', 'Topographic Narrative Field', 'Explorer Story Grid'],
    spectral: ['Spectral Story Spectrum', 'Chromatic Narrative Field', 'Lightwave Editorial Surface', 'Prismatic Post Engine']
  };

  const labels = labelsByProfile[profile];
  return labels[mode % labels.length];
}

function experienceLabel(profile: ShellProfile, mode: number): string {
  const labels = ['Chronicle', 'Cinema', 'Atelier', 'Pulse'];
  return `${profile.toUpperCase()} ${labels[mode % labels.length]}`;
}

function buildAmbientNodes(seed: number, reducedMotion: boolean): AmbientNodeToken[] {
  const count = reducedMotion ? 5 : 11;
  const blendModes = ['blend-screen', 'blend-overlay', 'blend-plus'] as const;

  const nodes: AmbientNodeToken[] = [];
  for (let index = 0; index < count; index += 1) {
    const size = 130 + seededUnit(seed, `node-size-${index}`) * 360;
    const left = seededUnit(seed, `node-left-${index}`) * 100;
    const top = seededUnit(seed, `node-top-${index}`) * 100;
    const opacity = 0.14 + seededUnit(seed, `node-opacity-${index}`) * 0.38;
    const duration = (reducedMotion ? 20 : 11) + seededUnit(seed, `node-duration-${index}`) * (reducedMotion ? 18 : 14);
    const delay = seededUnit(seed, `node-delay-${index}`) * -8;

    nodes.push({
      key: `ambient-${index}`,
      size: `${size.toFixed(0)}px`,
      left: `${left.toFixed(2)}%`,
      top: `${top.toFixed(2)}%`,
      opacity: opacity.toFixed(2),
      duration: `${duration.toFixed(1)}s`,
      delay: `${delay.toFixed(1)}s`,
      blendClass: seededChoice(seed, `node-mix-${index}`, blendModes)
    });
  }

  return nodes;
}

function buildAmbientRings(seed: number, reducedMotion: boolean): AmbientRingToken[] {
  const count = reducedMotion ? 2 : 4;
  const rings: AmbientRingToken[] = [];

  for (let index = 0; index < count; index += 1) {
    const size = 180 + seededUnit(seed, `ring-size-${index}`) * 460;
    const left = 10 + seededUnit(seed, `ring-left-${index}`) * 80;
    const top = 8 + seededUnit(seed, `ring-top-${index}`) * 78;
    const opacity = 0.14 + seededUnit(seed, `ring-opacity-${index}`) * 0.32;
    const rotate = seededUnit(seed, `ring-rotate-${index}`) * 360;
    const duration = (reducedMotion ? 28 : 16) + seededUnit(seed, `ring-duration-${index}`) * 20;

    rings.push({
      key: `ring-${index}`,
      size: `${size.toFixed(0)}px`,
      left: `${left.toFixed(2)}%`,
      top: `${top.toFixed(2)}%`,
      opacity: opacity.toFixed(2),
      rotate: `${rotate.toFixed(2)}deg`,
      duration: `${duration.toFixed(1)}s`
    });
  }

  return rings;
}

function buildPosterTransforms(seed: number): PosterTransformToken[] {
  const tokens: PosterTransformToken[] = [];

  for (let index = 0; index < 8; index += 1) {
    const rotate = -1.2 + seededUnit(seed, `poster-rotate-${index}`) * 2.4;
    const lift = -4 + seededUnit(seed, `poster-lift-${index}`) * 10;
    const scale = 0.97 + seededUnit(seed, `poster-scale-${index}`) * 0.06;

    tokens.push({
      key: `poster-${index}`,
      rotateDeg: `${rotate.toFixed(3)}deg`,
      liftPx: `${lift.toFixed(2)}px`,
      scale: scale.toFixed(3)
    });
  }

  return tokens;
}

export function resolveExperienceSystem(params: {
  blueprint: Blueprint;
  signature: string;
  focusTopics: string[];
  hints: ExperienceHints;
  reducedMotion: boolean;
}): ExperienceSystem {
  const moduleIds = params.blueprint.modules.map((module) => module.id).join('|');
  const baseSeed = hashText(
    `${params.blueprint.theme.accent}|${params.blueprint.layout.nav}|${moduleIds}|${params.blueprint.createdAt}`
  );

  const topicKey = params.focusTopics.join('|').toLowerCase();
  const visualSeed = hashText(`${baseSeed}|${params.signature}|${params.hints.shellProfile ?? ''}|${topicKey}`);

  const shellProfile = coerceShellProfile(params.hints.shellProfile, visualSeed);
  const typographyProfile = coerceTypographyProfile(params.hints.typographyProfile, visualSeed);
  const motionProfile = coerceMotionProfile(params.hints.motionProfile, visualSeed);
  const experienceMode = visualSeed % 4;

  return {
    visualSeed,
    shellProfile,
    typographyProfile,
    motionProfile,
    experienceMode,
    visualFx: (params.hints.visualFx ?? '').trim() || 'neon',
    textureFx: (params.hints.textureFx ?? '').trim() || 'glass',
    energyFx: (params.hints.energyFx ?? '').trim() || 'balanced',
    styleMotif: (params.hints.styleMotif ?? '').trim() || 'editorial',
    shellTitle: shellTitleByProfile(shellProfile, experienceMode),
    experienceLabel: experienceLabel(shellProfile, experienceMode),
    radius: 12 + Math.floor(seededUnit(visualSeed, 'radius') * 12),
    panelBlur: 4 + Math.floor(seededUnit(visualSeed, 'blur') * 8),
    moduleGapRem: 0.8 + seededUnit(visualSeed, 'gap') * 0.75,
    ambientNodes: buildAmbientNodes(visualSeed, params.reducedMotion),
    ambientRings: buildAmbientRings(visualSeed, params.reducedMotion),
    posterTransforms: buildPosterTransforms(visualSeed)
  };
}
