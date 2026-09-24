import { ApiError, apiRequest } from './apiClient';
import { getToken } from './tokenStorage';

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

export function getVideoStatus(projectId, videoId) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}/status`));
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

export function generateVideo(projectId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/generate`, { method: 'POST', body: payload }));
}

export function regenerateVideo(projectId, videoId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}/regenerate`, { method: 'POST', body: payload }));
}

export function submitVideoReview(projectId, videoId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}/submit-review`, { method: 'POST', body: payload }));
}

export function approveVideo(projectId, videoId, payload = {}) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}/approve`, { method: 'POST', body: payload }));
}

export function requestVideoRework(projectId, videoId, payload) {
  return unwrap(apiRequest(`/projects/${projectId}/videos/${videoId}/needs-rework`, { method: 'POST', body: payload }));
}

export function listVideoReviewHistory(projectId, videoId) {
  return apiRequest(`/projects/${projectId}/videos/${videoId}/review-history`).then((payload) => {
    if (Array.isArray(payload)) {
      return payload;
    }

    if (Array.isArray(payload?.data)) {
      return payload.data;
    }

    return [];
  });
}

export async function fetchVideoObjectUrl(projectId, videoId) {
  const token = getToken();
  const base = (import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
  const response = await fetch(`${base}/projects/${projectId}/videos/${videoId}/file`, {
    headers: {
      Accept: 'video/*',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    throw new ApiError('Unable to load the generated video.', { status: response.status });
  }

  const blob = await response.blob();
  return URL.createObjectURL(blob);
}

async function unwrap(promise) {
  const payload = await promise;
  if (payload && typeof payload === 'object' && payload.video?.id) {
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
