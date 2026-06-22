/**
 * API client helper to interact with the WordPress REST API securely.
 */

const { root, namespace, nonce } = window.cgmAdminData || {
  root: '/wp-json/',
  namespace: 'cgm-financial-news/v1',
  nonce: ''
};

const baseUrl = `${root}${namespace}`;

const request = async (path, options = {}) => {
  const url = `${baseUrl}${path}`;
  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': nonce,
    ...(options.headers || {})
  };

  const response = await fetch(url, {
    ...options,
    headers
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data.message || 'An API error occurred.');
  }

  return data;
};

export const api = {
  get: (path, params = {}) => {
    let urlPath = path;
    const query = Object.keys(params)
      .filter(k => params[k] !== undefined && params[k] !== null)
      .map(k => `${encodeURIComponent(k)}=${encodeURIComponent(params[k])}`)
      .join('&');
    if (query) {
      urlPath += `?${query}`;
    }
    return request(urlPath, { method: 'GET' });
  },

  post: (path, data = {}) => {
    return request(path, {
      method: 'POST',
      body: JSON.stringify(data)
    });
  }
};
