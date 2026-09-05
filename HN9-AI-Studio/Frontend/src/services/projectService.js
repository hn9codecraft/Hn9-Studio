import { apiRequest } from './apiClient';

export function listProjects({ perPage = 50, search = '', status = '', sort = 'created_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (search) {
    params.set('search', search);
  }

  if (status) {
    params.set('status', status);
  }

  return apiRequest(`/projects?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getProject(projectId) {
  return unwrap(apiRequest(`/projects/${projectId}`));
}

export function createProject(payload) {
  return unwrap(apiRequest('/projects', { method: 'POST', body: payload }));
}

export function updateProject(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}`, { method: 'PATCH', body: payload }));
}

export function deleteProject(projectId) {
  return apiRequest(`/projects/${projectId}`, { method: 'DELETE' });
}

async function unwrap(promise) {
  const payload = await promise;
  if (payload && typeof payload === 'object' && payload.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.data?.id) {
    return payload.data;
  }

  return payload;
}
