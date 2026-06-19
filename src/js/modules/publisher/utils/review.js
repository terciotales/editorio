export function parseJsonArray(value) {
  if (Array.isArray(value)) {
    return value;
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return [];
  }

  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    return [];
  }
}

export function normalizeList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean);
  }

  if (typeof value === 'string') {
    return value.split(/[,;\n]+/).map((item) => item.trim()).filter(Boolean);
  }

  return [];
}

export function getCuratedStoryTitle(item) {
  return item.generated_title || item.title || 'Pauta sem título';
}

export function getCuratedStoryReason(item) {
  return item.curation_reason || item.reason || item.summary || item.description || '';
}

export function getCuratedStorySources(item) {
  const sources = parseJsonArray(item.curation_sources);
  if (sources.length > 0) {
    return sources;
  }

  if (Array.isArray(item.sources)) {
    return item.sources;
  }

  return [];
}

export function getCuratedStoryContent(item) {
  return item.generated_content || '';
}

export function getCuratedSourceLabel(source) {
  return source.source_name || source.title || `ID ${source.workflow_item_id || source.id}`;
}

export function getCuratedStoryMode(item) {
  if (item.curation_mode) {
    return item.curation_mode;
  }

  return item.curation_error ? 'automatic' : 'ai';
}

export function getCuratedStoryError(item) {
  return item.curation_error || '';
}

export function createEmptyReviewDraft(item) {
  return {
    title: getCuratedStoryTitle(item),
    summary: item.generated_summary || getCuratedStoryReason(item),
    categories_suggested: normalizeList(parseJsonArray(item.generated_categories).length > 0 ? parseJsonArray(item.generated_categories) : item.generated_categories),
    tags_suggested: normalizeList(parseJsonArray(item.generated_tags).length > 0 ? parseJsonArray(item.generated_tags) : item.generated_tags),
    content: getCuratedStoryContent(item),
    featured_image_id: Number(item.featured_image_id || 0),
    featured_image_url: item.featured_image_url || '',
  };
}

export function hasSavedReviewDraft(item) {
  return ['approved', 'rejected'].includes(String(item.approval_status || ''));
}

export function isReviewPending(item) {
  return !hasSavedReviewDraft(item);
}

export function createGeneratedFieldState(item) {
  if (!hasSavedReviewDraft(item)) {
    return {};
  }

  return {
    title: Boolean(item.generated_title),
    summary: Boolean(item.generated_summary),
    categories: normalizeList(parseJsonArray(item.generated_categories).length > 0 ? parseJsonArray(item.generated_categories) : item.generated_categories).length > 0,
    tags: normalizeList(parseJsonArray(item.generated_tags).length > 0 ? parseJsonArray(item.generated_tags) : item.generated_tags).length > 0,
    content: Boolean(item.generated_content),
  };
}
