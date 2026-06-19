import {extractReadableText} from '../utils/text';

export async function fetchSourceText(url) {
  if (!url) {
    return '';
  }

  try {
    const response = await fetch(url, {
      credentials: 'omit',
      cache: 'no-store',
    });

    if (!response.ok) {
      return '';
    }

    return extractReadableText(await response.text());
  } catch (error) {
    return '';
  }
}

export async function buildReviewSourceContent(sources) {
  const sourceList = sources.slice(0, 4);
  const contents = await Promise.all(
    sourceList.map(async (source) => {
      const text = await fetchSourceText(source.content_url || '');
      const fallback = [source.source_name, source.title].filter(Boolean).join(' - ');
      return [fallback, text].filter(Boolean).join('\n\n').slice(0, 8000);
    })
  );

  return contents.filter(Boolean).join('\n\n---\n\n');
}
