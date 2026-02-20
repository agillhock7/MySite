<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { hashText } from '@/utils/seed';

const props = defineProps<{
  accent: string;
  seed: number;
  reducedMotion: boolean;
}>();

interface Particle {
  x: number;
  y: number;
  vx: number;
  vy: number;
  radius: number;
  alpha: number;
}

const canvasRef = ref<HTMLCanvasElement | null>(null);

let frameHandle = 0;
let resizeObserver: ResizeObserver | null = null;
let ctx: CanvasRenderingContext2D | null = null;
let width = 0;
let height = 0;
let dpr = 1;
let particles: Particle[] = [];
let startTime = 0;

function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

function parseHexColor(input: string): { r: number; g: number; b: number } {
  const fallback = { r: 22, g: 199, b: 207 };
  const normalized = input.trim();
  const shortHexMatch = /^#([0-9a-fA-F]{3})$/.exec(normalized);
  const hexMatch = /^#([0-9a-fA-F]{6})$/.exec(normalized);

  if (shortHexMatch) {
    const [r, g, b] = shortHexMatch[1].split('').map((token) => Number.parseInt(token + token, 16));
    return { r, g, b };
  }

  if (hexMatch) {
    const value = hexMatch[1];
    return {
      r: Number.parseInt(value.slice(0, 2), 16),
      g: Number.parseInt(value.slice(2, 4), 16),
      b: Number.parseInt(value.slice(4, 6), 16)
    };
  }

  return fallback;
}

function rgbToString(color: { r: number; g: number; b: number }, alpha: number): string {
  return `rgba(${Math.round(color.r)}, ${Math.round(color.g)}, ${Math.round(color.b)}, ${clamp(alpha, 0, 1)})`;
}

function mixColor(a: { r: number; g: number; b: number }, b: { r: number; g: number; b: number }, t: number) {
  return {
    r: a.r + (b.r - a.r) * t,
    g: a.g + (b.g - a.g) * t,
    b: a.b + (b.b - a.b) * t
  };
}

function brighten(color: { r: number; g: number; b: number }, amount: number) {
  return mixColor(color, { r: 255, g: 255, b: 255 }, amount);
}

function darken(color: { r: number; g: number; b: number }, amount: number) {
  return mixColor(color, { r: 3, g: 10, b: 25 }, amount);
}

function createSeededRandom(seed: number): () => number {
  let state = (seed || 1) >>> 0;

  return () => {
    state ^= state << 13;
    state ^= state >>> 17;
    state ^= state << 5;
    return (state >>> 0) / 4294967295;
  };
}

function setupCanvas(): void {
  const canvas = canvasRef.value;
  if (!canvas) {
    return;
  }

  const context = canvas.getContext('2d', { alpha: true });
  if (!context) {
    return;
  }

  ctx = context;

  const rect = canvas.getBoundingClientRect();
  dpr = clamp(window.devicePixelRatio || 1, 1, 2);
  width = Math.max(1, Math.floor(rect.width));
  height = Math.max(1, Math.floor(rect.height));

  canvas.width = Math.max(1, Math.floor(width * dpr));
  canvas.height = Math.max(1, Math.floor(height * dpr));
  context.setTransform(dpr, 0, 0, dpr, 0, 0);

  regenerateParticles();
  drawFrame(0, true);
}

function regenerateParticles(): void {
  const random = createSeededRandom(hashText(`${props.seed}|${props.accent}|particles`));
  const count = props.reducedMotion ? 18 : 54;

  particles = [];
  for (let index = 0; index < count; index += 1) {
    particles.push({
      x: random() * width,
      y: random() * height,
      vx: (random() - 0.5) * (props.reducedMotion ? 0 : 0.16),
      vy: (random() - 0.5) * (props.reducedMotion ? 0 : 0.16),
      radius: 0.6 + random() * 1.8,
      alpha: 0.18 + random() * 0.42
    });
  }
}

function renderBackdrop(elapsedMs: number): void {
  if (!ctx) {
    return;
  }

  const base = parseHexColor(props.accent);
  const glowA = brighten(base, 0.35);
  const glowB = mixColor({ r: 99, g: 102, b: 241 }, base, 0.45);
  const glowC = darken(base, 0.45);

  ctx.clearRect(0, 0, width, height);

  const baseGradient = ctx.createLinearGradient(0, 0, width, height);
  baseGradient.addColorStop(0, rgbToString(darken(base, 0.72), 0.5));
  baseGradient.addColorStop(0.5, rgbToString(glowC, 0.22));
  baseGradient.addColorStop(1, rgbToString(darken(base, 0.86), 0.38));

  ctx.fillStyle = baseGradient;
  ctx.fillRect(0, 0, width, height);

  const t = elapsedMs * 0.001;

  const p1x = width * (0.12 + 0.18 * Math.sin(t * 0.33 + props.seed * 0.0002));
  const p1y = height * (0.2 + 0.16 * Math.cos(t * 0.37 + props.seed * 0.0003));
  const p2x = width * (0.76 + 0.18 * Math.cos(t * 0.26 + props.seed * 0.0005));
  const p2y = height * (0.16 + 0.14 * Math.sin(t * 0.42 + props.seed * 0.0001));
  const p3x = width * (0.5 + 0.22 * Math.sin(t * 0.2 + props.seed * 0.0004));
  const p3y = height * (0.8 + 0.16 * Math.cos(t * 0.27 + props.seed * 0.0006));

  const gradientA = ctx.createRadialGradient(p1x, p1y, 0, p1x, p1y, Math.max(width, height) * 0.48);
  gradientA.addColorStop(0, rgbToString(glowA, 0.32));
  gradientA.addColorStop(1, rgbToString(glowA, 0));
  ctx.fillStyle = gradientA;
  ctx.fillRect(0, 0, width, height);

  const gradientB = ctx.createRadialGradient(p2x, p2y, 0, p2x, p2y, Math.max(width, height) * 0.44);
  gradientB.addColorStop(0, rgbToString(glowB, 0.26));
  gradientB.addColorStop(1, rgbToString(glowB, 0));
  ctx.fillStyle = gradientB;
  ctx.fillRect(0, 0, width, height);

  const gradientC = ctx.createRadialGradient(p3x, p3y, 0, p3x, p3y, Math.max(width, height) * 0.52);
  gradientC.addColorStop(0, rgbToString(base, 0.2));
  gradientC.addColorStop(1, rgbToString(base, 0));
  ctx.fillStyle = gradientC;
  ctx.fillRect(0, 0, width, height);

  ctx.save();
  ctx.globalCompositeOperation = 'screen';
  ctx.lineWidth = 1;
  ctx.strokeStyle = rgbToString(brighten(base, 0.28), 0.18);

  const lineCount = props.reducedMotion ? 3 : 7;
  for (let line = 0; line < lineCount; line += 1) {
    const y = (line + 1) * (height / (lineCount + 1));
    const wave = Math.sin(t * (0.45 + line * 0.07) + line * 1.3 + props.seed * 0.0001) * 22;

    ctx.beginPath();
    ctx.moveTo(0, y + wave);
    ctx.bezierCurveTo(width * 0.28, y - wave * 0.8, width * 0.68, y + wave * 0.6, width, y - wave * 0.4);
    ctx.stroke();
  }
  ctx.restore();

  ctx.save();
  ctx.globalCompositeOperation = 'lighter';
  particles.forEach((particle) => {
    if (!props.reducedMotion) {
      particle.x += particle.vx;
      particle.y += particle.vy;

      if (particle.x < -8) particle.x = width + 8;
      if (particle.x > width + 8) particle.x = -8;
      if (particle.y < -8) particle.y = height + 8;
      if (particle.y > height + 8) particle.y = -8;
    }

    ctx!.beginPath();
    ctx!.fillStyle = rgbToString(brighten(base, 0.42), particle.alpha);
    ctx!.arc(particle.x, particle.y, particle.radius, 0, Math.PI * 2);
    ctx!.fill();
  });
  ctx.restore();
}

function drawFrame(timestamp: number, force = false): void {
  if (!ctx) {
    return;
  }

  if (!startTime) {
    startTime = timestamp;
  }

  const elapsed = timestamp - startTime;
  renderBackdrop(elapsed);

  if (props.reducedMotion && !force) {
    return;
  }

  frameHandle = window.requestAnimationFrame((nextTimestamp) => {
    drawFrame(nextTimestamp);
  });
}

function restartAnimation(): void {
  if (frameHandle) {
    window.cancelAnimationFrame(frameHandle);
    frameHandle = 0;
  }

  startTime = 0;
  setupCanvas();

  if (!props.reducedMotion) {
    frameHandle = window.requestAnimationFrame((timestamp) => {
      drawFrame(timestamp);
    });
  }
}

onMounted(() => {
  setupCanvas();

  const canvas = canvasRef.value;
  if (canvas && typeof ResizeObserver !== 'undefined') {
    resizeObserver = new ResizeObserver(() => {
      setupCanvas();
    });
    resizeObserver.observe(canvas);
  }

  if (!props.reducedMotion) {
    frameHandle = window.requestAnimationFrame((timestamp) => {
      drawFrame(timestamp);
    });
  }
});

watch(
  () => [props.accent, props.seed, props.reducedMotion] as const,
  () => {
    restartAnimation();
  }
);

onBeforeUnmount(() => {
  if (frameHandle) {
    window.cancelAnimationFrame(frameHandle);
  }

  if (resizeObserver && canvasRef.value) {
    resizeObserver.unobserve(canvasRef.value);
  }

  resizeObserver = null;
  ctx = null;
});
</script>

<template>
  <canvas ref="canvasRef" class="holo-backdrop" aria-hidden="true"></canvas>
</template>

<style scoped>
.holo-backdrop {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  display: block;
  opacity: 0.9;
}
</style>
