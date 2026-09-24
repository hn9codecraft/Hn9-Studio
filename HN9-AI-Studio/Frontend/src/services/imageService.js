import { ApiError, apiRequest } from './apiClient';
import { getToken } from './tokenStorage';

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

export function generateImage(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/images/generate`, { method: 'POST', body: payload }));
}

export function regenerateImage(projectId, imageId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}/regenerate`, { method: 'POST', body: payload }));
}

export function submitImageReview(projectId, imageId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}/submit-review`, { method: 'POST', body: payload }));
}

export function approveImage(projectId, imageId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}/approve`, { method: 'POST', body: payload }));
}

export function requestImageRework(projectId, imageId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/images/${imageId}/needs-rework`, { method: 'POST', body: payload }));
}

export function listImageReviewHistory(projectId, imageId) {
  return apiRequest(`/projects/${projectId}/images/${imageId}/review-history`).then((payload) => {
    if (Array.isArray(payload)) {
      return payload;
    }

    if (Array.isArray(payload?.data)) {
      return payload.data;
    }

    return [];
  });
}

export async function fetchImageObjectUrl(projectId, imageId) {
  const token = getToken();
  const base = (import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
  const response = await fetch(`${base}/projects/${projectId}/images/${imageId}/file`, {
    headers: {
      Accept: 'image/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new ApiError('Unable to load the generated image.', { status: response.status });
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}

async function unwrap(promise) {
  const payload = await promise;
  if (payload && typeof payload === 'object' && payload.image?.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.data?.id) {
    return payload.data;
  }

  return payload;
}
