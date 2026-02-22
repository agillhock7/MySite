import {
  MAX_DASHBOARD_WIDGETS,
  createPromptWidgetFromPrompt,
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

  if (text.includes('weather') || text.includes('forecast') || text.includes('temperature')) {
    return 'weather';
  }

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

function inferCity(input: string): string {
  const match = input.match(/\b(?:in|for)\s+([a-zA-Z][a-zA-Z\s.-]{1,40})$/);
  if (!match) {
    return '';
  }

  return match[1].trim();
}

function looksLikePromptWidgetRequest(input: string): boolean {
  return /\b(build|create|make|generate|add)\b/i.test(input) || /\bwidget\b/i.test(input);
}

function promptFromRequest(input: string): string {
  return input
    .replace(/^\s*(please\s+)?(build|create|make|generate|add)\s+/i, '')
    .replace(/\s+/g, ' ')
    .trim();
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
        ? `You currently have ${existingWidgetCount}/${MAX_DASHBOARD_WIDGETS} deployed widgets.`
        : 'No widgets deployed yet. Ask me to build one.'
    };
  }

  if (existingWidgetCount >= MAX_DASHBOARD_WIDGETS) {
    return {
      reply: `Widget limit reached (${MAX_DASHBOARD_WIDGETS}/${MAX_DASHBOARD_WIDGETS}). Remove one with /widget remove <id> or /widget clear.`
    };
  }

  const widgetType = inferWidgetType(trimmed);
  if (!widgetType) {
    if (looksLikePromptWidgetRequest(trimmed)) {
      const widgetPrompt = promptFromRequest(trimmed) || trimmed;
      const widget = createPromptWidgetFromPrompt(widgetPrompt, seedSource);
      return {
        widget,
        reply: `Deployed AI prompt widget "${widget.title}". Use /widget refresh ${widget.id} to regenerate.`
      };
    }

    return {
      reply:
        'Tell me what widget you want in plain language, or use /widget build <title> || <prompt>.'
    };
  }

  const titleHint = inferTitle(trimmed);
  const cityHint = inferCity(trimmed);

  if (widgetType === 'customHtml') {
    const widget = generateSimpleHtmlWidgetFromRequest(trimmed, seedSource);
    return {
      widget,
      reply: `Deployed HTML widget "${widget.title}". You can refine it with /widget html <title> || <html>.`
    };
  }

  const widget = createPresetWidget(widgetType, seedSource, titleHint);
  const labels: Record<DashboardWidgetType, string> = {
    weather: 'weather',
    horoscope: 'horoscope',
    fashion: 'fashion trend',
    sports: 'sports score',
    customHtml: 'custom html'
  };

  if (widgetType === 'weather' && cityHint) {
    widget.config.city = cityHint;
    widget.title = `Weather · ${cityHint}`;
  }

  return {
    widget,
    reply: `Deployed ${labels[widgetType]} widget "${widget.title}".`
  };
}
