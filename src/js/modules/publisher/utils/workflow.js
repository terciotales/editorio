export function getSessionFromUrl() {
  if (typeof window === 'undefined') {
    return '';
  }

  return new URLSearchParams(window.location.search).get('session') || '';
}

export function writeSessionToUrl(sessionId) {
  if (typeof window === 'undefined' || !sessionId) {
    return;
  }

  const url = new URL(window.location.href);
  url.searchParams.set('session', sessionId);
  window.history.pushState({}, '', url.toString());
}

export function normalizeWorkflowStage(stage) {
  if (stage === 'confirmation') {
    return 'confirming';
  }

  return ['collecting', 'curating', 'reviewing', 'confirming', 'completed'].includes(stage)
    ? stage
    : 'idle';
}
