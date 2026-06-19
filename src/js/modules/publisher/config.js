import apiFetch from '@wordpress/api-fetch';

export const config = window.editorioPublisher || {
  restNamespace: '/editorio/v1',
  nonce: '',
  messages: {},
};

export function setupPublisherApiFetch() {
  if (!config.nonce) {
    return;
  }

  apiFetch.use((options, next) => {
    const headers = {
      ...(options.headers || {}),
      'X-WP-Nonce': config.nonce,
    };

    return next({ ...options, headers });
  });
}

export function message(key, fallback) {
  return config.messages && config.messages[key]
    ? config.messages[key]
    : fallback;
}
