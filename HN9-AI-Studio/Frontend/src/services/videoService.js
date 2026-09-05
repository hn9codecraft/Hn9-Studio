import { apiRequest } from './apiClient';

export function listVideos(projectId, { perPage = 50, status = '', sort = 'updated_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (status) {
    params.set('status', status);
  }

  return apiRequest(`/projects/${projectId}/videos?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getVideo(projectId, videoId) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}`));
}

export function createVideo(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/videos`, { method: 'POST', body: payload }));
}

export function updateVideo(projectId, videoId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}`, { method: 'PATCH', body: payload }));
}

export function deleteVideo(projectId, videoId) {
  return apiRequest(`/projects/${projectId}/videos/${videoId}`, { method: 'DELETE' });
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
