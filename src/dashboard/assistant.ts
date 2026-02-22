import {
  createPresetWidget,
  generateSimpleHtmlWidgetFromRequest,
  type DashboardWidget,
  type DashboardWidgetType
} from '@/dashboard/engine';

export interface DashboardAssistantResult {
  reply: string;
  widget?: DashboardWidget;
  action?: 'list' | 'none';
}

function inferWidgetType(input: string): DashboardWidgetType | null {
  const text = input.toLowerCase();

  if (text.includes('horoscope') || text.includes('zodiac')) {
    return 'horoscope';
  }

  if (text.includes('fashion') || text.includes('style') || text.includes('trend')) {
    return 'fashion';
  }

  if (text.includes('sports') || text.includes('score') || text.includes('game')) {
    return 'sports';
  }

  if (text.includes('html') || text.includes('widget card') || text.includes('custom widget')) {
    return 'customHtml';
  }

  return null;
}

function inferTitle(input: string): string {
  const cleaned = input
    .replace(/\b(create|build|add|make|deploy|please|widget|for|a|an|the|new)\b/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  if (!cleaned) {
    return '';
  }

  return cleaned.length > 42 ? `${cleaned.slice(0, 39)}...` : cleaned;
}

export function handleNaturalLanguageWidgetRequest(
  input: string,
  seedSource: string,
  existingWidgetCount: number
): DashboardAssistantResult {
  const trimmed = input.trim();
  if (!trimmed) {
    return {
      reply: 'Share what you want to build, like: "add horoscope widget" or "build sports scores widget".'
    };
  }

  const normalized = trimmed.toLowerCase();

  if (normalized.includes('list widgets') || normalized.includes('show widgets') || normalized === 'widgets') {
    return {
      action: 'list',
      reply: existingWidgetCount > 0
        ? `You currently have ${existingWidgetCount} deployed widgets.`
        : 'No widgets deployed yet. Ask me to build one.'
    };
  }

  const widgetType = inferWidgetType(trimmed);
  if (!widgetType) {
    return {
      reply:
        'I can build widget types: horoscope, fashion trends, sports scores, or custom HTML. Try: "create a horoscope widget".'
    };
  }

  const titleHint = inferTitle(trimmed);

  if (widgetType === 'customHtml') {
    const widget = generateSimpleHtmlWidgetFromRequest(trimmed, seedSource);
    return {
      widget,
      reply: `Deployed HTML widget "${widget.title}". You can refine it with /widget html <title> || <html>.`
    };
  }

  const widget = createPresetWidget(widgetType, seedSource, titleHint);
  const labels: Record<DashboardWidgetType, string> = {
    horoscope: 'horoscope',
    fashion: 'fashion trend',
    sports: 'sports score',
    customHtml: 'custom html'
  };

  return {
    widget,
    reply: `Deployed ${labels[widgetType]} widget "${widget.title}".`
  };
}
