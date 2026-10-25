/**
 * Fetch wrapper for the Silverstripe JSON APIs: session cookie auth, CSRF header,
 * uniform error objects.
 */
export class ApiError extends Error {
  constructor(status, code, message, violations = [], fields = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.violations = violations;
    this.fields = fields;
  }

  /** Soft rule violations: the user may confirm with force + reason. */
  get needsConfirmation() {
    return this.status === 409;
  }
}

export function createApi(config, baseUrl = config.apiBase) {
  const base = baseUrl.replace(/\/$/, '');

  async function request(method, path, { query, body } = {}) {
    const url = new URL(`${base}/${path.replace(/^\//, '')}`, window.location.origin);
    if (query) {
      Object.entries(query).forEach(([k, v]) => v !== undefined && v !== null && url.searchParams.set(k, v));
    }
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers[config.csrf.header] = config.csrf.value;
    }
    let response;
    try {
      response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
      });
    } catch {
      throw new ApiError(0, 'NETWORK', 'Keine Verbindung zum Server.');
    }
    let data = null;
    try {
      data = await response.json();
    } catch {
      data = null;
    }
    if (!response.ok) {
      const err = data?.error ?? {};
      if (response.status === 401) {
        window.location.reload();
      }
      throw new ApiError(response.status, err.code ?? 'HTTP_' + response.status, err.message ?? `Fehler ${response.status}`, err.violations ?? [], err.fields ?? {});
    }
    return data;
  }

  return {
    get: (path, query) => request('GET', path, { query }),
    post: (path, body = {}) => request('POST', path, { body }),
  };
}

export function readConfig(element) {
  try {
    return JSON.parse(element.dataset.config ?? '{}');
  } catch {
    return {};
  }
}
