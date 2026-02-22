import type { DashboardWidget } from '@/dashboard/engine';

const WIDGET_RUNTIME_ENDPOINT = '/api/ai/widget.php';

export interface WidgetRuntimePayload {
  widgetId: string;
  type: DashboardWidget['type'];
  refreshedAt: string;
  source: 'ai' | 'fallback' | 'external';
  payload: Record<string, unknown>;
}

function asRecord(value: unknown): Record<string, unknown> | null {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return value as Record<string, unknown>;
}

export async function refreshWidgetRuntime(
  widget: DashboardWidget,
  signature: string
): Promise<WidgetRuntimePayload | null> {
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), 18000);

  try {
    const response = await fetch(WIDGET_RUNTIME_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        widget,
        signature
      }),
      signal: controller.signal
    });

    if (!response.ok) {
      return null;
    }

    const payload = (await response.json()) as unknown;
    const record = asRecord(payload);
    if (!record) {
      return null;
    }

    const rawWidgetId = typeof record.widgetId === 'string' ? record.widgetId.trim() : '';
    const rawType = typeof record.type === 'string' ? record.type.trim() : '';
    const refreshedAt = typeof record.refreshedAt === 'string' ? record.refreshedAt.trim() : '';
    const sourceRaw = typeof record.source === 'string' ? record.source : 'fallback';
    const runtimePayload = asRecord(record.payload) ?? {};

    if (!rawWidgetId || !rawType || !refreshedAt) {
      return null;
    }

    if (!['weather', 'horoscope', 'fashion', 'sports', 'customHtml'].includes(rawType)) {
      return null;
    }

    const source: WidgetRuntimePayload['source'] =
      sourceRaw === 'ai' || sourceRaw === 'external' ? sourceRaw : 'fallback';

    return {
      widgetId: rawWidgetId,
      type: rawType as DashboardWidget['type'],
      refreshedAt,
      source,
      payload: runtimePayload
    };
  } catch {
    return null;
  } finally {
    clearTimeout(timeout);
  }
}
