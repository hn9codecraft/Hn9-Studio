import { apiRequest } from './apiClient';

export function listImages(projectId, { perPage = 50, status = '', sort = 'updated_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (status) {
    params.set('status', status);
  }

  return apiRequest(`/projects/${projectId}/images?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getImage(projectId, imageId) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}`));
}

export function createImage(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/images`, { method: 'POST', body: payload }));
}

export function updateImage(projectId, imageId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}`, { method: 'PATCH', body: payload }));
}

export function deleteImage(projectId, imageId) {
  return apiRequest(`/projects/${projectId}/images/${imageId}`, { method: 'DELETE' });
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
