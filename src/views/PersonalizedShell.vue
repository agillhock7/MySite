<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchWordpressContentBundle } from '@/api/wp';
import AiPromptGame from '@/components/AiPromptGame.vue';
import DashboardWidgetRenderer from '@/components/DashboardWidgetRenderer.vue';
import HoloBackdrop from '@/components/effects/HoloBackdrop.vue';
import {
  MAX_DASHBOARD_WIDGETS,
  createPromptWidgetFromPrompt,
  createPresetWidget,
  loadWidgets,
  saveWidgets,
  sanitizeWidgetHtml,
  type DashboardWidget,
  type DashboardWidgetType
} from '@/dashboard/engine';
import {
  generateAssistantTurnWithFallback,
  generateImagePromptWithFallback,
  type AssistantAttachment,
  type OnboardingTranscriptLine
} from '@/api/ai';
import { getContentByKey, setRuntimeContentOverrides } from '@/content/library';
import { BUILD_TAG } from '@/meta/build';
import { getOrCreateVisitorId } from '@/personalization/visitor';
import { usePersonalizationStore } from '@/stores/personalization';
import { buildExperienceScene, type ExperiencePost } from '@/experience/session';
import { hashText } from '@/utils/seed';

interface TerminalLine {
  id: number;
  tone: 'system' | 'user' | 'signal' | 'assistant';
  text: string;
  createdAt: string;
  imageUrl?: string;
  imageAlt?: string;
}

interface PendingAttachment {
  id: string;
  name: string;
  mimeType: string;
  sizeBytes: number;
  kind: 'image' | 'text' | 'file';
  dataUrl?: string;
  textPreview?: string;
}

interface ImagePreviewState {
  lineId: number;
  url: string;
  alt: string;
}

type WidgetOutputFormat = 'brief' | 'bullets' | 'checklist';

interface WidgetBuildDraft {
  intent: string;
  scope: string;
  format: WidgetOutputFormat;
  title: string;
}

interface WidgetBuildSession {
  step: 'intent' | 'scope' | 'format' | 'title' | 'confirm';
  draft: WidgetBuildDraft;
}

interface WidgetEditorState {
  widgetId: string;
  title: string;
  prompt: string;
  outputStyle: WidgetOutputFormat;
  audience: string;
  html: string;
  configEntries: Array<{ key: string; value: string }>;
}

interface SavedConversation {
  id: string;
  title: string;
  updatedAt: string;
  transcript: TerminalLine[];
  suggestions: Array<{ label: string; action: string }>;
}

interface ConversationStorePayload {
  activeConversationId: string;
  conversations: SavedConversation[];
}

const CONVERSATION_STORAGE_KEY = 'mysite.assistant.conversations.v1';
const MAX_SAVED_CONVERSATIONS = 5;

const router = useRouter();
const route = useRoute();
const personalization = usePersonalizationStore();

const initializing = ref(true);
const initializationError = ref('');
const wordpressError = ref('');
const sceneNonce = ref(0);
const forcedFocus = ref('');
const commandInput = ref('');
const filePickerRef = ref<HTMLInputElement | null>(null);
const transcriptRef = ref<HTMLElement | null>(null);
const transcript = ref<TerminalLine[]>([]);
const widgets = ref<DashboardWidget[]>([]);
const widgetRefreshNonce = ref(0);
const widgetRefreshKeys = ref<Record<string, number>>({});
const widgetBuildSession = ref<WidgetBuildSession | null>(null);
const editingWidgetId = ref('');
const widgetEditor = ref<WidgetEditorState | null>(null);
const assistantStreaming = ref(false);
const assistantStreamPhase = ref('');
const assistantSuggestions = ref<Array<{ label: string; action: string }>>([]);
const conversationThreads = ref<SavedConversation[]>([]);
const activeConversationId = ref('');
const terminalExpanded = ref(false);
const transcriptHeight = ref(320);
const imageRenderPending = ref(false);
const imageRenderPrompt = ref('');
const mediaLoadState = ref<Record<number, 'loading' | 'ready' | 'error'>>({});
const blobMediaUrls = ref<string[]>([]);
const prefersReducedMotion = ref(false);
const commandHistory = ref<string[]>([]);
const commandHistoryCursor = ref(-1);
const pendingAttachments = ref<PendingAttachment[]>([]);
const imagePreview = ref<ImagePreviewState | null>(null);
const lineActionStatus = ref<Record<number, string>>({});
const sceneRefreshEpoch = ref(0);
const sceneStyleToken = ref(Math.floor(Math.random() * 1_000_000));
let motionMediaQuery: MediaQueryList | null = null;

const blueprint = computed(() => personalization.blueprint);
const visitorId = getOrCreateVisitorId();
const assistantPrimaryCta = {
  label: 'Access more AI tools + free web hosting',
  action: 'https://hiops.darkhorsevirtue.io'
} as const;
const appNavSections = [
  { id: 'home', label: 'Mission Hub', path: '/app/home' },
  { id: 'conversations', label: 'AI Conversations', path: '/app/conversations' },
  { id: 'skill-game', label: 'AI Skill Game', path: '/app/skill-game' },
  { id: 'widgets', label: 'Widget Studio', path: '/app/widgets' },
  { id: 'blog', label: 'Blog Feed', path: '/app/blog' }
] as const;
const missionNavigation = [
  { id: 'conversations', label: 'AI Conversations', path: '/app/conversations' },
  { id: 'skill-game', label: 'AI Skill Game', path: '/app/skill-game' },
  { id: 'blog', label: 'Blog Posts', path: '/app/blog' }
] as const;
type AppNavSectionId = (typeof appNavSections)[number]['id'];

function canUseStorage(): boolean {
  return typeof window !== 'undefined' && typeof localStorage !== 'undefined';
}

function cloneTerminalLine(line: TerminalLine): TerminalLine {
  return {
    id: line.id,
    tone: line.tone,
    text: line.text,
    createdAt: line.createdAt,
    imageUrl: line.imageUrl,
    imageAlt: line.imageAlt
  };
}

function rebuildMediaLoadStateFromTranscript(): void {
  const next: Record<number, 'loading' | 'ready' | 'error'> = {};
  for (const line of transcript.value) {
    if (line.imageUrl) {
      next[line.id] = 'loading';
    }
  }
  mediaLoadState.value = next;
}

function sanitizeTerminalLine(value: unknown): TerminalLine | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  const record = value as Record<string, unknown>;
  const idRaw = record.id;
  const id = typeof idRaw === 'number' ? idRaw : Number.parseInt(String(idRaw ?? ''), 10);
  const toneRaw = typeof record.tone === 'string' ? record.tone.trim() : '';
  const text = typeof record.text === 'string' ? record.text : '';
  const createdAtRaw = typeof record.createdAt === 'string' ? record.createdAt.trim() : '';
  const imageUrl = typeof record.imageUrl === 'string' ? record.imageUrl.trim() : '';
  const imageAlt = typeof record.imageAlt === 'string' ? record.imageAlt.trim() : '';

  if (!Number.isFinite(id) || !['system', 'user', 'signal', 'assistant'].includes(toneRaw) || !text.trim()) {
    return null;
  }

  const fallbackCreatedAt = new Date(id).toISOString();
  const parsedCreatedAt = new Date(createdAtRaw);

  return {
    id,
    tone: toneRaw as TerminalLine['tone'],
    text: text.trim(),
    createdAt: Number.isFinite(parsedCreatedAt.getTime()) ? parsedCreatedAt.toISOString() : fallbackCreatedAt,
    imageUrl: imageUrl || undefined,
    imageAlt: imageAlt || undefined
  };
}

function normalizeConversationTitle(value: unknown, fallback: string): string {
  if (typeof value !== 'string') {
    return fallback;
  }

  const cleaned = value.trim();
  return cleaned.length > 0 ? cleaned.slice(0, 44) : fallback;
}

function buildConversationTitleFromTranscript(lines: TerminalLine[]): string {
  const candidate = lines.find((line) => line.tone === 'user' && line.text.trim().length > 0)?.text
    ?? lines.find((line) => line.tone === 'assistant' && line.text.trim().length > 0)?.text
    ?? '';

  const compact = candidate.replace(/\s+/g, ' ').trim();
  if (!compact) {
    return 'New Conversation';
  }

  return compact.slice(0, 44);
}

function toneLabel(tone: TerminalLine['tone']): string {
  if (tone === 'assistant') return 'AI';
  if (tone === 'user') return 'You';
  if (tone === 'signal') return 'System Signal';
  return 'System';
}

const lineTimeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: 'numeric',
  minute: '2-digit'
});

function formatLineTime(line: TerminalLine): string {
  const parsed = new Date(line.createdAt);
  if (!Number.isFinite(parsed.getTime())) {
    return '--:--';
  }
  return lineTimeFormatter.format(parsed);
}

function formatRelativeTime(isoTime: string): string {
  const parsed = new Date(isoTime);
  if (!Number.isFinite(parsed.getTime())) {
    return 'just now';
  }

  const diffMs = Date.now() - parsed.getTime();
  const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));
  if (diffMinutes < 1) return 'just now';
  if (diffMinutes < 60) return `${diffMinutes}m ago`;
  const diffHours = Math.floor(diffMinutes / 60);
  if (diffHours < 24) return `${diffHours}h ago`;
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays < 7) return `${diffDays}d ago`;
  return parsed.toLocaleDateString();
}

function formatAttachmentSize(sizeBytes: number): string {
  if (sizeBytes < 1024) return `${sizeBytes} B`;
  if (sizeBytes < 1024 * 1024) return `${(sizeBytes / 1024).toFixed(1)} KB`;
  return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
}

function fileKindFromMime(mimeType: string): PendingAttachment['kind'] {
  if (mimeType.startsWith('image/')) return 'image';
  if (mimeType.startsWith('text/')) return 'text';
  return 'file';
}

function readFileAsDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Unable to read file as data URL.'));
    reader.onload = () => {
      resolve(typeof reader.result === 'string' ? reader.result : '');
    };
    reader.readAsDataURL(file);
  });
}

function readFileAsText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('Unable to read file as text.'));
    reader.onload = () => {
      resolve(typeof reader.result === 'string' ? reader.result : '');
    };
    reader.readAsText(file);
  });
}

function clearPendingAttachments(): void {
  pendingAttachments.value = [];
  if (filePickerRef.value) {
    filePickerRef.value.value = '';
  }
}

function removePendingAttachment(attachmentId: string): void {
  pendingAttachments.value = pendingAttachments.value.filter((item) => item.id !== attachmentId);
}

function openFilePicker(): void {
  filePickerRef.value?.click();
}

function toAssistantAttachments(items: PendingAttachment[]): AssistantAttachment[] {
  return items.map((item) => ({
    kind: item.kind,
    name: item.name,
    mimeType: item.mimeType,
    sizeBytes: item.sizeBytes,
    dataUrl: item.dataUrl,
    textPreview: item.textPreview
  }));
}

function setLineActionStatus(lineId: number, status: string): void {
  lineActionStatus.value = {
    ...lineActionStatus.value,
    [lineId]: status
  };
  window.setTimeout(() => {
    if (lineActionStatus.value[lineId] === status) {
      const next = { ...lineActionStatus.value };
      delete next[lineId];
      lineActionStatus.value = next;
    }
  }, 1800);
}

function openImagePreview(line: TerminalLine): void {
  if (!line.imageUrl) {
    return;
  }
  imagePreview.value = {
    lineId: line.id,
    url: line.imageUrl,
    alt: line.imageAlt || line.text || 'Generated image'
  };
}

function closeImagePreview(): void {
  imagePreview.value = null;
}

function safeFilename(base: string, ext: string): string {
  const clean = base
    .replace(/[^\w\s.-]/g, ' ')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase()
    .slice(0, 50);
  return `${clean || 'asset'}-${Date.now()}.${ext}`;
}

function triggerDownloadFromUrl(url: string, filename: string): void {
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.rel = 'noopener noreferrer';
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
}

function triggerDownloadFromBlob(blob: Blob, filename: string): void {
  const objectUrl = URL.createObjectURL(blob);
  triggerDownloadFromUrl(objectUrl, filename);
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}

async function copyLineText(line: TerminalLine): Promise<void> {
  try {
    await navigator.clipboard.writeText(line.text);
    setLineActionStatus(line.id, 'Copied');
  } catch {
    setLineActionStatus(line.id, 'Copy blocked');
  }
}

async function downloadLineImage(line: TerminalLine): Promise<void> {
  if (!line.imageUrl) {
    return;
  }

  try {
    if (line.imageUrl.startsWith('data:image/')) {
      triggerDownloadFromUrl(line.imageUrl, safeFilename(line.imageAlt || 'image', 'png'));
      setLineActionStatus(line.id, 'Downloaded');
      return;
    }

    const response = await fetch(line.imageUrl, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error('fetch failed');
    }
    const blob = await response.blob();
    const ext = blob.type.includes('jpeg') ? 'jpg' : blob.type.includes('webp') ? 'webp' : 'png';
    triggerDownloadFromBlob(blob, safeFilename(line.imageAlt || 'image', ext));
    setLineActionStatus(line.id, 'Downloaded');
  } catch {
    setLineActionStatus(line.id, 'Download failed');
  }
}

async function copyLineImage(line: TerminalLine): Promise<void> {
  if (!line.imageUrl) {
    return;
  }

  try {
    if (typeof ClipboardItem === 'undefined') {
      await navigator.clipboard.writeText(line.imageUrl);
      setLineActionStatus(line.id, 'URL copied');
      return;
    }

    const response = await fetch(line.imageUrl, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error('fetch failed');
    }
    const blob = await response.blob();
    await navigator.clipboard.write([
      new ClipboardItem({
        [blob.type || 'image/png']: blob
      })
    ]);
    setLineActionStatus(line.id, 'Image copied');
  } catch {
    try {
      await navigator.clipboard.writeText(line.imageUrl);
      setLineActionStatus(line.id, 'URL copied');
    } catch {
      setLineActionStatus(line.id, 'Copy blocked');
    }
  }
}

function toPreviewLine(preview: ImagePreviewState): TerminalLine {
  return {
    id: preview.lineId,
    tone: 'assistant',
    text: preview.alt,
    createdAt: new Date().toISOString(),
    imageUrl: preview.url,
    imageAlt: preview.alt
  };
}

async function copyPreviewImage(): Promise<void> {
  if (!imagePreview.value) {
    return;
  }
  await copyLineImage(toPreviewLine(imagePreview.value));
}

async function downloadPreviewImage(): Promise<void> {
  if (!imagePreview.value) {
    return;
  }
  await downloadLineImage(toPreviewLine(imagePreview.value));
}

function openPreviewImageTab(): void {
  if (!imagePreview.value) {
    return;
  }
  window.open(imagePreview.value.url, '_blank', 'noopener,noreferrer');
}

async function handleFileSelection(event: Event): Promise<void> {
  const target = event.target;
  if (!(target instanceof HTMLInputElement) || !target.files || target.files.length === 0) {
    return;
  }

  const selected = Array.from(target.files).slice(0, 3);
  const nextAttachments: PendingAttachment[] = [];

  for (const file of selected) {
    const mimeType = file.type || 'application/octet-stream';
    const kind = fileKindFromMime(mimeType);
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const attachment: PendingAttachment = {
      id,
      name: file.name || 'upload.bin',
      mimeType,
      sizeBytes: file.size,
      kind
    };

    if (kind === 'image' && file.size <= 4 * 1024 * 1024) {
      try {
        const dataUrl = await readFileAsDataUrl(file);
        if (dataUrl.startsWith('data:image/')) {
          attachment.dataUrl = dataUrl;
        }
      } catch {
        // Keep metadata-only attachment if browser read fails.
      }
    } else if (kind === 'text' && file.size <= 256 * 1024) {
      try {
        const text = await readFileAsText(file);
        const compact = text.replace(/\s+/g, ' ').trim().slice(0, 800);
        if (compact) {
          attachment.textPreview = compact;
        }
      } catch {
        // Keep metadata-only attachment if text read fails.
      }
    }

    nextAttachments.push(attachment);
  }

  pendingAttachments.value = nextAttachments;
  const summary = nextAttachments
    .map((file) => `${file.name} (${formatAttachmentSize(file.sizeBytes)})`)
    .join(', ');
  addLine('signal', `Attached ${nextAttachments.length} file${nextAttachments.length > 1 ? 's' : ''}: ${summary}`);
  for (const file of nextAttachments) {
    if (file.kind === 'image' && file.dataUrl) {
      addLine('assistant', `Attachment preview · ${file.name}`, {
        imageUrl: file.dataUrl,
        imageAlt: file.name
      });
    }
  }
}

function isImageConversationRequest(message: string): boolean {
  return /\b(image|illustration|render|draw|logo|poster|photo|artwork|cover art|thumbnail|portrait)\b/i.test(message);
}

function isHostingIntent(message: string): boolean {
  return /\b(host|hosting|server|domain|deploy|deployment|vps|cloud|pro suite|dark horse|whmcs)\b/i.test(message);
}

function needsImageCapabilityOverride(message: string): boolean {
  return /\b(can(?:not|'t)|unable|cannot)\b[\s\S]{0,40}\b(image|visual|photo|render)\b/i.test(message);
}

function rememberBlobUrl(url: string): void {
  if (!url.startsWith('blob:')) {
    return;
  }
  blobMediaUrls.value.push(url);
}

function canRenderImageUrl(url: string, timeoutMs = 9000): Promise<boolean> {
  return new Promise((resolve) => {
    const image = new Image();
    let settled = false;

    const done = (value: boolean) => {
      if (settled) {
        return;
      }
      settled = true;
      window.clearTimeout(timer);
      resolve(value);
    };

    const timer = window.setTimeout(() => done(false), timeoutMs);
    image.onload = () => done(true);
    image.onerror = () => done(false);
    image.referrerPolicy = 'no-referrer';
    image.src = url;
  });
}

async function fetchImageThroughProxy(sourceUrl: string): Promise<string | null> {
  const target = sourceUrl.trim();
  if (!target) {
    return null;
  }

  const proxyUrl = `/api/ai/image-proxy.php?url=${encodeURIComponent(target)}`;
  try {
    const response = await fetch(proxyUrl, {
      method: 'GET',
      cache: 'no-store'
    });
    if (!response.ok) {
      return null;
    }

    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.toLowerCase().includes('image/')) {
      return null;
    }

    const blob = await response.blob();
    if (blob.size === 0) {
      return null;
    }

    const objectUrl = URL.createObjectURL(blob);
    rememberBlobUrl(objectUrl);
    return objectUrl;
  } catch {
    return null;
  }
}

async function resolveAssistantMediaUrl(url: string, promptContext: string): Promise<string> {
  const cleaned = url.trim();
  if (!cleaned) {
    return buildTranscriptImageFallback(promptContext);
  }

  try {
    if (cleaned.startsWith('data:image/') && !cleaned.startsWith('data:image/svg+xml')) {
      return cleaned;
    }

    if (cleaned.startsWith('/api/ai/image-proxy.php?')) {
      const proxied = await fetch(cleaned, { method: 'GET', cache: 'no-store' });
      if (!proxied.ok) {
        return buildTranscriptImageFallback(promptContext);
      }
      const type = proxied.headers.get('content-type') ?? '';
      if (!type.toLowerCase().includes('image/')) {
        return buildTranscriptImageFallback(promptContext);
      }
      const blob = await proxied.blob();
      if (!blob.size) {
        return buildTranscriptImageFallback(promptContext);
      }
      const objectUrl = URL.createObjectURL(blob);
      rememberBlobUrl(objectUrl);
      return objectUrl;
    }

    if (/^https:\/\//i.test(cleaned)) {
      if (await canRenderImageUrl(cleaned)) {
        return cleaned;
      }
      const proxied = await fetchImageThroughProxy(cleaned);
      if (proxied) {
        return proxied;
      }
      return ensureGeneratedImageForPrompt(promptContext);
    }

    if (cleaned.startsWith('data:image/svg+xml')) {
      const generated = await ensureGeneratedImageForPrompt(promptContext);
      return generated || cleaned;
    }

    return cleaned;
  } catch {
    return buildTranscriptImageFallback(promptContext);
  }
}

async function ensureGeneratedImageForPrompt(prompt: string): Promise<string> {
  try {
    const response = await fetch('/api/ai/image.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ prompt }),
      cache: 'no-store'
    });
    const payload = (await response.json().catch(() => null)) as Record<string, unknown> | null;
    const endpointImage = payload && typeof payload.imageDataUrl === 'string' ? payload.imageDataUrl.trim() : '';
    if (endpointImage.startsWith('data:image/')) {
      const provider = payload && typeof payload.provider === 'string' ? payload.provider.trim() : '';
      const model = payload && typeof payload.model === 'string' ? payload.model.trim() : '';
      if (provider || model) {
        addLine('signal', `Image runtime: ${provider || 'provider'} ${model || ''}`.trim());
      }
      return endpointImage;
    }
    const endpointError = payload && typeof payload.error === 'string' ? payload.error.trim() : '';
    if (endpointError) {
      addLine('signal', `Image runtime notice: ${endpointError}`);
    }
    const attempts = payload && Array.isArray(payload.attempts) ? payload.attempts : [];
    if (attempts.length > 0) {
      const summary = attempts
        .map((entry) => {
          if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
            return null;
          }
          const item = entry as Record<string, unknown>;
          const model = typeof item.model === 'string' ? item.model : '';
          const status = typeof item.status === 'string' ? item.status : '';
          if (!model && !status) {
            return null;
          }
          return `${model || 'model'}:${status || 'unknown'}`;
        })
        .filter((part): part is string => part !== null)
        .slice(0, 3);
      if (summary.length > 0) {
        addLine('signal', `Image attempts: ${summary.join(', ')}`);
      }
    }
  } catch {
    addLine('signal', 'Image runtime notice: endpoint request failed. Showing local generated fallback.');
  }

  return buildTranscriptImageFallback(prompt);
}

function createConversation(title = 'New Conversation'): SavedConversation {
  const now = new Date().toISOString();
  const token = Math.random().toString(36).slice(2, 8).toUpperCase();
  return {
    id: `C-${token}`,
    title,
    updatedAt: now,
    transcript: [],
    suggestions: []
  };
}

function normalizeConversation(value: unknown): SavedConversation | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  const record = value as Record<string, unknown>;
  const id = typeof record.id === 'string' ? record.id.trim() : '';
  if (!id) {
    return null;
  }

  const transcriptRaw = Array.isArray(record.transcript) ? record.transcript : [];
  const nextTranscript = transcriptRaw
    .map((line) => sanitizeTerminalLine(line))
    .filter((line): line is TerminalLine => line !== null)
    .slice(-120);

  const suggestionsRaw = Array.isArray(record.suggestions) ? record.suggestions : [];
  const suggestions = suggestionsRaw
    .map((entry) => {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        return null;
      }

      const item = entry as Record<string, unknown>;
      const label = typeof item.label === 'string' ? item.label.trim() : '';
      const action = typeof item.action === 'string' ? item.action.trim() : '';
      if (!label || !action) {
        return null;
      }

      return { label, action };
    })
    .filter((entry): entry is { label: string; action: string } => entry !== null)
    .slice(0, 3);

  const updatedAt = typeof record.updatedAt === 'string' && record.updatedAt.trim()
    ? record.updatedAt.trim()
    : new Date().toISOString();
  const fallbackTitle = buildConversationTitleFromTranscript(nextTranscript);

  return {
    id,
    title: normalizeConversationTitle(record.title, fallbackTitle),
    updatedAt,
    transcript: nextTranscript.map(cloneTerminalLine),
    suggestions
  };
}

function persistConversationState(): void {
  if (!canUseStorage()) {
    return;
  }

  try {
    const normalized = conversationThreads.value
      .slice()
      .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt))
      .slice(0, MAX_SAVED_CONVERSATIONS)
      .map((conversation) => ({
        ...conversation,
        transcript: conversation.transcript.map(cloneTerminalLine),
        suggestions: conversation.suggestions.slice(0, 3)
      }));

    if (normalized.length === 0) {
      localStorage.removeItem(CONVERSATION_STORAGE_KEY);
      return;
    }

    if (!normalized.some((conversation) => conversation.id === activeConversationId.value)) {
      activeConversationId.value = normalized[0].id;
    }

    const payload: ConversationStorePayload = {
      activeConversationId: activeConversationId.value,
      conversations: normalized
    };

    conversationThreads.value = normalized;
    localStorage.setItem(CONVERSATION_STORAGE_KEY, JSON.stringify(payload));
  } catch {
    // Ignore storage quota or access errors; in-memory conversation remains usable.
  }
}

function initializeConversationThreads(): void {
  if (!canUseStorage()) {
    const created = createConversation();
    conversationThreads.value = [created];
    activeConversationId.value = created.id;
    transcript.value = [];
    mediaLoadState.value = {};
    assistantSuggestions.value = [];
    return;
  }

  try {
    const raw = localStorage.getItem(CONVERSATION_STORAGE_KEY);
    if (!raw) {
      const created = createConversation();
      conversationThreads.value = [created];
      activeConversationId.value = created.id;
      transcript.value = [];
      mediaLoadState.value = {};
      assistantSuggestions.value = [];
      persistConversationState();
      return;
    }

    const decoded = JSON.parse(raw) as unknown;
    if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) {
      throw new Error('Invalid conversation payload');
    }

    const payload = decoded as Record<string, unknown>;
    const conversationsRaw = Array.isArray(payload.conversations) ? payload.conversations : [];
    const conversations = conversationsRaw
      .map((entry) => normalizeConversation(entry))
      .filter((entry): entry is SavedConversation => entry !== null)
      .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt))
      .slice(0, MAX_SAVED_CONVERSATIONS);

    if (conversations.length === 0) {
      const created = createConversation();
      conversationThreads.value = [created];
      activeConversationId.value = created.id;
      transcript.value = [];
      mediaLoadState.value = {};
      assistantSuggestions.value = [];
      persistConversationState();
      return;
    }

    const requestedActiveId = typeof payload.activeConversationId === 'string' ? payload.activeConversationId.trim() : '';
    const selected = conversations.find((conversation) => conversation.id === requestedActiveId) ?? conversations[0];

    conversationThreads.value = conversations;
    activeConversationId.value = selected.id;
    transcript.value = selected.transcript.map(cloneTerminalLine);
    rebuildMediaLoadStateFromTranscript();
    assistantSuggestions.value = selected.suggestions.slice(0, 3);
    persistConversationState();
  } catch {
    const created = createConversation();
    conversationThreads.value = [created];
    activeConversationId.value = created.id;
    transcript.value = [];
    mediaLoadState.value = {};
    assistantSuggestions.value = [];
    persistConversationState();
  }
}

function persistActiveConversation(): void {
  const activeId = activeConversationId.value;
  if (!activeId) {
    return;
  }

  const index = conversationThreads.value.findIndex((conversation) => conversation.id === activeId);
  if (index === -1) {
    return;
  }

  const existing = conversationThreads.value[index];
  const inferredTitle = buildConversationTitleFromTranscript(transcript.value);
  const nextTitle = inferredTitle && existing.title === 'New Conversation'
    ? inferredTitle
    : existing.title || inferredTitle || 'New Conversation';

  const nextConversation: SavedConversation = {
    ...existing,
    title: nextTitle.slice(0, 44),
    updatedAt: new Date().toISOString(),
    transcript: transcript.value.map(cloneTerminalLine).slice(-120),
    suggestions: assistantSuggestions.value.slice(0, 3)
  };

  conversationThreads.value.splice(index, 1, nextConversation);
  persistConversationState();
}

function startNewConversation(): void {
  const created = createConversation();
  conversationThreads.value = [created, ...conversationThreads.value];
  activeConversationId.value = created.id;
  transcript.value = [];
  mediaLoadState.value = {};
  assistantSuggestions.value = [];
  persistConversationState();
  addLine('signal', `Conversation ${created.id} active. Ask anything or use /widget build.`);
}

function switchConversation(conversationId: string): void {
  if (conversationId === activeConversationId.value) {
    return;
  }

  const target = conversationThreads.value.find((conversation) => conversation.id === conversationId);
  if (!target) {
    return;
  }

  activeConversationId.value = target.id;
  transcript.value = target.transcript.map(cloneTerminalLine);
  assistantSuggestions.value = target.suggestions.slice(0, 3);
  rebuildMediaLoadStateFromTranscript();
  scrollTranscriptToEnd();
  persistConversationState();
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
}

function clampColor(value: number): number {
  return Math.max(0, Math.min(255, Math.round(value)));
}

function buildTranscriptImageFallback(label: string): string {
  const safeLabel = label
    .replace(/[<>]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 88) || 'Image preview unavailable';
  const svg = [
    '<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="640" viewBox="0 0 1024 640">',
    '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#02121f"/><stop offset="100%" stop-color="#0b1021"/></linearGradient></defs>',
    '<rect width="1024" height="640" fill="url(#g)"/>',
    '<circle cx="140" cy="110" r="150" fill="rgba(6,182,212,0.22)"/>',
    '<circle cx="892" cy="560" r="210" fill="rgba(16,185,129,0.2)"/>',
    '<rect x="72" y="72" width="880" height="496" rx="20" fill="rgba(2,6,23,0.6)" stroke="rgba(110,231,255,0.45)" stroke-width="2"/>',
    '<text x="114" y="188" fill="#67e8f9" font-family="monospace" font-size="30">MULTIMODAL IMAGE FALLBACK</text>',
    `<text x="114" y="276" fill="#d1fae5" font-family="monospace" font-size="34">${safeLabel}</text>`,
    '<text x="114" y="342" fill="#86efac" font-family="monospace" font-size="22">Synthesized concept visual generated locally for this prompt.</text>',
    '</svg>'
  ].join('');
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

function handleTranscriptImageError(event: Event, line: TerminalLine): void {
  const target = event.target;
  if (!(target instanceof HTMLImageElement)) {
    return;
  }

  if (target.dataset.fallbackApplied === '1') {
    mediaLoadState.value = {
      ...mediaLoadState.value,
      [line.id]: 'error'
    };
    return;
  }

  const fallback = buildTranscriptImageFallback(line.imageAlt || line.text || 'Generated image');
  target.dataset.fallbackApplied = '1';
  target.src = fallback;
  line.imageUrl = fallback;
  mediaLoadState.value = {
    ...mediaLoadState.value,
    [line.id]: 'error'
  };
  persistActiveConversation();
}

function handleTranscriptImageLoad(line: TerminalLine): void {
  mediaLoadState.value = {
    ...mediaLoadState.value,
    [line.id]: 'ready'
  };
}

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const normalized = hex.trim().replace('#', '');
  if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
    return null;
  }

  return {
    r: Number.parseInt(normalized.slice(0, 2), 16),
    g: Number.parseInt(normalized.slice(2, 4), 16),
    b: Number.parseInt(normalized.slice(4, 6), 16)
  };
}

function rgbToCss(rgb: { r: number; g: number; b: number }): string {
  return `${clampColor(rgb.r)}, ${clampColor(rgb.g)}, ${clampColor(rgb.b)}`;
}

function mixWithWhite(rgb: { r: number; g: number; b: number }, amount: number): { r: number; g: number; b: number } {
  return {
    r: rgb.r + (255 - rgb.r) * amount,
    g: rgb.g + (255 - rgb.g) * amount,
    b: rgb.b + (255 - rgb.b) * amount
  };
}

function mixRgb(
  base: { r: number; g: number; b: number },
  target: { r: number; g: number; b: number },
  amount: number
): { r: number; g: number; b: number } {
  return {
    r: base.r + (target.r - base.r) * amount,
    g: base.g + (target.g - base.g) * amount,
    b: base.b + (target.b - base.b) * amount
  };
}

function firstStringModuleProp(propName: string): string {
  const modules = blueprint.value?.modules ?? [];
  for (const module of modules) {
    const value = module.props[propName];
    if (typeof value === 'string' && value.trim().length > 0) {
      return value.trim();
    }
  }

  return '';
}

function firstStringArrayModuleProp(propName: string): string[] {
  const modules = blueprint.value?.modules ?? [];
  for (const module of modules) {
    const value = module.props[propName];
    if (!Array.isArray(value)) {
      continue;
    }

    const clean = value
      .filter((item): item is string => typeof item === 'string')
      .map((item) => item.trim())
      .filter(Boolean);

    if (clean.length > 0) {
      return clean;
    }
  }

  return [];
}

function scrollTranscriptToEnd(): void {
  nextTick(() => {
    if (!transcriptRef.value) {
      return;
    }

    transcriptRef.value.scrollTop = transcriptRef.value.scrollHeight;
  });
}

function addLine(
  tone: TerminalLine['tone'],
  text: string,
  media?: { imageUrl?: string; imageAlt?: string }
): number {
  const now = Date.now();
  const lineId = now + Math.floor(Math.random() * 1000);
  transcript.value.push({
    id: lineId,
    tone,
    text,
    createdAt: new Date(now).toISOString(),
    imageUrl: media?.imageUrl,
    imageAlt: media?.imageAlt
  });
  if (media?.imageUrl) {
    mediaLoadState.value = {
      ...mediaLoadState.value,
      [lineId]: 'loading'
    };
  }
  scrollTranscriptToEnd();
  persistActiveConversation();
  return lineId;
}

function appendLine(lineId: number, textChunk: string): void {
  const line = transcript.value.find((entry) => entry.id === lineId);
  if (!line) {
    return;
  }

  line.text += textChunk;
  scrollTranscriptToEnd();
  persistActiveConversation();
}

function isExternalUrl(url: string): boolean {
  return /^https?:\/\//i.test(url);
}

async function openAction(url: string): Promise<void> {
  if (!url) {
    return;
  }

  if (url === 'ask-hosting') {
    window.open('https://hiops.darkhorsevirtue.io', '_blank', 'noopener,noreferrer');
    addLine('signal', 'Routing you to HiOps Pro Suite for hosting onboarding...');
    await runAssistantConversation('I need hosting onboarding help through Dark Horse Virtue Pro Suite.');
    return;
  }

  if (url === 'ask-ai-access') {
    await runAssistantConversation('Help me map an AI access plan for this experience.');
    return;
  }

  if (url === 'reopen-onboarding') {
    await router.push('/onboarding?force=1');
    return;
  }

  if (url.startsWith('/')) {
    await router.push(url === '/app' ? '/app/home' : url);
    return;
  }

  if (isExternalUrl(url)) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

function buildAssistantTranscript(): OnboardingTranscriptLine[] {
  return transcript.value
    .slice(-16)
    .map((line) => {
      const role: OnboardingTranscriptLine['role'] = line.tone === 'user'
        ? 'user'
        : line.tone === 'assistant'
          ? 'assistant'
          : 'system';

      return {
        role,
        text: line.text
      };
    });
}

async function streamAssistantMessage(message: string): Promise<void> {
  const text = message.trim();
  if (!text) {
    return;
  }

  const lineId = addLine('assistant', '');
  const chunks = text.split(/(\s+)/).filter((chunk) => chunk.length > 0);

  for (const chunk of chunks) {
    appendLine(lineId, chunk);
    const isBreak = chunk.trim().length === 0;
    await new Promise((resolve) => window.setTimeout(resolve, isBreak ? 10 : 22));
  }
}

async function runAssistantConversation(
  userInput: string,
  options?: { forceImage?: boolean; attachments?: AssistantAttachment[] }
): Promise<void> {
  const attachments = options?.attachments?.slice(0, 3) ?? [];
  if (attachments.length > 0) {
    addLine('signal', `Attachment context included: ${attachments.length} file${attachments.length > 1 ? 's' : ''}.`);
  }
  const expectsImage = Boolean(options?.forceImage) || isImageConversationRequest(userInput);
  assistantStreaming.value = true;
  imageRenderPending.value = expectsImage;
  imageRenderPrompt.value = expectsImage ? userInput.trim() : '';
  assistantStreamPhase.value = expectsImage
    ? 'AI stream: composing multimodal image...'
    : 'AI stream: analyzing request...';
  assistantSuggestions.value = [];
  addLine('signal', assistantStreamPhase.value);

  try {
    const result = await generateAssistantTurnWithFallback({
      userMessage: userInput,
      transcript: buildAssistantTranscript(),
      visitorId,
      variantNonce: sceneNonce.value,
      attachments
    });

    assistantStreamPhase.value = expectsImage
      ? (result.source === 'backend' ? 'AI stream: finalizing visual response...' : 'Fallback stream: finalizing visual response...')
      : (result.source === 'backend' ? 'AI stream: rendering response...' : 'Fallback stream: rendering response...');

    let assistantMessage = result.assistantMessage;
    if (expectsImage && needsImageCapabilityOverride(assistantMessage)) {
      assistantMessage = 'Image request captured. Rendering an in-thread preview now. Ask for style, angle, or mood changes to regenerate.';
    }
    await streamAssistantMessage(assistantMessage);

    if (expectsImage) {
      const generatedImage = await ensureGeneratedImageForPrompt(userInput);
      addLine('assistant', 'Generated image preview', {
        imageUrl: generatedImage,
        imageAlt: 'Generated image preview'
      });
    } else {
      const mediaItems = result.media.filter((item) => item.type === 'image');
      for (const item of mediaItems) {
        const resolvedImage = await resolveAssistantMediaUrl(item.url, userInput);
        addLine('assistant', item.alt || 'Generated image', {
          imageUrl: resolvedImage,
          imageAlt: item.alt || 'Generated image'
        });
      }
    }

    const suggestions = result.suggestions.slice(0, 3);
    if (isHostingIntent(userInput) && !suggestions.some((entry) => entry.action.includes('hiops.darkhorsevirtue.io'))) {
      suggestions.unshift({ label: 'Open Pro Suite', action: 'https://hiops.darkhorsevirtue.io' });
    }
    assistantSuggestions.value = suggestions.slice(0, 3);
    if (assistantSuggestions.value.length > 0) {
      addLine('signal', 'Suggestions ready. Tap a quick action below.');
    }
    persistActiveConversation();
  } finally {
    assistantStreaming.value = false;
    assistantStreamPhase.value = '';
    imageRenderPending.value = false;
    imageRenderPrompt.value = '';
  }
}

function persistWidgets(): void {
  saveWidgets(widgets.value);
}

function deployWidget(widget: DashboardWidget, sourceLabel = 'AI CLI'): boolean {
  if (widgets.value.length >= MAX_DASHBOARD_WIDGETS) {
    addLine(
      'system',
      `Widget limit reached (${MAX_DASHBOARD_WIDGETS}/${MAX_DASHBOARD_WIDGETS}). Remove one with /widget remove <id> or /widget clear.`
    );
    return false;
  }

  widgets.value = [widget, ...widgets.value].slice(0, MAX_DASHBOARD_WIDGETS);
  persistWidgets();
  addLine('signal', `${sourceLabel} deployed widget ${widget.id} · ${widget.title}`);
  return true;
}

function parseWidgetType(rawType: string): DashboardWidgetType | null {
  const normalized = rawType.trim().toLowerCase().replace('-', '');
  if (
    normalized === 'weather' ||
    normalized === 'horoscope' ||
    normalized === 'fashion' ||
    normalized === 'sports' ||
    normalized === 'customhtml'
  ) {
    return normalized === 'customhtml' ? 'customHtml' : (normalized as DashboardWidgetType);
  }

  return null;
}

function parseFocusInput(input: string): string {
  const words = input
    .split(/[\s,]+/)
    .map((token) => token.trim())
    .filter((token) => token.length > 2);

  if (words.length === 0) {
    return '';
  }

  return words.slice(0, 2).join(' ');
}

function defaultWidgetBuildDraft(): WidgetBuildDraft {
  return {
    intent: '',
    scope: '',
    format: 'brief',
    title: ''
  };
}

function parseWidgetOutputFormat(input: string): WidgetOutputFormat | null {
  const normalized = input.trim().toLowerCase();
  if (!normalized) {
    return null;
  }

  if (normalized === 'brief' || normalized === 'concise' || normalized === 'paragraph') {
    return 'brief';
  }

  if (normalized === 'bullets' || normalized === 'bullet' || normalized === 'list') {
    return 'bullets';
  }

  if (normalized === 'checklist' || normalized === 'tasks' || normalized === 'todo') {
    return 'checklist';
  }

  return null;
}

function formatInstruction(format: WidgetOutputFormat): string {
  if (format === 'bullets') {
    return 'Use a short heading sentence and then a tight bullet list of actionable points.';
  }

  if (format === 'checklist') {
    return 'Return a practical checklist with concise, execution-focused tasks.';
  }

  return 'Return one concise answer with direct, practical guidance.';
}

function deriveWidgetTitle(input: string): string {
  const cleaned = input
    .replace(/[^\w\s-]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!cleaned) {
    return '';
  }

  const words = cleaned.split(' ').slice(0, 4);
  const titled = words.map((word) => `${word.charAt(0).toUpperCase()}${word.slice(1).toLowerCase()}`);
  return titled.join(' ');
}

function composePromptFromBuildDraft(draft: WidgetBuildDraft): string {
  const scope = draft.scope || 'general audience';
  return [
    `Widget goal: ${draft.intent}.`,
    `Context/focus: ${scope}.`,
    `Output requirement: ${formatInstruction(draft.format)}`,
    'Keep language clear, modern, and useful in a dashboard card.'
  ].join(' ');
}

function widgetBuildSummary(draft: WidgetBuildDraft): string {
  return `Intent="${draft.intent}" · Scope="${draft.scope || 'general'}" · Format=${draft.format} · Title="${draft.title || 'auto'}"`;
}

function isWidgetBuildIntentRequest(input: string): boolean {
  const normalized = input.trim().toLowerCase();
  if (!normalized || normalized.startsWith('/')) {
    return false;
  }

  const hasBuildVerb = /\b(build|create|make|generate|design|craft)\b/.test(normalized);
  const mentionsWidget = /\bwidget\b/.test(normalized);
  return hasBuildVerb && mentionsWidget;
}

function extractIntentFromBuildRequest(input: string): string {
  return input
    .replace(/\b(please|can you|could you|would you)\b/gi, ' ')
    .replace(/\b(build|create|make|generate|design|craft)\b/gi, ' ')
    .replace(/\b(widget|for me|a|an|the)\b/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function startWidgetBuildMode(initialIntent = ''): boolean {
  if (widgets.value.length >= MAX_DASHBOARD_WIDGETS) {
    addLine(
      'system',
      `Widget limit reached (${MAX_DASHBOARD_WIDGETS}/${MAX_DASHBOARD_WIDGETS}). Remove one with /widget remove <id> or /widget clear.`
    );
    return false;
  }

  const draft = defaultWidgetBuildDraft();
  const normalizedIntent = initialIntent.trim();
  if (normalizedIntent) {
    draft.intent = normalizedIntent;
    draft.title = deriveWidgetTitle(normalizedIntent);
  }

  widgetBuildSession.value = {
    step: normalizedIntent ? 'scope' : 'intent',
    draft
  };

  addLine('signal', 'Widget build mode enabled. I will ask a few short questions.');
  if (!normalizedIntent) {
    addLine('system', '1/4 What should this widget do for the user?');
  } else {
    addLine('system', `1/4 Goal captured: "${normalizedIntent}"`);
    addLine('system', '2/4 What should it focus on (city, topic, audience, source)?');
  }
  addLine('system', 'Use /widget cancel anytime to exit build mode.');
  return true;
}

function cancelWidgetBuildMode(): void {
  widgetBuildSession.value = null;
  addLine('system', 'Widget build mode cancelled.');
}

function handleWidgetBuildModeInput(input: string): boolean {
  const session = widgetBuildSession.value;
  if (!session) {
    return false;
  }

  const trimmed = input.trim();
  const normalized = trimmed.toLowerCase();

  if (normalized === '/widget cancel' || normalized === '/cancel') {
    cancelWidgetBuildMode();
    return true;
  }

  if (normalized === '/help') {
    addLine('system', 'Build mode active. Answer the current question, or use /widget cancel.');
    addLine('system', 'Formats: brief, bullets, checklist.');
    return true;
  }

  if (normalized.startsWith('/widget ')) {
    addLine('system', 'Build mode is active. Finish these questions or use /widget cancel.');
    return true;
  }

  if (trimmed.startsWith('/')) {
    return false;
  }

  if (session.step === 'intent') {
    if (trimmed.length < 6) {
      addLine('system', 'Please give a bit more detail for the widget goal.');
      return true;
    }

    session.draft.intent = trimmed;
    if (!session.draft.title) {
      session.draft.title = deriveWidgetTitle(trimmed);
    }
    session.step = 'scope';
    addLine('system', '2/4 What should it focus on (city, topic, audience, source)?');
    return true;
  }

  if (session.step === 'scope') {
    session.draft.scope = trimmed || 'general audience';
    session.step = 'format';
    addLine('system', '3/4 Preferred output format: brief, bullets, or checklist?');
    return true;
  }

  if (session.step === 'format') {
    const format = parseWidgetOutputFormat(trimmed);
    if (!format) {
      addLine('system', 'Choose one: brief, bullets, checklist.');
      return true;
    }

    session.draft.format = format;
    session.step = 'title';
    addLine('system', `4/4 Widget title (or type "auto"): current="${session.draft.title || 'auto'}"`);
    return true;
  }

  if (session.step === 'title') {
    if (normalized === 'auto') {
      session.draft.title = deriveWidgetTitle(session.draft.intent);
    } else if (trimmed) {
      session.draft.title = trimmed;
    }

    session.step = 'confirm';
    addLine('system', `Review: ${widgetBuildSummary(session.draft)}`);
    addLine('system', 'Deploy this widget now? (yes/no)');
    return true;
  }

  if (session.step === 'confirm') {
    if (normalized === 'yes' || normalized === 'y') {
      const prompt = composePromptFromBuildDraft(session.draft);
      const widget = createPromptWidgetFromPrompt(
        prompt,
        `${visitorId}:${designSignature.value}:${sceneNonce.value}`,
        session.draft.title
      );

      const deployed = deployWidget(widget, 'Build Mode');
      widgetBuildSession.value = null;
      if (deployed) {
        addLine('system', `Widget is live. Use /widget refresh ${widget.id} to regenerate.`);
      }
      return true;
    }

    if (normalized === 'no' || normalized === 'n') {
      widgetBuildSession.value = {
        step: 'intent',
        draft: defaultWidgetBuildDraft()
      };
      addLine('system', 'No problem. Let’s try again. 1/4 What should this widget do for the user?');
      return true;
    }

    addLine('system', 'Reply with yes or no.');
    return true;
  }

  return false;
}

function normalizeConfigEntries(entries: Array<{ key: string; value: string }>): Record<string, string> {
  const next: Record<string, string> = {};
  for (const entry of entries) {
    const key = entry.key.trim();
    const value = entry.value.trim();
    if (!key || !value) {
      continue;
    }
    next[key] = value;
  }

  return next;
}

function inferOutputStyleFromPrompt(prompt: string): WidgetOutputFormat {
  const normalized = prompt.toLowerCase();
  if (normalized.includes('checklist')) {
    return 'checklist';
  }
  if (normalized.includes('bullet')) {
    return 'bullets';
  }
  return 'brief';
}

function extractPromptAudience(prompt: string): string {
  const match = prompt.match(/context\/focus:\s*([^\.]+)/i);
  if (!match) {
    return '';
  }
  return match[1].trim();
}

function buildWidgetEditorState(widget: DashboardWidget): WidgetEditorState {
  const entries = Object.entries(widget.config).map(([key, value]) => ({ key, value }));
  const prompt = widget.config.prompt ?? '';
  const outputStyle = inferOutputStyleFromPrompt(prompt);
  const audience = extractPromptAudience(prompt);

  return {
    widgetId: widget.id,
    title: widget.title,
    prompt,
    outputStyle,
    audience,
    html: widget.html ?? '',
    configEntries: entries.length > 0 ? entries : [{ key: '', value: '' }]
  };
}

function startEditingWidget(widgetId: string): void {
  const target = widgets.value.find((widget) => widget.id.toLowerCase() === widgetId.toLowerCase());
  if (!target) {
    addLine('system', `Widget ${widgetId} not found.`);
    return;
  }

  editingWidgetId.value = target.id;
  widgetEditor.value = buildWidgetEditorState(target);
  addLine('signal', `Editing widget ${target.id}. Save to apply tuning changes.`);
}

function cancelWidgetEdit(): void {
  editingWidgetId.value = '';
  widgetEditor.value = null;
}

function addConfigEntry(): void {
  if (!widgetEditor.value) {
    return;
  }

  widgetEditor.value.configEntries.push({ key: '', value: '' });
}

function removeConfigEntry(index: number): void {
  if (!widgetEditor.value) {
    return;
  }

  widgetEditor.value.configEntries.splice(index, 1);
  if (widgetEditor.value.configEntries.length === 0) {
    widgetEditor.value.configEntries.push({ key: '', value: '' });
  }
}

function optimizePromptDraft(): void {
  if (!widgetEditor.value) {
    return;
  }

  const basePrompt = widgetEditor.value.prompt.trim();
  const objective = basePrompt || 'Deliver practical, high-value insight for this widget.';
  const audience = widgetEditor.value.audience.trim() || 'dashboard visitor';
  const formatGuide = formatInstruction(widgetEditor.value.outputStyle);

  widgetEditor.value.prompt = [
    `Objective: ${objective}`,
    `Audience/context: ${audience}`,
    `Formatting: ${formatGuide}`,
    'Quality bar: include concrete facts when available, avoid placeholders, and keep output directly actionable.'
  ].join(' ');
}

function saveWidgetEdit(): void {
  if (!widgetEditor.value || !editingWidgetId.value) {
    return;
  }

  const widgetIndex = widgets.value.findIndex((widget) => widget.id === editingWidgetId.value);
  if (widgetIndex === -1) {
    addLine('system', `Widget ${editingWidgetId.value} not found.`);
    cancelWidgetEdit();
    return;
  }

  const existing = widgets.value[widgetIndex];
  const normalizedTitle = widgetEditor.value.title.trim() || existing.title;
  const config = normalizeConfigEntries(widgetEditor.value.configEntries);

  if (existing.config.mode === 'prompt' || widgetEditor.value.prompt.trim().length > 0) {
    config.mode = 'prompt';
    config.prompt = widgetEditor.value.prompt.trim() || existing.config.prompt || '';
    config.outputStyle = widgetEditor.value.outputStyle;
    config.audience = widgetEditor.value.audience.trim();
  }

  const nextWidget: DashboardWidget = {
    ...existing,
    title: normalizedTitle,
    config,
    html: existing.type === 'customHtml' && existing.config.mode !== 'prompt'
      ? sanitizeWidgetHtml(widgetEditor.value.html || existing.html || '')
      : existing.html
  };

  widgets.value.splice(widgetIndex, 1, nextWidget);
  persistWidgets();
  const refreshed = refreshWidgetRuntime(nextWidget.id);
  addLine('signal', `Saved tuning for ${nextWidget.id}. ${refreshed ? 'Runtime refresh queued.' : ''}`);
  cancelWidgetEdit();
}

function removeWidgetById(widgetId: string): boolean {
  const nextWidgets = widgets.value.filter((widget) => widget.id.toLowerCase() !== widgetId.toLowerCase());
  if (nextWidgets.length === widgets.value.length) {
    return false;
  }

  widgets.value = nextWidgets;
  persistWidgets();
  if (widgetRefreshKeys.value[widgetId] !== undefined) {
    const nextRefreshKeys = { ...widgetRefreshKeys.value };
    delete nextRefreshKeys[widgetId];
    widgetRefreshKeys.value = nextRefreshKeys;
  }

  if (editingWidgetId.value.toLowerCase() === widgetId.toLowerCase()) {
    cancelWidgetEdit();
  }
  return true;
}

function refreshWidgetRuntime(widgetId: string): string | null {
  const target = widgets.value.find((widget) => widget.id.toLowerCase() === widgetId.toLowerCase());
  if (!target) {
    return null;
  }

  widgetRefreshKeys.value = {
    ...widgetRefreshKeys.value,
    [target.id]: (widgetRefreshKeys.value[target.id] ?? 0) + 1
  };
  return target.id;
}

function refreshAllWidgetRuntime(): void {
  widgetRefreshNonce.value += 1;
}

function widgetRuntimeSignature(widgetId: string): string {
  const widgetToken = widgetRefreshKeys.value[widgetId] ?? 0;
  return `${designSignature.value}:${widgetRefreshNonce.value}:${widgetToken}`;
}

async function initializePersonalization(): Promise<void> {
  initializing.value = true;
  initializationError.value = '';
  wordpressError.value = '';

  if (!personalization.blueprint) {
    personalization.loadFromStorage();
  }

  const wpBundle = await fetchWordpressContentBundle();
  if (wpBundle && Object.keys(wpBundle.contentOverrides).length > 0) {
    setRuntimeContentOverrides(wpBundle.contentOverrides);
  }

  if (!wpBundle) {
    wordpressError.value = 'WordPress fetch unavailable. Running from cached runtime content.';
  } else if (wpBundle.wordpress && !wpBundle.wordpress.available) {
    const firstError = wpBundle.wordpress.errors[0] ?? '';
    wordpressError.value = firstError
      ? `WordPress REST warning: ${firstError}`
      : 'WordPress REST warning: no posts were returned.';
  }

  widgets.value = loadWidgets();

  if (!personalization.blueprint) {
    initializing.value = false;
    await router.replace('/onboarding');
    return;
  }

  initializing.value = false;
}

const brandName = computed(() => firstStringModuleProp('brandName') || 'Alexander Gill');
const brandTagline = computed(() => firstStringModuleProp('brandTagline') || 'Power plays.');
const brandBaseUrl = computed(() => firstStringModuleProp('brandBaseUrl') || 'https://alexanderjgill.com');
const brandIconUrl = computed(
  () => firstStringModuleProp('brandIconUrl') || 'https://alexanderjgill.com/wp-content/uploads/2025/09/A_icon_1_171f1f.png'
);

const focusTopics = computed(() => {
  const fromProps = firstStringArrayModuleProp('focusTopics');
  if (fromProps.length > 0) {
    return fromProps.slice(0, 6);
  }

  return ['Identity', 'Ideas', 'Momentum'];
});

const personalizationMode = computed(() => blueprint.value?.theme.mode ?? 'dark');
const personalizationDensity = computed(() => blueprint.value?.layout.density ?? 'medium');
const personalizationNav = computed(() => blueprint.value?.layout.nav ?? 'top');
const personalizationAccent = computed(() => blueprint.value?.theme.accent ?? '#16c7cf');
const personalizationProfile = computed(() => firstStringModuleProp('profile') || 'adaptive');
const personalizationProfileClass = computed(() =>
  personalizationProfile.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'adaptive'
);
const sceneVariant = computed(() => {
  const token = hashText(`${sceneStyleToken.value}:${sceneNonce.value}:${designSignature.value}`);
  return token % 8;
});

const shellClassName = computed(() => [
  `mode-${personalizationMode.value}`,
  `density-${personalizationDensity.value}`,
  `nav-${personalizationNav.value}`,
  `profile-${personalizationProfileClass.value}`,
  `scene-variant-${sceneVariant.value}`
]);

const shellVisualStyle = computed<Record<string, string>>(() => {
  const baseAccent = hexToRgb(personalizationAccent.value) ?? { r: 22, g: 199, b: 207 };
  const accentTargets = [
    { r: 22, g: 199, b: 207 },
    { r: 56, g: 189, b: 248 },
    { r: 45, g: 212, b: 191 },
    { r: 74, g: 222, b: 128 },
    { r: 244, g: 114, b: 182 },
    { r: 196, g: 181, b: 253 },
    { r: 251, g: 146, b: 60 },
    { r: 250, g: 204, b: 21 }
  ];
  const blendRatios = [0.12, 0.64, 0.58, 0.54, 0.66, 0.6, 0.62, 0.57];
  const variant = sceneVariant.value;
  const accentRgb = mixRgb(baseAccent, accentTargets[variant], blendRatios[variant]);
  const soft = mixWithWhite(accentRgb, 0.32);
  const sharp = mixWithWhite(accentRgb, 0.08);
  const gridOpacity = variant === 0 ? 0.03 : variant === 4 ? 0.065 : variant === 7 ? 0.07 : 0.05;
  const panelRadius = variant === 2 ? '20px' : variant === 5 ? '16px' : variant === 7 ? '22px' : '14px';
  const glowStrength = variant === 6 || variant === 7 ? '0.32' : variant === 4 ? '0.28' : '0.2';

  return {
    '--accent-rgb': rgbToCss(accentRgb),
    '--accent-soft-rgb': rgbToCss(soft),
    '--accent-sharp-rgb': rgbToCss(sharp),
    '--scene-grid-opacity': `${gridOpacity}`,
    '--scene-panel-radius': panelRadius,
    '--scene-glow-strength': glowStrength
  };
});

const gameThemeStyle = computed<Record<string, string>>(() => ({
  '--game-accent-rgb': shellVisualStyle.value['--accent-rgb'] ?? '22, 199, 207',
  '--game-accent-soft-rgb': shellVisualStyle.value['--accent-soft-rgb'] ?? '120, 224, 228',
  '--game-accent-sharp-rgb': shellVisualStyle.value['--accent-sharp-rgb'] ?? '16, 153, 178',
  '--game-text-primary': personalizationMode.value === 'light' ? '#0f172a' : '#d1fae5',
  '--game-text-secondary': personalizationMode.value === 'light' ? '#334155' : '#a7f3d0',
  '--game-surface-main': personalizationMode.value === 'light' ? 'rgba(247, 252, 255, 0.92)' : 'rgba(2, 8, 24, 0.86)',
  '--game-surface-card': personalizationMode.value === 'light' ? 'rgba(255, 255, 255, 0.9)' : 'rgba(2, 10, 28, 0.72)',
  '--game-surface-elevated': personalizationMode.value === 'light' ? 'rgba(255, 255, 255, 0.94)' : 'rgba(2, 8, 23, 0.74)'
}));

const visualSeed = computed(() =>
  hashText(`${designSignature.value}:${sceneNonce.value}:${focusTopics.value.join('|')}:${personalizationProfile.value}`)
);

const impressionOrbs = computed(() => {
  const orbitCount = personalizationDensity.value === 'high' ? 9 : personalizationDensity.value === 'low' ? 5 : 7;
  const baseSeed = `${designSignature.value}:${focusTopics.value.join('|')}:${personalizationProfile.value}`;

  return Array.from({ length: orbitCount }, (_, index) => {
    const seed = hashText(`${baseSeed}:${index}`);
    const top = (seed % 84) + 6;
    const left = ((seed >>> 4) % 84) + 4;
    const size = 120 + ((seed >>> 9) % 190);
    const drift = 12 + ((seed >>> 13) % 36);
    const duration = 12 + ((seed >>> 15) % 20);
    const delay = (seed >>> 7) % 7;

    return {
      top: `${top}%`,
      left: `${left}%`,
      width: `${size}px`,
      height: `${size}px`,
      '--drift': `${drift}px`,
      '--duration': `${duration}s`,
      '--delay': `${delay}s`
    } as Record<string, string>;
  });
});

const designSignature = computed(() => {
  const fromBlueprint = firstStringModuleProp('signature');
  if (fromBlueprint) {
    return fromBlueprint.slice(0, 10).toUpperCase();
  }

  if (!blueprint.value) {
    return 'SIG-0000';
  }

  const moduleIds = blueprint.value.modules.map((module) => module.id).join('|');
  return hashText(`${blueprint.value.createdAt}|${moduleIds}`).toString(36).toUpperCase().padStart(8, '0').slice(0, 8);
});

const goalSignal = computed(() => {
  const heroTitle = firstStringModuleProp('title');
  if (heroTitle) {
    return heroTitle;
  }

  return 'Guide each visitor through a distinctive content journey.';
});

const posts = computed<ExperiencePost[]>(() => {
  const featured = asRecord(getContentByKey('featuredGrid'));
  const items = Array.isArray(featured?.items) ? featured.items : [];

  const mapped = items
    .map((entry, index) => {
      const item = asRecord(entry);
      if (!item) {
        return null;
      }

      const id = String(item.id ?? `post-${index + 1}`);
      const title = typeof item.title === 'string' ? item.title.trim() : '';
      if (!title) {
        return null;
      }

      const description = typeof item.description === 'string' ? item.description.trim() : 'No summary available yet.';
      const hrefRaw = typeof item.href === 'string' ? item.href.trim() : '';
      const canonical = typeof item.canonicalUrl === 'string' ? item.canonicalUrl.trim() : '';
      const href = hrefRaw || canonical || brandBaseUrl.value;
      const imageUrl = typeof item.imageUrl === 'string' ? item.imageUrl.trim() : '';
      const meta = typeof item.meta === 'string' ? item.meta.trim() : 'Live stream';

      return {
        id,
        title,
        description,
        href,
        imageUrl,
        meta
      };
    })
    .filter((item): item is ExperiencePost => item !== null)
    .slice(0, 8);

  if (mapped.length > 0) {
    return mapped;
  }

  return [
    {
      id: 'fallback-post',
      title: 'Open alexanderjgill.com',
      description: 'No feed detected in runtime cache. Open the source blog directly.',
      href: brandBaseUrl.value,
      imageUrl: '',
      meta: 'Fallback stream'
    }
  ];
});

const shortcuts = computed(() => (blueprint.value?.shortcuts ?? []).slice(0, 6));

const scene = computed(() =>
  buildExperienceScene({
    visitorId,
    signature: designSignature.value,
    goal: goalSignal.value,
    topics: focusTopics.value,
    posts: posts.value,
    nonce: sceneNonce.value,
    forcedFocus: forcedFocus.value
  })
);

const readinessScore = computed(() => {
  const topicWeight = Math.min(30, focusTopics.value.length * 7);
  const postWeight = Math.min(30, posts.value.length * 5);
  const widgetWeight = Math.min(30, widgets.value.length * 6);
  const trackWeight = Math.min(10, scene.value.tracks.length * 3);
  return Math.min(100, topicWeight + postWeight + widgetWeight + trackWeight);
});

const dashboardStats = computed(() => [
  {
    label: 'Experience Readiness',
    value: `${readinessScore.value}%`,
    detail: readinessScore.value >= 80 ? 'High signal' : 'Building signal'
  },
  {
    label: 'Live Story Nodes',
    value: `${posts.value.length}`,
    detail: 'Pulled from runtime WP feed'
  },
  {
    label: 'Deployed Widgets',
    value: `${widgets.value.length}/${MAX_DASHBOARD_WIDGETS}`,
    detail: widgets.value.length > 0 ? widgets.value[0].title : 'No widgets yet'
  },
  {
    label: 'Mission Tracks',
    value: `${scene.value.tracks.length}`,
    detail: 'Action lanes available now'
  }
]);

const activeConversation = computed(() =>
  conversationThreads.value.find((conversation) => conversation.id === activeConversationId.value) ?? null
);
const activeConversationSummary = computed(() => {
  if (!activeConversation.value) {
    return 'No active conversation';
  }
  return `${activeConversation.value.title} · ${formatRelativeTime(activeConversation.value.updatedAt)}`;
});

const widgetBuildStepLabel = computed(() => {
  const step = widgetBuildSession.value?.step;
  if (!step) {
    return '';
  }

  if (step === 'intent') return 'Goal';
  if (step === 'scope') return 'Scope';
  if (step === 'format') return 'Format';
  if (step === 'title') return 'Title';
  return 'Confirm';
});

const commandPlaceholder = computed(() =>
  widgetBuildSession.value
    ? `Widget build mode (${widgetBuildStepLabel.value}) · answer question or /widget cancel`
    : 'Chat with multimodal AI or build widgets (type /help)'
);

const commandHints = computed(() => {
  if (widgetBuildSession.value) {
    return [
      { label: 'Cancel Build', command: '/widget cancel' },
      { label: 'Help', command: '/help' },
      { label: 'New Thread', command: '/thread new' }
    ];
  }

  return [
    { label: 'Help', command: '/help' },
    { label: 'New Thread', command: '/thread new' },
    { label: 'Upload File', command: '/upload' },
    { label: 'Widget Build', command: '/widget build' },
    { label: 'Image Prompt', command: '/image random' },
    { label: 'Shuffle Scene', command: '/shuffle' }
  ];
});

const commandStatus = computed(() =>
  assistantStreaming.value
    ? 'Assistant is responding...'
    : `Enter to send · History ${Math.min(commandHistory.value.length, 99)} · Files ${pendingAttachments.value.length}`
);

const threadSummary = computed(() => `${conversationThreads.value.length}/${MAX_SAVED_CONVERSATIONS}`);
const terminalShellStyle = computed<Record<string, string>>(() => ({
  '--terminal-transcript-height': `${transcriptHeight.value}px`,
  '--reveal-order': '4'
}));
const currentAppView = computed<AppNavSectionId>(() => {
  const match = appNavSections.find((section) => route.path === section.path);
  return match?.id ?? 'home';
});
const activeSectionId = computed<AppNavSectionId>(() => currentAppView.value);
const isHomeView = computed(() => currentAppView.value === 'home');
const isConversationView = computed(() => currentAppView.value === 'conversations');
const isSkillGameView = computed(() => currentAppView.value === 'skill-game');
const isWidgetsView = computed(() => currentAppView.value === 'widgets');
const isBlogView = computed(() => currentAppView.value === 'blog');

function clampTranscriptHeight(height: number): number {
  return Math.max(220, Math.min(860, Math.round(height)));
}

function adjustTranscriptHeight(delta: number): void {
  transcriptHeight.value = clampTranscriptHeight(transcriptHeight.value + delta);
}

function applySceneShuffle(): void {
  sceneNonce.value += 1;
  sceneStyleToken.value = Date.now() + Math.floor(Math.random() * 10_000);
  sceneRefreshEpoch.value += 1;
  forcedFocus.value = '';
  closeImagePreview();
}

function rememberCommand(input: string): void {
  if (!input.trim()) {
    return;
  }

  const previous = commandHistory.value[commandHistory.value.length - 1] ?? '';
  if (previous !== input) {
    commandHistory.value.push(input);
    if (commandHistory.value.length > 80) {
      commandHistory.value = commandHistory.value.slice(-80);
    }
  }
  commandHistoryCursor.value = -1;
}

function handleCommandInputKeydown(event: KeyboardEvent): void {
  if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
    return;
  }

  if (commandHistory.value.length === 0) {
    return;
  }

  event.preventDefault();

  if (event.key === 'ArrowUp') {
    if (commandHistoryCursor.value === -1) {
      commandHistoryCursor.value = commandHistory.value.length - 1;
    } else {
      commandHistoryCursor.value = Math.max(0, commandHistoryCursor.value - 1);
    }
  } else if (commandHistoryCursor.value === -1) {
    return;
  } else if (commandHistoryCursor.value >= commandHistory.value.length - 1) {
    commandHistoryCursor.value = -1;
    commandInput.value = '';
    return;
  } else {
    commandHistoryCursor.value += 1;
  }

  if (commandHistoryCursor.value !== -1) {
    commandInput.value = commandHistory.value[commandHistoryCursor.value] ?? '';
  }
}

function runCommandHint(command: string): void {
  commandInput.value = command;
  void handleCommand(command);
}

function toggleTerminalExpanded(): void {
  terminalExpanded.value = !terminalExpanded.value;
  transcriptHeight.value = clampTranscriptHeight(terminalExpanded.value ? Math.max(transcriptHeight.value, 560) : 320);
}

async function navigateToView(path: string): Promise<void> {
  if (route.path === path) {
    return;
  }
  await router.push(path);
}

function handleGlobalKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && imagePreview.value) {
    closeImagePreview();
    return;
  }

  if (event.key === 'Escape' && terminalExpanded.value) {
    terminalExpanded.value = false;
    transcriptHeight.value = clampTranscriptHeight(320);
  }
}

function updateMotionPreference(): void {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    prefersReducedMotion.value = false;
    return;
  }

  if (!motionMediaQuery) {
    motionMediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  }

  prefersReducedMotion.value = motionMediaQuery.matches;
}

function handleMotionPreferenceChange(): void {
  updateMotionPreference();
}

async function resetPersonalization(): Promise<void> {
  personalization.resetPersonalization();
  await router.push('/onboarding?force=1&reset=1');
}

function showWidgetListInTerminal(): void {
  if (widgets.value.length === 0) {
    addLine('system', 'No widgets deployed yet. Try /widget build and I will guide you.');
    return;
  }

  addLine('signal', `Widgets active: ${widgets.value.length}/${MAX_DASHBOARD_WIDGETS}`);
  for (const widget of widgets.value.slice(0, 10)) {
    addLine('system', `${widget.id} · ${widget.type} · ${widget.title}`);
  }
}

function handleThreadCommand(input: string): boolean {
  if (!input.startsWith('/thread')) {
    return false;
  }

  if (input === '/thread') {
    addLine('system', 'Thread commands: /thread list, /thread new, /thread open <id>');
    return true;
  }

  if (input === '/thread list') {
    addLine('signal', `Saved conversations: ${conversationThreads.value.length}/${MAX_SAVED_CONVERSATIONS}`);
    for (const thread of conversationThreads.value) {
      const marker = thread.id === activeConversationId.value ? ' (active)' : '';
      addLine('system', `${thread.id}${marker} · ${thread.title}`);
    }
    return true;
  }

  if (input === '/thread new') {
    startNewConversation();
    return true;
  }

  if (input.startsWith('/thread open ')) {
    const targetId = input.slice('/thread open '.length).trim();
    if (!targetId) {
      addLine('system', 'Usage: /thread open <conversation-id>');
      return true;
    }

    const target = conversationThreads.value.find((thread) => thread.id.toLowerCase() === targetId.toLowerCase());
    if (!target) {
      addLine('system', `Conversation ${targetId} not found. Use /thread list.`);
      return true;
    }

    switchConversation(target.id);
    addLine('signal', `Conversation ${target.id} restored.`);
    return true;
  }

  addLine('system', 'Thread commands: /thread list, /thread new, /thread open <id>');
  return true;
}

function handleWidgetCommand(input: string): boolean {
  if (!input.startsWith('/widget')) {
    return false;
  }

  if (input === '/widget list') {
    showWidgetListInTerminal();
    return true;
  }

  if (input === '/widget clear') {
    widgets.value = [];
    widgetRefreshKeys.value = {};
    cancelWidgetEdit();
    persistWidgets();
    addLine('signal', 'All deployed widgets cleared.');
    return true;
  }

  if (input === '/widget cancel') {
    if (widgetBuildSession.value) {
      cancelWidgetBuildMode();
    } else {
      addLine('system', 'Widget build mode is not active.');
    }
    return true;
  }

  if (input === '/widget refresh' || input === '/widget refresh all') {
    if (widgets.value.length === 0) {
      addLine('system', 'No widgets to refresh yet.');
      return true;
    }

    refreshAllWidgetRuntime();
    addLine('signal', `Refreshing all widgets (${widgets.value.length}/${MAX_DASHBOARD_WIDGETS})...`);
    return true;
  }

  if (input.startsWith('/widget refresh ')) {
    const widgetId = input.slice('/widget refresh '.length).trim();
    if (!widgetId) {
      addLine('system', 'Usage: /widget refresh <widget-id|all>');
      return true;
    }

    if (widgetId.toLowerCase() === 'all') {
      refreshAllWidgetRuntime();
      addLine('signal', `Refreshing all widgets (${widgets.value.length}/${MAX_DASHBOARD_WIDGETS})...`);
      return true;
    }

    const refreshedWidgetId = refreshWidgetRuntime(widgetId);
    addLine(
      refreshedWidgetId ? 'signal' : 'system',
      refreshedWidgetId ? `Refreshing widget ${refreshedWidgetId}...` : `Widget ${widgetId} not found.`
    );
    return true;
  }

  if (input.startsWith('/widget remove ')) {
    const id = input.slice('/widget remove '.length).trim();
    if (!id) {
      addLine('system', 'Usage: /widget remove <widget-id>');
      return true;
    }

    const removed = removeWidgetById(id);
    addLine(removed ? 'signal' : 'system', removed ? `Removed widget ${id}.` : `Widget ${id} not found.`);
    return true;
  }

  if (input.startsWith('/widget edit ')) {
    const widgetId = input.slice('/widget edit '.length).trim();
    if (!widgetId) {
      addLine('system', 'Usage: /widget edit <widget-id>');
      return true;
    }

    startEditingWidget(widgetId);
    return true;
  }

  if (input.startsWith('/widget add ')) {
    const payload = input.slice('/widget add '.length).trim();
    if (!payload) {
      addLine('system', 'Usage: /widget add <weather|horoscope|fashion|sports|customHtml> [hint]');
      return true;
    }

    const [rawType, ...rest] = payload.split(' ');
    const widgetType = parseWidgetType(rawType);
    if (!widgetType) {
      addLine('system', 'Widget types: weather, horoscope, fashion, sports, customHtml');
      return true;
    }

    const hint = rest.join(' ').trim();
    const widget = createPresetWidget(widgetType, `${visitorId}:${designSignature.value}:${sceneNonce.value}`, hint);
    deployWidget(widget);
    return true;
  }

  if (input === '/widget build' || input.startsWith('/widget build ')) {
    const payload = input === '/widget build' ? '' : input.slice('/widget build '.length).trim();
    if (!payload) {
      startWidgetBuildMode();
      return true;
    }

    const splitToken = '||';
    const splitIndex = payload.indexOf(splitToken);

    if (splitIndex === -1) {
      startWidgetBuildMode(payload);
      return true;
    }

    let title = '';
    let prompt = payload;
    if (splitIndex !== -1) {
      title = payload.slice(0, splitIndex).trim();
      prompt = payload.slice(splitIndex + splitToken.length).trim();
    }

    if (!prompt) {
      addLine('system', 'Prompt missing. Usage: /widget build <title> || <prompt>');
      return true;
    }

    const widget = createPromptWidgetFromPrompt(prompt, `${visitorId}:${designSignature.value}:${sceneNonce.value}`, title);
    deployWidget(widget);
    addLine('system', `Prompt bound. Use /widget refresh ${widget.id} to run it again.`);
    return true;
  }

  if (input.startsWith('/widget html ')) {
    const payload = input.slice('/widget html '.length).trim();
    const splitToken = '||';
    const splitIndex = payload.indexOf(splitToken);

    if (splitIndex === -1) {
      addLine('system', 'Usage: /widget html <title> || <html>');
      addLine('system', 'Example: /widget html Quick Card || <section><h4>Today</h4><p>Ship one feature.</p></section>');
      return true;
    }

    const title = payload.slice(0, splitIndex).trim() || 'Custom HTML Widget';
    const html = payload.slice(splitIndex + splitToken.length).trim();

    if (!html) {
      addLine('system', 'HTML content missing. Usage: /widget html <title> || <html>');
      return true;
    }

    const widget: DashboardWidget = {
      ...createPresetWidget('customHtml', `${visitorId}:${designSignature.value}:${sceneNonce.value}`, title),
      html: sanitizeWidgetHtml(html)
    };

    deployWidget(widget);
    return true;
  }

  addLine(
    'system',
    'Widget commands: /widget list, /widget build [intent], /widget build <title> || <prompt>, /widget edit <id>, /widget add <type>, /widget html <title> || <html>, /widget refresh <id|all>, /widget remove <id>, /widget clear, /widget cancel'
  );
  return true;
}

async function handleCommand(raw: string): Promise<void> {
  const input = raw.trim();
  if (!input) {
    return;
  }

  rememberCommand(input);
  addLine('user', input);
  commandInput.value = '';

  if (handleWidgetBuildModeInput(input)) {
    return;
  }

  if (input === '/help') {
    addLine('system', 'Core: /help, /upload, /image <prompt>, /thread [list|new|open <id>], /shuffle, /focus <topic>, /open <1-3>, /reset');
    addLine(
      'system',
      'Widgets: /widget list, /widget build [intent] (guided), /widget build <title> || <prompt>, /widget edit <id>, /widget add <type>, /widget html <title> || <html>, /widget refresh <id|all>, /widget remove <id>, /widget clear, /widget cancel'
    );
    addLine('system', `Conversation limit: ${MAX_SAVED_CONVERSATIONS}. Widget limit: ${MAX_DASHBOARD_WIDGETS}.`);
    return;
  }

  if (input === '/clear') {
    transcript.value = [];
    mediaLoadState.value = {};
    assistantSuggestions.value = [];
    addLine('system', `Transcript cleared. ${scene.value.codename} remains active.`);
    return;
  }

  if (input === '/shuffle') {
    applySceneShuffle();
    addLine('signal', `Scene recompiled -> ${scene.value.codename} · style variant ${sceneVariant.value + 1}/8`);
    addLine('signal', 'Visual refresh applied.');
    return;
  }

  if (input.startsWith('/focus ')) {
    const candidate = parseFocusInput(input.slice('/focus '.length));
    if (!candidate) {
      addLine('system', 'Focus command requires a topic. Example: /focus hockey');
      return;
    }

    forcedFocus.value = candidate;
    sceneNonce.value += 1;
    addLine('signal', `Focus lane locked -> ${candidate}`);
    return;
  }

  if (input.startsWith('/open ')) {
    const token = input.slice('/open '.length).trim();
    const index = Number.parseInt(token, 10) - 1;
    const track = scene.value.tracks[index];
    if (!track) {
      addLine('system', 'Track not found. Use /open 1, /open 2, or /open 3.');
      return;
    }

    addLine('signal', `Opening ${track.label}...`);
    await openAction(track.ctaHref);
    return;
  }

  if (input === '/reset') {
    addLine('system', 'Resetting personalization and returning to onboarding terminal...');
    await resetPersonalization();
    return;
  }

  if (input === '/upload') {
    openFilePicker();
    addLine('system', 'Choose a file to attach. Images and text files are supported.');
    return;
  }

  if (input.startsWith('/image ')) {
    const payload = input.slice('/image '.length).trim();
    if (!payload) {
      addLine('system', 'Usage: /image <prompt>');
      return;
    }

    let prompt = payload;
    if (/^(random|surprise|auto)$/i.test(payload)) {
      addLine('signal', 'Synthesizing a randomized image prompt...');
      const generated = await generateImagePromptWithFallback({
        visitorId,
        variantNonce: sceneNonce.value,
        topics: focusTopics.value
      });
      prompt = generated.prompt;
      addLine('system', `Image prompt (${generated.source}): ${prompt}`);
    }

    addLine('signal', 'Multimodal request detected. Generating in-thread visual...');
    const attachments = toAssistantAttachments(pendingAttachments.value);
    clearPendingAttachments();
    await runAssistantConversation(prompt, { forceImage: true, attachments });
    return;
  }

  if (handleThreadCommand(input)) {
    return;
  }

  if (handleWidgetCommand(input)) {
    return;
  }

  if (isWidgetBuildIntentRequest(input)) {
    const intentHint = extractIntentFromBuildRequest(input);
    startWidgetBuildMode(intentHint);
    return;
  }

  const attachments = toAssistantAttachments(pendingAttachments.value);
  clearPendingAttachments();
  await runAssistantConversation(input, { attachments });
}

async function handleComposerSubmit(): Promise<void> {
  const raw = commandInput.value.trim();
  if (!raw && pendingAttachments.value.length === 0) {
    return;
  }

  if (!raw && pendingAttachments.value.length > 0) {
    commandInput.value = 'Please analyze my attached file and give me clear next steps.';
  }

  await handleCommand(commandInput.value);
}

function removeWidget(widgetId: string): void {
  const removed = removeWidgetById(widgetId);
  if (!removed) {
    return;
  }

  addLine('signal', `Widget ${widgetId} removed from dashboard.`);
}

onMounted(async () => {
  await initializePersonalization();
  window.addEventListener('keydown', handleGlobalKeydown);
  updateMotionPreference();
  if (motionMediaQuery) {
    if (typeof motionMediaQuery.addEventListener === 'function') {
      motionMediaQuery.addEventListener('change', handleMotionPreferenceChange);
    } else if (typeof motionMediaQuery.addListener === 'function') {
      motionMediaQuery.addListener(handleMotionPreferenceChange);
    }
  }

  if (!blueprint.value) {
    return;
  }

  initializeConversationThreads();

  if (transcript.value.length === 0) {
    addLine('system', `Visitor experience terminal active · Signature ${designSignature.value}`);
    addLine('system', scene.value.mission);
    addLine('signal', scene.value.pulse);
    addLine('system', 'Multimodal assistant is live in-thread. Widget deploy only happens in /widget mode.');
    addLine('system', 'Use /thread new for a fresh conversation or /widget build for guided widget creation.');
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleGlobalKeydown);
  if (motionMediaQuery) {
    if (typeof motionMediaQuery.removeEventListener === 'function') {
      motionMediaQuery.removeEventListener('change', handleMotionPreferenceChange);
    } else if (typeof motionMediaQuery.removeListener === 'function') {
      motionMediaQuery.removeListener(handleMotionPreferenceChange);
    }
  }
  motionMediaQuery = null;
  for (const url of blobMediaUrls.value) {
    URL.revokeObjectURL(url);
  }
  blobMediaUrls.value = [];
});
</script>

<template>
  <main v-if="initializing" class="experience-root loading-root">
    <section class="loading-card">
      <p>Booting visitor experience terminal...</p>
    </section>
  </main>

  <main
    v-else-if="blueprint"
    :key="`scene-${sceneRefreshEpoch}`"
    class="experience-root"
    :class="shellClassName"
    :style="shellVisualStyle"
  >
    <div class="fx-stage" aria-hidden="true" :key="`fx-${sceneRefreshEpoch}`">
      <HoloBackdrop class="fx-canvas" :accent="personalizationAccent" :seed="visualSeed" :reduced-motion="prefersReducedMotion" />
      <span v-for="(orb, idx) in impressionOrbs" :key="`orb-${idx}`" class="fx-orb" :style="orb"></span>
    </div>

    <div class="app-shell">
      <aside class="app-sidebar reveal-surface" style="--reveal-order: 1">
        <a class="brand sidebar-brand" :href="brandBaseUrl" target="_blank" rel="noopener noreferrer">
          <img :src="brandIconUrl" alt="" loading="lazy" />
          <span>
            <strong>{{ brandName }}</strong>
            <em>{{ brandTagline }}</em>
          </span>
        </a>

        <p class="sidebar-kicker">Workspace Navigation</p>
        <nav class="sidebar-nav" aria-label="Workspace sections">
          <button
            v-for="section in appNavSections"
            :key="section.id"
            type="button"
            :class="{ active: activeSectionId === section.id }"
            @click="navigateToView(section.path)"
          >
            <span>{{ section.label }}</span>
          </button>
        </nav>

        <div class="sidebar-meta">
          <p class="sidebar-meta-label">Readiness</p>
          <p class="sidebar-meta-value">{{ readinessScore }}%</p>
          <p class="sidebar-meta-detail">Signature {{ designSignature }}</p>
        </div>
      </aside>

      <div class="app-main">
        <header class="topbar reveal-surface" style="--reveal-order: 1">
          <div class="topbar-primary">
            <div class="topbar-head">
              <p class="mission-kicker">MySite Workspace</p>
              <h2>{{ scene.codename }}</h2>
              <p class="topbar-copy">A sample of AI capabilities with creating custom workspaces.</p>
            </div>

            <div class="topbar-meta">
              <p>{{ scene.codename }} · {{ BUILD_TAG }}</p>
              <p class="persona-line">Profile {{ personalizationProfile }} · {{ personalizationDensity }} density</p>
              <button type="button" @click="resetPersonalization">Reset Personalization</button>
            </div>
          </div>

        </header>

    <Transition name="view-swap" mode="out-in">
      <div :key="currentAppView" class="view-stage">
    <section v-if="isHomeView" id="mission-hub" class="mission-shell reveal-surface" style="--reveal-order: 2">
      <article class="mission-card">
        <p class="mission-kicker">About MySite</p>
        <h1>{{ scene.mission }}</h1>
        <p class="mysite-explainer">
          MySite is a sample of AI capabilities for creating custom workspaces tailored to each visitor.
        </p>
        <p class="voice-line">{{ scene.voice }}</p>
        <p class="pulse-line">{{ scene.pulse }}</p>
        <div class="topic-row">
          <span v-for="topic in focusTopics.slice(0, 5)" :key="topic">{{ topic }}</span>
        </div>
        <nav class="mission-nav" aria-label="MySite sections">
          <button
            v-for="item in missionNavigation"
            :key="item.id"
            type="button"
            @click="navigateToView(item.path)"
          >
            {{ item.label }}
          </button>
        </nav>
        <p v-if="wordpressError" class="warning-line">{{ wordpressError }}</p>
        <p v-if="initializationError" class="warning-line">{{ initializationError }}</p>
      </article>

      <article class="prompt-card">
        <p class="mission-kicker">Prompt Suggestions</p>
        <ul>
          <li v-for="prompt in scene.prompts" :key="prompt">{{ prompt }}</li>
        </ul>
      </article>
    </section>

    <section v-if="isHomeView" id="dashboard-hub" class="dashboard-shell reveal-surface" style="--reveal-order: 3">
      <article class="dashboard-card">
        <p class="mission-kicker">Visitor Dashboard</p>
        <div class="stats-grid">
          <section v-for="stat in dashboardStats" :key="stat.label" class="stat-card">
            <p class="stat-label">{{ stat.label }}</p>
            <h2 class="stat-value">{{ stat.value }}</h2>
            <p class="stat-detail">{{ stat.detail }}</p>
          </section>
        </div>
      </article>
    </section>

    <section v-if="isSkillGameView" id="ai-skill-game" class="skill-game-view reveal-surface" style="--reveal-order: 3">
      <div class="game-anchor">
        <AiPromptGame
          :signature="designSignature"
          :topics="focusTopics"
          :theme-style="gameThemeStyle"
          :theme-mode="personalizationMode"
        />
      </div>
    </section>

    <section v-if="isHomeView || isConversationView" id="ai-conversations" class="terminal-shell reveal-surface" :class="{ expanded: terminalExpanded }" :style="terminalShellStyle">
      <p v-if="widgetBuildSession" class="build-mode-banner">
        Widget Build Mode · Step: {{ widgetBuildStepLabel }} · Answer prompts or use /widget cancel
      </p>

      <div class="conversation-bar">
        <div class="conversation-head">
          <p class="mission-kicker">AI Conversations</p>
          <div class="conversation-meta">
            <p class="conversation-count">{{ threadSummary }}</p>
            <div class="terminal-tools">
              <button type="button" class="terminal-tool-btn" @click="adjustTranscriptHeight(-80)">-</button>
              <button type="button" class="terminal-tool-btn" @click="adjustTranscriptHeight(80)">+</button>
              <button type="button" class="terminal-tool-btn" @click="toggleTerminalExpanded">
                {{ terminalExpanded ? 'Collapse' : 'Expand' }}
              </button>
            </div>
          </div>
        </div>
        <p class="conversation-active">{{ activeConversationSummary }}</p>
        <div class="conversation-actions">
          <button
            v-for="thread in conversationThreads"
            :key="thread.id"
            type="button"
            class="thread-chip"
            :class="{ active: thread.id === activeConversationId }"
            @click="switchConversation(thread.id)"
          >
            {{ thread.title }}
          </button>
          <button type="button" class="thread-new" @click="startNewConversation">
            + New
          </button>
        </div>
      </div>

      <div v-if="assistantStreaming" class="stream-shell" aria-live="polite">
        <div class="stream-bars">
          <span></span>
          <span></span>
          <span></span>
          <span></span>
          <span></span>
        </div>
        <div class="stream-copy">
          <p>{{ assistantStreamPhase || 'Streaming assistant response...' }}</p>
          <div v-if="imageRenderPending" class="image-pipeline-preview">
            <div class="pipeline-grid"></div>
            <div class="pipeline-copy">
              <strong>Multimodal Render Queue</strong>
              <span>{{ imageRenderPrompt || 'Generating visual...' }}</span>
            </div>
            <div class="pipeline-pulse"></div>
          </div>
        </div>
      </div>

      <div ref="transcriptRef" class="transcript" aria-live="polite">
        <article
          v-for="(line, lineIndex) in transcript"
          :key="line.id"
          class="line"
          :class="`tone-${line.tone}`"
          :style="{ '--line-order': `${Math.min(lineIndex, 22)}` }"
        >
          <span class="glyph">
            {{ line.tone === 'user' ? '>' : line.tone === 'signal' ? '#' : line.tone === 'assistant' ? '*' : '$' }}
          </span>
          <div class="line-body">
            <div class="line-meta">
              <span class="line-role">{{ toneLabel(line.tone) }}</span>
              <span class="line-time">{{ formatLineTime(line) }}</span>
            </div>
            <div class="line-actions">
              <button type="button" @click="copyLineText(line)">Copy</button>
              <button v-if="line.imageUrl" type="button" @click="openImagePreview(line)">Expand</button>
              <button v-if="line.imageUrl" type="button" @click="copyLineImage(line)">Copy Image</button>
              <button v-if="line.imageUrl" type="button" @click="downloadLineImage(line)">Download Image</button>
              <span v-if="lineActionStatus[line.id]" class="line-action-status">{{ lineActionStatus[line.id] }}</span>
            </div>
            <p class="line-text">{{ line.text }}</p>
            <div v-if="line.imageUrl" class="line-media-shell">
              <div v-if="mediaLoadState[line.id] !== 'ready'" class="line-media-loading">
                <div class="line-media-grid"></div>
                <div class="line-media-pulse"></div>
                <p>{{ mediaLoadState[line.id] === 'error' ? 'Rebuilding preview...' : 'Generating image...' }}</p>
              </div>
              <img
                class="line-media"
                :src="line.imageUrl"
                :alt="line.imageAlt || 'Generated image'"
                loading="lazy"
                @click="openImagePreview(line)"
                @load="handleTranscriptImageLoad(line)"
                @error="handleTranscriptImageError($event, line)"
              />
            </div>
          </div>
        </article>
      </div>

      <form class="command-row" @submit.prevent="handleComposerSubmit">
        <span class="glyph">></span>
        <button
          type="button"
          class="upload-btn"
          :disabled="assistantStreaming"
          @click="openFilePicker"
        >
          Upload
        </button>
        <input
          v-model="commandInput"
          type="text"
          autocomplete="off"
          :placeholder="commandPlaceholder"
          @keydown="handleCommandInputKeydown"
        />
      </form>
      <input
        ref="filePickerRef"
        class="file-picker"
        type="file"
        accept="image/*,.txt,.md,.json,.csv,.pdf,.doc,.docx,.xlsx,.xls"
        multiple
        @change="handleFileSelection"
      />
      <div v-if="pendingAttachments.length > 0" class="attachment-row">
        <article
          v-for="file in pendingAttachments"
          :key="file.id"
          class="attachment-chip"
        >
          <span>{{ file.kind.toUpperCase() }} · {{ file.name }} · {{ formatAttachmentSize(file.sizeBytes) }}</span>
          <button type="button" @click="removePendingAttachment(file.id)">Remove</button>
        </article>
      </div>
      <div class="command-meta">
        <p>{{ commandStatus }}</p>
      </div>
      <div class="command-hints">
        <button
          v-for="hint in commandHints"
          :key="hint.command"
          type="button"
          :disabled="assistantStreaming"
          @click="runCommandHint(hint.command)"
        >
          {{ hint.label }}
        </button>
      </div>

      <div class="assistant-actions">
        <button type="button" @click="openAction(assistantPrimaryCta.action)">
          {{ assistantPrimaryCta.label }}
        </button>
      </div>
    </section>

    <section v-if="isHomeView || isWidgetsView" id="widget-studio" class="widget-studio reveal-surface" style="--reveal-order: 5">
      <header class="studio-head">
        <p class="mission-kicker">Widget Studio</p>
        <p class="studio-meta">Deployable widgets: {{ widgets.length }}/{{ MAX_DASHBOARD_WIDGETS }}</p>
      </header>

      <div v-if="widgets.length === 0" class="empty-widgets">
        <p>No widgets yet. Try:</p>
        <p>/widget build</p>
        <p>/image cinematic neon skyline over the desert at sunrise</p>
        <p>build me a widget for weather in Austin</p>
        <p>/widget build Daily Coach || Give me one focused action for the day and two follow-ups</p>
        <p>/widget add weather Austin</p>
        <p>/widget add horoscope</p>
        <p>/widget add sports NHL</p>
        <p>/widget edit W-ABC123 (or use the Edit button)</p>
        <p>/widget html Daily Brief || &lt;section&gt;&lt;h4&gt;Daily Brief&lt;/h4&gt;&lt;p&gt;Write one clear prompt goal.&lt;/p&gt;&lt;/section&gt;</p>
        <p>Limit: {{ MAX_DASHBOARD_WIDGETS }} widgets total</p>
      </div>

      <article v-for="widget in widgets" :key="widget.id" class="widget-row">
        <div class="widget-head">
          <p class="widget-id">{{ widget.id }}</p>
          <div class="widget-controls">
            <button
              type="button"
              class="edit-widget"
              @click="editingWidgetId === widget.id ? cancelWidgetEdit() : startEditingWidget(widget.id)"
            >
              {{ editingWidgetId === widget.id ? 'Close Editor' : 'Edit' }}
            </button>
            <button type="button" class="remove-widget" @click="removeWidget(widget.id)">Remove</button>
          </div>
        </div>
        <h3>{{ widget.title }}</h3>
        <DashboardWidgetRenderer :widget="widget" :signature="widgetRuntimeSignature(widget.id)" />

        <form
          v-if="editingWidgetId === widget.id && widgetEditor"
          class="widget-editor"
          @submit.prevent="saveWidgetEdit"
        >
          <label>
            <span>Widget Title</span>
            <input v-model="widgetEditor.title" type="text" maxlength="80" />
          </label>

          <label v-if="widget.config.mode === 'prompt' || widgetEditor.prompt">
            <span>Prompt</span>
            <textarea v-model="widgetEditor.prompt" rows="4"></textarea>
          </label>

          <div v-if="widget.config.mode === 'prompt' || widgetEditor.prompt" class="editor-grid">
            <label>
              <span>Output Style</span>
              <select v-model="widgetEditor.outputStyle">
                <option value="brief">brief</option>
                <option value="bullets">bullets</option>
                <option value="checklist">checklist</option>
              </select>
            </label>

            <label>
              <span>Audience / Context</span>
              <input v-model="widgetEditor.audience" type="text" placeholder="founder, reader, investor, etc." />
            </label>
          </div>

          <label v-if="widget.type === 'customHtml' && widget.config.mode !== 'prompt'">
            <span>HTML Markup</span>
            <textarea v-model="widgetEditor.html" rows="4"></textarea>
          </label>

          <div class="editor-config">
            <p>Runtime Config</p>
            <div v-for="(entry, idx) in widgetEditor.configEntries" :key="`${widget.id}:cfg:${idx}`" class="cfg-row">
              <input v-model="entry.key" type="text" placeholder="key" />
              <input v-model="entry.value" type="text" placeholder="value" />
              <button type="button" class="cfg-remove" @click="removeConfigEntry(idx)">x</button>
            </div>
            <button type="button" class="cfg-add" @click="addConfigEntry">+ Add Field</button>
          </div>

          <div class="editor-actions">
            <button
              v-if="widget.config.mode === 'prompt' || widgetEditor.prompt"
              type="button"
              class="tune-btn"
              @click="optimizePromptDraft"
            >
              Optimize Prompt
            </button>
            <button type="button" class="cancel-btn" @click="cancelWidgetEdit">Cancel</button>
            <button type="submit" class="save-btn">Save + Re-Run</button>
          </div>
        </form>
      </article>
    </section>

    <section v-if="isHomeView || isBlogView" class="tracks-grid reveal-surface" style="--reveal-order: 6">
      <article v-for="track in scene.tracks" :key="track.id" class="track-card">
        <p class="track-signal">{{ track.signal }}</p>
        <h2>{{ track.label }}</h2>
        <p>{{ track.summary }}</p>
        <button type="button" @click="openAction(track.ctaHref)">{{ track.ctaLabel }}</button>
      </article>
    </section>

    <section v-if="isHomeView || isBlogView" id="blog-posts" class="content-stream reveal-surface" style="--reveal-order: 7">
      <article v-for="post in posts" :key="post.id" class="post-row">
        <img v-if="post.imageUrl" :src="post.imageUrl" alt="" loading="lazy" />
        <div>
          <p class="post-meta">{{ post.meta }}</p>
          <h3>{{ post.title }}</h3>
          <p>{{ post.description }}</p>
          <a :href="post.href" :target="isExternalUrl(post.href) ? '_blank' : '_self'" :rel="isExternalUrl(post.href) ? 'noopener noreferrer' : undefined">Open</a>
        </div>
      </article>
    </section>

    <section v-if="isHomeView || isBlogView" class="shortcut-row reveal-surface" style="--reveal-order: 8">
      <a
        v-for="shortcut in shortcuts"
        :key="`${shortcut.label}:${shortcut.action}`"
        class="shortcut-chip"
        :href="shortcut.action"
        :target="isExternalUrl(shortcut.action) ? '_blank' : '_self'"
        :rel="isExternalUrl(shortcut.action) ? 'noopener noreferrer' : undefined"
      >
        {{ shortcut.label }}
      </a>
    </section>

    <nav class="mobile-dock" aria-label="Mobile workspace navigation">
      <button
        v-for="section in appNavSections"
        :key="`dock-${section.id}`"
        type="button"
        :class="{ active: activeSectionId === section.id }"
        @click="navigateToView(section.path)"
      >
        {{ section.label }}
      </button>
    </nav>
      </div>
    </Transition>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="imagePreview"
        class="image-viewer"
        role="dialog"
        aria-modal="true"
        @click.self="closeImagePreview"
      >
        <article class="image-viewer-card">
          <header>
            <p>{{ imagePreview.alt }}</p>
            <button type="button" @click="closeImagePreview">Close</button>
          </header>
          <img :src="imagePreview.url" :alt="imagePreview.alt" />
          <div class="image-viewer-actions">
            <button type="button" @click="copyPreviewImage">Copy Image</button>
            <button type="button" @click="downloadPreviewImage">Download</button>
            <button type="button" @click="openPreviewImageTab">Open Tab</button>
          </div>
        </article>
      </div>
    </Teleport>
  </main>
</template>

<style scoped>
.experience-root {
  --accent-rgb: 22, 199, 207;
  --accent-soft-rgb: 120, 224, 228;
  --accent-sharp-rgb: 16, 153, 178;
  --surface-main: rgba(2, 6, 23, 0.78);
  --surface-card: rgba(2, 10, 28, 0.78);
  --surface-elevated: rgba(3, 7, 18, 0.84);
  --surface-terminal: rgba(2, 8, 22, 0.92);
  --scene-grid-opacity: 0.03;
  --scene-panel-radius: 14px;
  --scene-glow-strength: 0.2;
  --border-tone: rgba(var(--accent-rgb), 0.32);
  --text-primary: #d1fae5;
  --text-secondary: #a7f3d0;
  --text-signal: rgb(var(--accent-soft-rgb));
  min-height: 100vh;
  background:
    radial-gradient(circle at 12% -12%, rgba(var(--accent-rgb), var(--scene-glow-strength)), transparent 38%),
    radial-gradient(circle at 88% 118%, rgba(var(--accent-soft-rgb), calc(var(--scene-glow-strength) * 0.88)), transparent 42%),
    radial-gradient(circle at 50% 50%, rgba(var(--accent-sharp-rgb), calc(var(--scene-glow-strength) * 0.54)), transparent 58%),
    #000000;
  color: var(--text-primary);
  font-family: 'Space Mono', 'IBM Plex Mono', 'Fira Code', monospace;
  padding: 1rem;
  position: relative;
  isolation: isolate;
  overflow: hidden;
}

.loading-root {
  display: grid;
  place-items: center;
}

.mode-light.experience-root {
  --surface-main: rgba(255, 255, 255, 0.84);
  --surface-card: rgba(245, 250, 255, 0.85);
  --surface-elevated: rgba(250, 253, 255, 0.9);
  --surface-terminal: rgba(248, 252, 255, 0.95);
  --border-tone: rgba(var(--accent-rgb), 0.35);
  --text-primary: #0f172a;
  --text-secondary: #334155;
  --text-signal: rgb(var(--accent-sharp-rgb));
  background:
    radial-gradient(circle at 12% -12%, rgba(var(--accent-rgb), 0.18), transparent 38%),
    radial-gradient(circle at 88% 118%, rgba(var(--accent-soft-rgb), 0.15), transparent 42%),
    #eef3fb;
}

.scene-variant-1.experience-root {
  --scene-panel-radius: 12px;
  --scene-grid-opacity: 0.045;
  --scene-glow-strength: 0.26;
}

.scene-variant-2.experience-root {
  --scene-panel-radius: 18px;
  --scene-grid-opacity: 0.052;
}

.scene-variant-3.experience-root {
  --scene-grid-opacity: 0.04;
  --scene-glow-strength: 0.3;
}

.scene-variant-4.experience-root {
  --scene-panel-radius: 16px;
  --scene-grid-opacity: 0.06;
  --scene-glow-strength: 0.32;
}

.scene-variant-5.experience-root {
  --scene-panel-radius: 20px;
  --scene-grid-opacity: 0.05;
  --scene-glow-strength: 0.28;
}

.scene-variant-6.experience-root {
  --scene-panel-radius: 12px;
  --scene-grid-opacity: 0.06;
  --scene-glow-strength: 0.34;
}

.scene-variant-7.experience-root {
  --scene-panel-radius: 22px;
  --scene-grid-opacity: 0.07;
  --scene-glow-strength: 0.38;
}

.experience-root::before,
.experience-root::after {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: -1;
}

.experience-root::before {
  background:
    linear-gradient(120deg, rgba(var(--accent-rgb), 0.08), transparent 32%, rgba(var(--accent-soft-rgb), 0.07) 66%, transparent),
    radial-gradient(circle at 22% 18%, rgba(var(--accent-rgb), 0.12), transparent 46%),
    radial-gradient(circle at 78% 72%, rgba(var(--accent-soft-rgb), 0.1), transparent 44%);
  mix-blend-mode: screen;
  animation: aurora-shift 24s linear infinite;
}

.experience-root::after {
  background:
    linear-gradient(transparent 90%, rgba(var(--accent-rgb), 0.07) 100%),
    repeating-linear-gradient(
      0deg,
      transparent 0,
      transparent 12px,
      rgba(var(--accent-rgb), var(--scene-grid-opacity)) 13px,
      transparent 14px
    );
  opacity: 0.35;
}

@keyframes aurora-shift {
  0% {
    transform: translate3d(0, 0, 0) scale(1);
    opacity: 0.55;
  }
  50% {
    transform: translate3d(0, -1.4%, 0) scale(1.04);
    opacity: 0.8;
  }
  100% {
    transform: translate3d(0, 0, 0) scale(1);
    opacity: 0.55;
  }
}

.fx-stage {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
  z-index: -1;
}

.fx-canvas {
  opacity: 0.42;
  mix-blend-mode: screen;
}

.fx-orb {
  position: absolute;
  display: block;
  border-radius: 999px;
  background: radial-gradient(circle, rgba(var(--accent-rgb), 0.27), rgba(var(--accent-rgb), 0.05) 62%, transparent 78%);
  filter: blur(10px);
  opacity: 0.7;
  animation: orb-drift var(--duration) ease-in-out infinite;
  animation-delay: var(--delay);
}

@keyframes orb-drift {
  0%, 100% {
    transform: translate3d(0, 0, 0) scale(1);
    opacity: 0.36;
  }
  50% {
    transform: translate3d(var(--drift), calc(var(--drift) * -0.6), 0) scale(1.08);
    opacity: 0.62;
  }
}

.reveal-surface {
  opacity: 0;
  transform: translate3d(0, 18px, 0) scale(0.992);
  animation: section-in 0.64s cubic-bezier(0.2, 0.92, 0.18, 1) forwards;
  animation-delay: calc(80ms + (var(--reveal-order, 0) * 80ms));
}

@keyframes section-in {
  0% {
    opacity: 0;
    transform: translate3d(0, 18px, 0) scale(0.992);
    filter: saturate(0.8);
  }
  100% {
    opacity: 1;
    transform: translate3d(0, 0, 0) scale(1);
    filter: saturate(1);
  }
}

.loading-card {
  border: 1px solid var(--border-tone);
  border-radius: 14px;
  padding: 0.95rem 1rem;
  background: var(--surface-main);
  backdrop-filter: blur(10px);
}

.app-shell {
  display: grid;
  gap: 0.85rem;
  min-width: 0;
}

.app-main {
  min-width: 0;
  display: grid;
  gap: 0;
}

.view-stage {
  min-width: 0;
  display: grid;
  gap: 0.85rem;
  align-content: start;
}

.view-swap-enter-active,
.view-swap-leave-active {
  transition: opacity 0.22s ease, transform 0.22s ease;
}

.view-swap-enter-from,
.view-swap-leave-to {
  opacity: 0;
  transform: translate3d(0, 10px, 0);
}

.app-sidebar {
  border: 1px solid var(--border-tone);
  border-radius: var(--scene-panel-radius);
  background: var(--surface-main);
  padding: 0.78rem;
  backdrop-filter: blur(15px);
  box-shadow: 0 16px 34px rgba(2, 6, 23, 0.3);
  display: grid;
  gap: 0.62rem;
}

.sidebar-brand {
  margin-bottom: 0.2rem;
}

.sidebar-kicker {
  margin: 0;
  font-size: 0.66rem;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--text-signal);
}

.sidebar-nav {
  display: grid;
  gap: 0.34rem;
}

.sidebar-nav button {
  border: 1px solid rgba(var(--accent-rgb), 0.3);
  border-radius: 10px;
  background: rgba(var(--accent-rgb), 0.08);
  color: var(--text-primary);
  text-align: left;
  font-size: 0.78rem;
  letter-spacing: 0.02em;
  padding: 0.44rem 0.56rem;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
}

.sidebar-nav button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  background: rgba(var(--accent-rgb), 0.18);
}

.sidebar-nav button.active {
  border-color: rgba(var(--accent-rgb), 0.78);
  background: linear-gradient(130deg, rgba(var(--accent-rgb), 0.32), rgba(var(--accent-sharp-rgb), 0.26));
  box-shadow: inset 0 1px 0 rgba(var(--accent-soft-rgb), 0.3);
}

.sidebar-meta {
  margin-top: 0.3rem;
  border: 1px solid rgba(var(--accent-rgb), 0.24);
  border-radius: 10px;
  padding: 0.52rem 0.56rem;
  background: rgba(var(--accent-rgb), 0.1);
  display: grid;
  gap: 0.14rem;
}

.sidebar-meta-label {
  margin: 0;
  font-size: 0.64rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: var(--text-signal);
}

.sidebar-meta-value {
  margin: 0;
  font-size: 1.08rem;
  line-height: 1.05;
  color: var(--text-primary);
}

.sidebar-meta-detail {
  margin: 0;
  font-size: 0.72rem;
  color: var(--text-secondary);
}

.topbar {
  display: flex;
  flex-direction: column;
  gap: 0.72rem;
  border: 1px solid var(--border-tone);
  border-radius: var(--scene-panel-radius);
  background: var(--surface-main);
  padding: 0.8rem 0.9rem;
  backdrop-filter: blur(16px);
  box-shadow: 0 20px 42px rgba(0, 0, 0, 0.25);
  transition: border-color 0.24s ease, transform 0.24s ease, box-shadow 0.24s ease;
  position: relative;
  z-index: 25;
  margin-bottom: 0.9rem;
}

.topbar:hover {
  border-color: rgba(var(--accent-rgb), 0.56);
  box-shadow: 0 26px 52px rgba(0, 0, 0, 0.34);
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  color: inherit;
  text-decoration: none;
}

.brand img {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  border: 1px solid rgba(var(--accent-rgb), 0.45);
  box-shadow: 0 0 0 4px rgba(var(--accent-rgb), 0.1);
}

.brand span {
  display: grid;
  line-height: 1.02;
}

.brand strong {
  font-size: 0.82rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.brand em {
  font-style: normal;
  font-size: 0.72rem;
  color: var(--text-signal);
}

.topbar-head h2 {
  margin: 0.34rem 0 0;
  font-size: clamp(1rem, 2.3vw, 1.3rem);
  overflow-wrap: anywhere;
}

.topbar-primary {
  display: grid;
  gap: 0.7rem;
}

.topbar-copy {
  margin: 0.3rem 0 0;
  color: var(--text-secondary);
  font-size: 0.78rem;
  line-height: 1.45;
}

.topbar-meta {
  display: grid;
  gap: 0.4rem;
  justify-items: start;
}

.topbar-meta p {
  margin: 0;
  color: var(--text-secondary);
  font-size: 0.74rem;
  letter-spacing: 0.06em;
  text-align: left;
}

.persona-line {
  color: var(--text-signal) !important;
  font-size: 0.7rem !important;
  text-transform: uppercase;
  letter-spacing: 0.09em;
}

.topbar-meta button {
  border: 1px solid var(--border-tone);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.14);
  color: var(--text-primary);
  padding: 0.38rem 0.75rem;
  transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
}

.topbar-meta button:hover {
  transform: translateY(-1px);
  background: rgba(var(--accent-rgb), 0.24);
  border-color: rgba(var(--accent-rgb), 0.6);
}

.mission-shell {
  display: grid;
  gap: 0.7rem;
}

.dashboard-shell {
  display: grid;
  gap: 0.7rem;
}

.skill-game-view {
  display: grid;
  gap: 0.7rem;
}

.mission-card,
.prompt-card,
.dashboard-card {
  border: 1px solid var(--border-tone);
  border-radius: var(--scene-panel-radius);
  background: var(--surface-card);
  padding: 0.9rem;
  backdrop-filter: blur(14px);
  box-shadow: 0 14px 28px rgba(2, 6, 23, 0.35);
  transition: border-color 0.22s ease, box-shadow 0.22s ease, transform 0.22s ease;
}

.mission-card:hover,
.prompt-card:hover,
.dashboard-card:hover {
  border-color: rgba(var(--accent-rgb), 0.52);
  box-shadow: 0 22px 42px rgba(2, 6, 23, 0.46);
  transform: translateY(-2px);
}

.mission-kicker {
  margin: 0;
  font-size: 0.73rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: var(--text-signal);
}

h1 {
  margin: 0.38rem 0 0;
  font-size: clamp(1.2rem, 3.5vw, 2rem);
  line-height: 1.08;
}

.voice-line,
.pulse-line,
.warning-line {
  margin: 0.45rem 0 0;
  color: var(--text-secondary);
}

.mysite-explainer {
  margin: 0.45rem 0 0;
  color: var(--text-primary);
  line-height: 1.45;
  max-width: 70ch;
}

.warning-line {
  color: #fca5a5;
}

.topic-row {
  margin-top: 0.52rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.topic-row span {
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  border-radius: 999px;
  padding: 0.2rem 0.55rem;
  font-size: 0.72rem;
  color: var(--text-primary);
  background: rgba(var(--accent-rgb), 0.14);
}

.mission-nav {
  margin-top: 0.58rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.mission-nav button {
  border: 1px solid rgba(var(--accent-rgb), 0.46);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.16);
  color: var(--text-primary);
  padding: 0.28rem 0.68rem;
  font-size: 0.74rem;
  letter-spacing: 0.03em;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.mission-nav button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.74);
  background: rgba(var(--accent-rgb), 0.28);
}

#mission-hub,
#dashboard-hub,
#ai-skill-game,
#ai-conversations,
#widget-studio,
#blog-posts {
  scroll-margin-top: 6.4rem;
}

.prompt-card ul {
  margin: 0.55rem 0 0;
  padding-left: 1.1rem;
  display: grid;
  gap: 0.35rem;
  color: var(--text-secondary);
}

.stats-grid {
  margin-top: 0.55rem;
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.stat-card {
  border: 1px solid var(--border-tone);
  border-radius: 10px;
  background: var(--surface-elevated);
  padding: 0.58rem;
  transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
}

.stat-card:hover {
  border-color: rgba(var(--accent-rgb), 0.62);
  transform: translateY(-1px);
  background: color-mix(in srgb, var(--surface-elevated) 86%, rgba(var(--accent-rgb), 0.18));
}

.stat-label {
  margin: 0;
  color: var(--text-signal);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.stat-value {
  margin: 0.25rem 0 0;
  font-size: 1.1rem;
  line-height: 1.05;
}

.stat-detail {
  margin: 0.28rem 0 0;
  color: var(--text-secondary);
  font-size: 0.8rem;
}

.game-anchor {
  min-width: 0;
}

.mobile-dock {
  position: fixed;
  left: 0.5rem;
  right: 0.5rem;
  bottom: 0.45rem;
  z-index: 38;
  border: 1px solid rgba(var(--accent-rgb), 0.42);
  border-radius: 12px;
  background: color-mix(in srgb, var(--surface-terminal) 88%, rgba(var(--accent-rgb), 0.24));
  backdrop-filter: blur(12px);
  padding: 0.38rem;
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.3rem;
}

.mobile-dock button {
  border: 1px solid rgba(var(--accent-rgb), 0.35);
  border-radius: 9px;
  background: rgba(var(--accent-rgb), 0.1);
  color: var(--text-primary);
  font-size: 0.64rem;
  line-height: 1.15;
  padding: 0.35rem 0.3rem;
  text-align: center;
}

.mobile-dock button.active {
  border-color: rgba(var(--accent-rgb), 0.78);
  background: linear-gradient(130deg, rgba(var(--accent-rgb), 0.28), rgba(var(--accent-sharp-rgb), 0.22));
}

.terminal-shell {
  border: 1px solid var(--border-tone);
  border-radius: var(--scene-panel-radius);
  background: var(--surface-terminal);
  overflow: hidden;
  backdrop-filter: blur(14px);
  box-shadow: 0 20px 42px rgba(2, 6, 23, 0.42);
  transition: border-color 0.24s ease, box-shadow 0.24s ease;
}

.terminal-shell:hover {
  border-color: rgba(var(--accent-rgb), 0.5);
  box-shadow: 0 28px 56px rgba(2, 6, 23, 0.52);
}

.terminal-shell.expanded {
  position: fixed;
  inset: 0.8rem;
  z-index: 56;
  border-width: 2px;
  background: color-mix(in srgb, var(--surface-main) 92%, black);
  box-shadow: 0 20px 46px rgba(2, 6, 23, 0.45);
}

.build-mode-banner {
  margin: 0;
  padding: 0.6rem 0.85rem;
  border-bottom: 1px solid var(--border-tone);
  background: rgba(var(--accent-rgb), 0.18);
  color: var(--text-primary);
  font-size: 0.78rem;
  letter-spacing: 0.03em;
}

.conversation-bar {
  border-bottom: 1px solid var(--border-tone);
  padding: 0.62rem 0.85rem 0.74rem;
  display: grid;
  gap: 0.45rem;
  background:
    linear-gradient(120deg, rgba(var(--accent-rgb), 0.16), rgba(var(--accent-rgb), 0.06) 48%, rgba(var(--accent-soft-rgb), 0.12));
}

.conversation-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.45rem;
}

.conversation-count {
  margin: 0;
  color: var(--text-secondary);
  font-size: 0.72rem;
}

.conversation-active {
  margin: 0;
  color: color-mix(in srgb, var(--text-secondary) 86%, rgb(var(--accent-soft-rgb)));
  font-size: 0.72rem;
  letter-spacing: 0.04em;
  padding-right: 0.35rem;
}

.conversation-meta {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
}

.terminal-tools {
  display: inline-flex;
  align-items: center;
  gap: 0.28rem;
}

.terminal-tool-btn {
  border-radius: 999px;
  border: 1px solid var(--border-tone);
  background: rgba(var(--accent-rgb), 0.16);
  color: var(--text-primary);
  padding: 0.16rem 0.48rem;
  font-size: 0.68rem;
  letter-spacing: 0.04em;
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.terminal-tool-btn:hover {
  background: rgba(var(--accent-rgb), 0.3);
  border-color: rgba(var(--accent-rgb), 0.64);
  transform: translateY(-1px);
}

.conversation-actions {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.35rem;
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-width: thin;
  padding-bottom: 0.15rem;
  scroll-snap-type: x proximity;
}

.thread-chip,
.thread-new {
  flex: 0 0 auto;
  scroll-snap-align: start;
  border-radius: 999px;
  border: 1px solid var(--border-tone);
  background: rgba(var(--accent-rgb), 0.13);
  color: var(--text-primary);
  padding: 0.2rem 0.58rem;
  font-size: 0.7rem;
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
}

.thread-chip.active {
  border-color: rgba(var(--accent-rgb), 0.66);
  background: rgba(var(--accent-rgb), 0.26);
  box-shadow: 0 8px 20px rgba(var(--accent-rgb), 0.18);
}

.thread-chip:hover,
.thread-new:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  background: rgba(var(--accent-rgb), 0.24);
}

.stream-shell {
  padding: 0.62rem 0.85rem;
  border-bottom: 1px solid var(--border-tone);
  background: rgba(var(--accent-rgb), 0.12);
  display: flex;
  align-items: center;
  gap: 0.58rem;
}

.stream-shell p {
  margin: 0;
  font-size: 0.78rem;
  color: var(--text-primary);
}

.stream-copy {
  display: grid;
  gap: 0.45rem;
  min-width: 0;
}

.image-pipeline-preview {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(var(--accent-rgb), 0.36);
  border-radius: 10px;
  padding: 0.45rem 0.55rem;
  background: rgba(var(--accent-rgb), 0.1);
  display: grid;
  gap: 0.3rem;
  min-width: min(100%, 520px);
}

.pipeline-grid {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(90deg, rgba(var(--accent-rgb), 0.08) 1px, transparent 1px),
    linear-gradient(0deg, rgba(var(--accent-rgb), 0.08) 1px, transparent 1px);
  background-size: 14px 14px;
  opacity: 0.6;
}

.pipeline-copy {
  position: relative;
  display: grid;
  gap: 0.18rem;
}

.pipeline-copy strong {
  font-size: 0.72rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgb(var(--accent-soft-rgb));
}

.pipeline-copy span {
  font-size: 0.76rem;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pipeline-pulse {
  position: relative;
  height: 4px;
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.2);
  overflow: hidden;
}

.pipeline-pulse::after {
  content: '';
  position: absolute;
  inset: 0;
  width: 35%;
  background: linear-gradient(90deg, rgba(var(--accent-rgb), 0), rgba(var(--accent-soft-rgb), 0.9), rgba(var(--accent-rgb), 0));
  animation: pipeline-run 1.1s linear infinite;
}

@keyframes pipeline-run {
  0% {
    transform: translateX(-120%);
  }
  100% {
    transform: translateX(320%);
  }
}

.stream-bars {
  display: inline-flex;
  align-items: flex-end;
  gap: 0.16rem;
  min-width: 40px;
  height: 16px;
}

.stream-bars span {
  width: 4px;
  border-radius: 999px;
  background: rgb(var(--accent-soft-rgb));
  animation: stream-bars 0.9s ease-in-out infinite;
}

.stream-bars span:nth-child(1) { animation-delay: 0s; height: 6px; }
.stream-bars span:nth-child(2) { animation-delay: 0.1s; height: 10px; }
.stream-bars span:nth-child(3) { animation-delay: 0.2s; height: 14px; }
.stream-bars span:nth-child(4) { animation-delay: 0.3s; height: 10px; }
.stream-bars span:nth-child(5) { animation-delay: 0.4s; height: 6px; }

@keyframes stream-bars {
  0%, 100% { transform: scaleY(0.7); opacity: 0.65; }
  50% { transform: scaleY(1.05); opacity: 1; }
}

.transcript {
  min-height: 200px;
  max-height: var(--terminal-transcript-height, 320px);
  overflow: auto;
  padding: 0.85rem 0.85rem 1rem;
  display: grid;
  gap: 0.42rem;
  transition: max-height 0.2s ease;
  background:
    linear-gradient(180deg, rgba(var(--accent-rgb), 0.08), rgba(2, 6, 23, 0.15) 22%, transparent 42%),
    radial-gradient(circle at 90% 0%, rgba(var(--accent-rgb), 0.08), transparent 50%);
  scroll-behavior: smooth;
  scroll-padding-bottom: 1rem;
}

.terminal-shell.expanded .transcript {
  max-height: min(74vh, 920px);
}

.line {
  margin: 0;
  display: flex;
  gap: 0.5rem;
  align-items: stretch;
  font-size: 0.92rem;
  animation: line-in 0.28s cubic-bezier(0.19, 0.92, 0.22, 1) both;
  animation-delay: calc(var(--line-order, 0) * 9ms);
}

@keyframes line-in {
  from {
    opacity: 0;
    transform: translate3d(0, 8px, 0);
  }
  to {
    opacity: 1;
    transform: translate3d(0, 0, 0);
  }
}

.line-body {
  display: grid;
  gap: 0.35rem;
  min-width: 0;
  border: 1px solid rgba(var(--accent-rgb), 0.22);
  border-radius: 12px;
  background: rgba(2, 6, 23, 0.58);
  padding: 0.48rem 0.58rem 0.56rem;
  box-shadow: inset 0 1px 0 rgba(var(--accent-rgb), 0.1);
  transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
}

.line-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
}

.line-role {
  font-size: 0.66rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgb(var(--accent-soft-rgb));
}

.line-time {
  font-size: 0.65rem;
  letter-spacing: 0.06em;
  color: color-mix(in srgb, var(--text-secondary) 88%, rgb(var(--accent-soft-rgb)));
  white-space: nowrap;
}

.line-text {
  margin: 0;
  overflow-wrap: anywhere;
  line-height: 1.45;
}

.line-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.28rem;
  align-items: center;
}

.line-actions button {
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.12);
  color: var(--text-primary);
  padding: 0.1rem 0.46rem;
  font-size: 0.62rem;
  letter-spacing: 0.03em;
  transition: transform 0.14s ease, border-color 0.14s ease, background 0.14s ease;
}

.line-actions button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.64);
  background: rgba(var(--accent-rgb), 0.24);
}

.line-action-status {
  font-size: 0.62rem;
  color: rgb(var(--accent-soft-rgb));
}

.tone-user .line-body {
  border-color: rgba(var(--accent-rgb), 0.4);
  background: linear-gradient(140deg, rgba(var(--accent-rgb), 0.18), rgba(2, 6, 23, 0.82) 52%);
}

.tone-assistant .line-body {
  border-color: rgba(var(--accent-rgb), 0.34);
  background: linear-gradient(140deg, rgba(var(--accent-rgb), 0.12), rgba(2, 6, 23, 0.74) 58%);
  box-shadow: inset 0 1px 0 rgba(var(--accent-rgb), 0.24), 0 12px 22px rgba(2, 6, 23, 0.32);
}

.tone-signal .line-body {
  border-color: rgba(var(--accent-rgb), 0.28);
  background: rgba(var(--accent-rgb), 0.1);
}

.tone-system .line-body {
  border-color: rgba(var(--accent-rgb), 0.18);
  background: rgba(2, 6, 23, 0.44);
}

.line-media {
  width: min(100%, 360px);
  border-radius: 10px;
  border: 1px solid rgba(var(--accent-rgb), 0.35);
  box-shadow: 0 14px 28px rgba(2, 6, 23, 0.36);
}

.line-media-shell {
  position: relative;
  width: min(100%, 360px);
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(var(--accent-rgb), 0.35);
  box-shadow: 0 14px 28px rgba(2, 6, 23, 0.36);
}

.line-media-shell::after {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  left: -38%;
  width: 35%;
  background: linear-gradient(90deg, rgba(var(--accent-rgb), 0), rgba(var(--accent-soft-rgb), 0.22), rgba(var(--accent-rgb), 0));
  pointer-events: none;
  animation: media-glint 2.8s ease-in-out infinite;
}

@keyframes media-glint {
  0%,
  60%,
  100% {
    transform: translateX(0);
    opacity: 0;
  }
  22% {
    transform: translateX(220%);
    opacity: 0.95;
  }
}

.line-media-shell .line-media {
  display: block;
  width: 100%;
  border: none;
  border-radius: 0;
  box-shadow: none;
  background: rgba(2, 6, 23, 0.8);
  cursor: zoom-in;
}

.line-media-loading {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: grid;
  place-items: center;
  background: rgba(2, 6, 23, 0.8);
  color: var(--text-primary);
}

.line-media-loading p {
  margin: 0;
  position: relative;
  font-size: 0.75rem;
  letter-spacing: 0.04em;
}

.line-media-grid {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(90deg, rgba(var(--accent-rgb), 0.08) 1px, transparent 1px),
    linear-gradient(0deg, rgba(var(--accent-rgb), 0.08) 1px, transparent 1px);
  background-size: 16px 16px;
}

.line-media-pulse {
  position: absolute;
  left: 0;
  right: 0;
  height: 34%;
  background: linear-gradient(180deg, rgba(var(--accent-rgb), 0), rgba(var(--accent-rgb), 0.24), rgba(var(--accent-rgb), 0));
  animation: line-media-scan 1.4s linear infinite;
}

@keyframes line-media-scan {
  0% {
    transform: translateY(-120%);
  }
  100% {
    transform: translateY(220%);
  }
}

.tone-system {
  color: var(--text-secondary);
}

.tone-user {
  color: var(--text-primary);
}

.tone-signal {
  color: var(--text-signal);
}

.tone-assistant {
  color: color-mix(in srgb, var(--text-primary) 86%, rgb(var(--accent-soft-rgb)));
}

.glyph {
  color: rgb(var(--accent-soft-rgb));
  min-width: 1.1rem;
  font-size: 0.78rem;
  border: 1px solid rgba(var(--accent-rgb), 0.32);
  border-radius: 999px;
  width: 1.1rem;
  height: 1.1rem;
  display: inline-grid;
  place-items: center;
  margin-top: 0.15rem;
  background: rgba(var(--accent-rgb), 0.16);
}

.command-row {
  border-top: 1px solid var(--border-tone);
  display: grid;
  grid-template-columns: auto auto 1fr;
  gap: 0.45rem;
  align-items: center;
  padding: 0.75rem 0.85rem;
  background: linear-gradient(180deg, rgba(var(--accent-rgb), 0.06), rgba(var(--accent-rgb), 0.02));
}

.upload-btn {
  border: 1px solid rgba(var(--accent-rgb), 0.42);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.16);
  color: var(--text-primary);
  padding: 0.25rem 0.58rem;
  font-size: 0.68rem;
  letter-spacing: 0.04em;
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.upload-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.66);
  background: rgba(var(--accent-rgb), 0.26);
}

.upload-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.command-row input {
  width: 100%;
  border: 1px solid rgba(var(--accent-rgb), 0.26);
  outline: none;
  background: rgba(2, 6, 23, 0.46);
  color: var(--text-primary);
  border-radius: 10px;
  padding: 0.46rem 0.58rem;
  transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
}

.command-row input:focus-visible {
  border-color: rgba(var(--accent-rgb), 0.74);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), 0.2);
  background: rgba(2, 6, 23, 0.62);
}

.command-row input::placeholder {
  color: color-mix(in srgb, var(--text-secondary) 45%, transparent);
}

.file-picker {
  display: none;
}

.attachment-row {
  padding: 0 0.85rem 0.45rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.36rem;
}

.attachment-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.13);
  padding: 0.18rem 0.24rem 0.18rem 0.5rem;
  max-width: 100%;
}

.attachment-chip span {
  font-size: 0.66rem;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: min(72vw, 420px);
}

.attachment-chip button {
  border: 1px solid rgba(var(--accent-rgb), 0.5);
  border-radius: 999px;
  background: rgba(2, 6, 23, 0.54);
  color: var(--text-primary);
  padding: 0.12rem 0.44rem;
  font-size: 0.62rem;
}

.command-meta {
  border-top: 1px solid rgba(var(--accent-rgb), 0.14);
  padding: 0.2rem 0.85rem 0.45rem;
}

.command-meta p {
  margin: 0;
  font-size: 0.7rem;
  letter-spacing: 0.05em;
  color: color-mix(in srgb, var(--text-secondary) 86%, rgb(var(--accent-soft-rgb)));
}

.command-hints {
  display: flex;
  flex-wrap: wrap;
  gap: 0.38rem;
  padding: 0 0.85rem 0.62rem;
}

.command-hints button {
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.14);
  color: var(--text-primary);
  padding: 0.22rem 0.58rem;
  font-size: 0.68rem;
  letter-spacing: 0.03em;
  transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}

.command-hints button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  background: rgba(var(--accent-rgb), 0.24);
}

.command-hints button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

.assistant-actions {
  border-top: 1px solid var(--border-tone);
  padding: 0.55rem 0.85rem 0.7rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.42rem;
}

.assistant-actions button {
  border: 1px solid var(--border-tone);
  border-radius: 999px;
  background: linear-gradient(135deg, rgba(var(--accent-rgb), 0.24), rgba(var(--accent-sharp-rgb), 0.18));
  color: var(--text-primary);
  padding: 0.32rem 0.7rem;
  font-size: 0.74rem;
  letter-spacing: 0.03em;
  transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.assistant-actions button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  box-shadow: 0 8px 18px rgba(var(--accent-rgb), 0.16);
}

.assistant-actions button:active {
  transform: translateY(0);
}

.image-viewer {
  position: fixed;
  inset: 0;
  z-index: 160;
  background:
    radial-gradient(circle at 18% 12%, rgba(var(--accent-rgb), 0.22), transparent 42%),
    radial-gradient(circle at 82% 88%, rgba(var(--accent-soft-rgb), 0.2), transparent 40%),
    rgba(2, 6, 23, 0.82);
  backdrop-filter: blur(10px) saturate(1.08);
  display: grid;
  place-items: center;
  padding: clamp(0.7rem, 2vw, 1.2rem);
}

.image-viewer-card {
  width: min(1120px, 96vw);
  max-height: min(94vh, 980px);
  border: 1px solid rgba(var(--accent-rgb), 0.72);
  border-radius: 18px;
  background: rgba(3, 10, 26, 0.97);
  box-shadow: 0 30px 62px rgba(2, 6, 23, 0.68);
  display: grid;
  grid-template-rows: auto 1fr auto;
  overflow: hidden;
}

.image-viewer-card header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.8rem;
  padding: 0.75rem 0.9rem;
  border-bottom: 1px solid rgba(var(--accent-rgb), 0.44);
  background: linear-gradient(120deg, rgba(var(--accent-rgb), 0.24), rgba(var(--accent-sharp-rgb), 0.18));
}

.image-viewer-card header p {
  margin: 0;
  font-size: 0.84rem;
  color: #f0fdf4;
  letter-spacing: 0.02em;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.image-viewer-card header button {
  border: 1px solid rgba(var(--accent-rgb), 0.72);
  border-radius: 999px;
  background: rgba(2, 6, 23, 0.6);
  color: #e2f8ef;
  padding: 0.22rem 0.68rem;
  font-size: 0.72rem;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.image-viewer-card header button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-soft-rgb), 0.82);
  background: rgba(var(--accent-rgb), 0.26);
}

.image-viewer-card img {
  width: 100%;
  height: 100%;
  max-height: min(74vh, 780px);
  object-fit: contain;
  background:
    linear-gradient(180deg, rgba(2, 8, 22, 0.96), rgba(2, 6, 23, 0.98)),
    repeating-linear-gradient(
      45deg,
      rgba(var(--accent-rgb), 0.05) 0,
      rgba(var(--accent-rgb), 0.05) 8px,
      transparent 8px,
      transparent 16px
    );
  padding: 0.35rem;
}

.image-viewer-actions {
  border-top: 1px solid rgba(var(--accent-rgb), 0.46);
  background: rgba(2, 6, 23, 0.88);
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  padding: 0.72rem 0.9rem 0.9rem;
}

.image-viewer-actions button {
  border: 1px solid rgba(var(--accent-rgb), 0.66);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.26);
  color: #e7fff5;
  padding: 0.3rem 0.74rem;
  font-size: 0.74rem;
  letter-spacing: 0.02em;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.image-viewer-actions button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-soft-rgb), 0.88);
  background: rgba(var(--accent-rgb), 0.38);
}

.widget-studio {
  border: 1px solid var(--border-tone);
  border-radius: var(--scene-panel-radius);
  background: var(--surface-card);
  padding: 0.9rem;
  display: grid;
  gap: 0.68rem;
  backdrop-filter: blur(14px);
  box-shadow: 0 18px 36px rgba(2, 6, 23, 0.35);
}

.studio-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.65rem;
}

.studio-meta {
  margin: 0;
  color: var(--text-secondary);
  font-size: 0.76rem;
}

.empty-widgets {
  border: 1px dashed rgba(var(--accent-rgb), 0.35);
  border-radius: 10px;
  padding: 0.65rem;
}

.empty-widgets p {
  margin: 0.25rem 0 0;
  color: var(--text-secondary);
  font-size: 0.82rem;
}

.widget-row {
  border: 1px solid var(--border-tone);
  border-radius: 10px;
  background: var(--surface-elevated);
  padding: 0.66rem;
  display: grid;
  gap: 0.45rem;
  box-shadow: inset 0 1px 0 rgba(var(--accent-rgb), 0.12);
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.widget-row:hover {
  transform: translateY(-2px);
  border-color: rgba(var(--accent-rgb), 0.56);
  box-shadow: inset 0 1px 0 rgba(var(--accent-rgb), 0.18), 0 16px 26px rgba(2, 6, 23, 0.35);
}

.widget-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.6rem;
}

.widget-id {
  margin: 0;
  color: var(--text-signal);
  font-size: 0.74rem;
  letter-spacing: 0.07em;
}

.widget-controls {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.edit-widget {
  border: 1px solid rgba(var(--accent-rgb), 0.5);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.18);
  color: var(--text-primary);
  padding: 0.24rem 0.58rem;
  font-size: 0.72rem;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.remove-widget {
  border: 1px solid rgba(220, 38, 38, 0.55);
  border-radius: 999px;
  background: rgba(69, 10, 10, 0.78);
  color: #fecaca;
  padding: 0.24rem 0.58rem;
  font-size: 0.72rem;
  transition: transform 0.16s ease, filter 0.16s ease;
}

.edit-widget:hover,
.remove-widget:hover {
  transform: translateY(-1px);
}

.remove-widget:hover {
  filter: saturate(1.06);
}

.widget-row h3 {
  margin: 0;
  font-size: 0.98rem;
}

.widget-editor {
  border: 1px solid rgba(var(--accent-rgb), 0.32);
  border-radius: 10px;
  padding: 0.65rem;
  background: rgba(2, 6, 23, 0.58);
  display: grid;
  gap: 0.5rem;
}

.widget-editor label {
  display: grid;
  gap: 0.22rem;
}

.widget-editor span {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--text-signal);
}

.widget-editor input,
.widget-editor textarea,
.widget-editor select {
  width: 100%;
  border: 1px solid rgba(var(--accent-rgb), 0.4);
  border-radius: 8px;
  background: rgba(15, 23, 42, 0.72);
  color: var(--text-primary);
  padding: 0.42rem 0.48rem;
  font-family: inherit;
}

.editor-grid {
  display: grid;
  gap: 0.5rem;
}

.editor-config {
  border: 1px dashed rgba(var(--accent-rgb), 0.35);
  border-radius: 8px;
  padding: 0.45rem;
}

.editor-config p {
  margin: 0 0 0.4rem;
  font-size: 0.72rem;
  color: var(--text-signal);
}

.cfg-row {
  display: grid;
  grid-template-columns: minmax(0, 0.7fr) minmax(0, 1fr) auto;
  gap: 0.34rem;
  margin-top: 0.34rem;
}

.cfg-add,
.cfg-remove {
  border: 1px solid rgba(var(--accent-rgb), 0.45);
  background: rgba(var(--accent-rgb), 0.16);
  color: var(--text-primary);
  border-radius: 8px;
  padding: 0.3rem 0.42rem;
  font-size: 0.72rem;
}

.editor-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.4rem;
}

.tune-btn,
.cancel-btn,
.save-btn {
  border-radius: 999px;
  padding: 0.3rem 0.72rem;
  font-size: 0.74rem;
}

.tune-btn {
  border: 1px solid rgba(var(--accent-rgb), 0.55);
  background: rgba(var(--accent-rgb), 0.2);
  color: var(--text-primary);
}

.cancel-btn {
  border: 1px solid rgba(148, 163, 184, 0.5);
  background: rgba(15, 23, 42, 0.8);
  color: #e2e8f0;
}

.save-btn {
  border: 1px solid rgba(var(--accent-rgb), 0.58);
  background: linear-gradient(120deg, rgba(var(--accent-rgb), 0.68), rgba(var(--accent-sharp-rgb), 0.76));
  color: #04101a;
  font-weight: 700;
}

.tracks-grid {
  display: grid;
  gap: 0.68rem;
  grid-template-columns: 1fr;
}

.track-card {
  border: 1px solid var(--border-tone);
  border-radius: 12px;
  background: var(--surface-elevated);
  padding: 0.85rem;
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.track-card:hover {
  transform: translateY(-2px);
  border-color: rgba(var(--accent-rgb), 0.58);
  box-shadow: 0 16px 28px rgba(2, 6, 23, 0.36);
}

.track-signal {
  margin: 0;
  color: var(--text-signal);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.09em;
}

.track-card h2 {
  margin: 0.35rem 0 0;
  font-size: 1rem;
}

.track-card p {
  margin: 0.4rem 0 0;
  color: var(--text-secondary);
}

.track-card button {
  margin-top: 0.62rem;
  border: 1px solid var(--border-tone);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.15);
  color: var(--text-primary);
  padding: 0.34rem 0.72rem;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
}

.track-card button:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  background: rgba(var(--accent-rgb), 0.26);
}

.content-stream {
  display: grid;
  gap: 0.65rem;
}

.post-row {
  border: 1px solid var(--border-tone);
  border-radius: 12px;
  background: var(--surface-card);
  padding: 0.7rem;
  display: grid;
  gap: 0.65rem;
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.post-row:hover {
  transform: translateY(-2px);
  border-color: rgba(var(--accent-rgb), 0.56);
  box-shadow: 0 16px 28px rgba(2, 6, 23, 0.34);
}

.post-row img {
  width: 100%;
  max-height: 220px;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid rgba(var(--accent-rgb), 0.28);
  transition: transform 0.24s ease, filter 0.24s ease;
}

.post-row:hover img {
  transform: scale(1.02);
  filter: saturate(1.08);
}

.post-meta {
  margin: 0;
  color: var(--text-signal);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.post-row h3 {
  margin: 0.32rem 0 0;
  font-size: 1rem;
}

.post-row p {
  margin: 0.35rem 0 0;
  color: var(--text-secondary);
}

.post-row a {
  display: inline-block;
  margin-top: 0.5rem;
  color: var(--text-primary);
  text-decoration: none;
  border-bottom: 1px dashed rgb(var(--accent-soft-rgb));
  transition: color 0.16s ease, border-color 0.16s ease;
}

.post-row a:hover {
  color: rgb(var(--accent-soft-rgb));
  border-bottom-color: rgba(var(--accent-rgb), 0.85);
}

.shortcut-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.shortcut-chip {
  text-decoration: none;
  color: var(--text-primary);
  border: 1px solid var(--border-tone);
  border-radius: 999px;
  background: rgba(var(--accent-rgb), 0.16);
  padding: 0.3rem 0.65rem;
  font-size: 0.78rem;
  transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
}

.shortcut-chip:hover {
  transform: translateY(-1px);
  border-color: rgba(var(--accent-rgb), 0.62);
  background: rgba(var(--accent-rgb), 0.28);
  box-shadow: 0 8px 18px rgba(var(--accent-rgb), 0.16);
}

.experience-root :is(button, input, textarea, select, a):focus-visible {
  outline: 2px solid rgba(var(--accent-soft-rgb), 0.82);
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  .reveal-surface,
  .line,
  .fx-orb,
  .pipeline-pulse::after,
  .stream-bars span,
  .line-media-shell::after,
  .experience-root::before {
    animation: none !important;
  }

  .view-swap-enter-active,
  .view-swap-leave-active {
    transition: none !important;
  }
}

@media (max-width: 680px) {
  .experience-root {
    padding: 0.7rem 0.65rem 5rem;
  }

  .topbar {
    padding: 0.68rem 0.72rem;
    margin-bottom: 0.7rem;
  }

  .mission-card,
  .prompt-card,
  .dashboard-card,
  .widget-studio,
  .terminal-shell,
  .track-card,
  .post-row {
    padding: 0.62rem;
  }

  .command-row {
    grid-template-columns: auto 1fr;
  }

  .upload-btn {
    grid-column: 1 / -1;
    justify-self: start;
  }

  .topbar-copy,
  .topbar-meta p {
    font-size: 0.7rem;
  }

  .mission-nav {
    display: none;
  }
}

@media (max-width: 1079px) {
  .experience-root {
    padding-bottom: 5.1rem;
  }

  .app-sidebar {
    display: none;
  }

  .topbar-primary {
    grid-template-columns: minmax(0, 1fr);
  }

  .topbar-meta {
    justify-items: start;
  }

  .topbar-meta p {
    text-align: left;
  }

  .topbar-meta button {
    justify-self: start;
    width: 100%;
    max-width: 260px;
  }
}

@media (min-width: 860px) {
  .experience-root {
    padding: 1.2rem 1.8rem 2rem;
  }

  .topbar-primary {
    grid-template-columns: minmax(0, 1fr) minmax(220px, auto);
    column-gap: 1.1rem;
    align-items: start;
  }

  .topbar-head {
    min-width: 0;
  }

  .topbar-meta {
    justify-items: end;
  }

  .topbar-meta p {
    text-align: right;
  }

  .mission-shell {
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 0.7fr);
  }

  .dashboard-shell {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
  }

  .editor-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .widget-studio {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .studio-head,
  .empty-widgets {
    grid-column: 1 / -1;
  }

  .tracks-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .content-stream {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

}

@media (min-width: 1080px) {
  .experience-root {
    padding-bottom: 2rem;
  }

  .app-shell {
    grid-template-columns: minmax(232px, 264px) minmax(0, 1fr);
    align-items: start;
  }

  .app-sidebar {
    position: sticky;
    top: 1.2rem;
    max-height: calc(100vh - 2.4rem);
    overflow: auto;
  }

  .topbar {
    position: sticky;
    top: 1.2rem;
    margin-bottom: 1rem;
  }

  .mobile-dock {
    display: none;
  }
}

</style>
