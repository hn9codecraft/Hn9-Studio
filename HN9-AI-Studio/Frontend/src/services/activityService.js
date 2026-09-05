import { apiRequest } from './apiClient';

export function listActivities(projectId, { perPage = 50, module = '', action = '', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('order', order);

  if (module) {
    params.set('module', module);
  }

  if (action) {
    params.set('action', action);
  }

  return apiRequest(`/projects/${projectId}/activities?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}
