import { apiRequest } from './apiClient';

export function listScripts(projectId, { perPage = 50, status = '', sort = 'updated_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (status) {
    params.set('status', status);
  }

  return apiRequest(`/projects/${projectId}/scripts?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getScript(projectId, scriptId) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}`));
}

export function createScript(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts`, { method: 'POST', body: payload }));
}

export function updateScript(projectId, scriptId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}`, { method: 'PATCH', body: payload }));
}

export function deleteScript(projectId, scriptId) {
  return apiRequest(`/projects/${projectId}/scripts/${scriptId}`, { method: 'DELETE' });
}

export function generateScript(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/generate`, { method: 'POST', body: payload }));
}

export function regenerateScript(projectId, scriptId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}/regenerate`, { method: 'POST', body: payload }));
}

export function submitScriptReview(projectId, scriptId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}/submit-review`, { method: 'POST', body: payload }));
}

export function approveScript(projectId, scriptId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}/approve`, { method: 'POST', body: payload }));
}

export function requestScriptRework(projectId, scriptId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/scripts/${scriptId}/needs-rework`, { method: 'POST', body: payload }));
}

export function listScriptReviewHistory(projectId, scriptId) {
  return apiRequest(`/projects/${projectId}/scripts/${scriptId}/review-history`).then((payload) => {
    if (Array.isArray(payload)) {
      return payload;
    }

    if (Array.isArray(payload?.data)) {
      return payload.data;
    }

    return [];
  });
}

async function unwrap(promise) {
  const payload = await promise;
  if (payload && typeof payload === 'object' && payload.script?.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.data?.script?.id) {
    return payload.data;
  }

  if (payload && typeof payload === 'object' && payload.data?.id) {
    return payload.data;
  }

  return payload;
}
