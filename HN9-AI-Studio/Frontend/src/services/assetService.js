import { apiRequest } from './apiClient';

export function listAssets(projectId, { perPage = 50, status = '', type = '', sort = 'updated_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (status) {
    params.set('status', status);
  }

  if (type) {
    params.set('type', type);
  }

  return apiRequest(`/projects/${projectId}/assets?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getAsset(projectId, assetId) {
  return unwrap(apiRequest(`/projects/${projectId}/assets/${assetId}`));
}

export function createAsset(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/assets`, { method: 'POST', body: payload }));
}

export function updateAsset(projectId, assetId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/assets/${assetId}`, { method: 'PATCH', body: payload }));
}

export function deleteAsset(projectId, assetId) {
  return apiRequest(`/projects/${projectId}/assets/${assetId}`, { method: 'DELETE' });
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
