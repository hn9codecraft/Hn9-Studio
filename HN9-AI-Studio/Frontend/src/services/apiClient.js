import { clearToken, getToken } from './tokenStorage';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(message, { status = 0, errorCode = null, errors = null, context = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errorCode = errorCode;
    this.errors = errors;
    this.context = context;
  }
}

let unauthorizedHandler = null;

export function onUnauthorized(handler) {
  unauthorizedHandler = handler;
}

export async function apiRequest(path, { method = 'GET', body, auth = true, headers = {}, withMeta = false } = {}) {
  const token = getToken();
  const requestHeaders = {
    Accept: 'application/json',
    ...headers,
  };

  if (body !== undefined) {
    requestHeaders['Content-Type'] = 'application/json';
  }

  if (auth && token) {
    requestHeaders.Authorization = `Bearer ${token}`;
  }

  let response;

  try {
    response = await fetch(`${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`, {
      method,
      headers: requestHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError('Unable to reach the HN9 API. Confirm the Laravel backend is running.', {
      status: 0,
      errorCode: 'network',
    });
  }

  if (response.status === 204) {
    return withMeta ? { data: null, meta: null } : null;
  }

  const payload = await response.json().catch(() => ({}));

  if (response.status === 401) {
    if (auth) {
      clearToken();
      unauthorizedHandler?.();
    }

    throw new ApiError(payload.message || 'Unauthenticated.', {
      status: 401,
      errorCode: payload.error_code || 'unauthenticated',
      errors: payload.errors,
      context: payload.context,
    });
  }

  if (!response.ok) {
    throw new ApiError(payload.message || 'Request failed.', {
      status: response.status,
      errorCode: payload.error_code || 'error',
      errors: payload.errors,
      context: payload.context,
    });
  }

  if (withMeta) {
    return {
      data: payload.data,
      meta: payload.meta ?? null,
    };
  }

  return payload.data;
}

export function getApiBaseUrl() {
  return API_BASE_URL;
}
