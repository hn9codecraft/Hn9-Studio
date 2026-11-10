import { apiRequest, getApiBaseUrl } from './apiClient';
import { markStoryProjectChanged } from './storyService';
import { getToken } from './tokenStorage';

export function listSubtitles(projectId, reelId, renderId) {
  return apiRequest(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/subtitles`).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createSubtitles(projectId, reelId, renderId) {
  return apiRequest(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/subtitles`, { method: 'POST' });
}

export async function downloadSubtitles(projectId, reelId, renderId, trackId, format) {
  const token = getToken();
  const response = await fetch(
    `${getApiBaseUrl()}/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/subtitles/${trackId}/file?format=${format}`,
    {
      headers: {
        Accept: format === 'vtt' ? 'text/vtt' : 'application/x-subrip',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    },
  );
  if (!response.ok) {
    throw new Error('The subtitle file is not available.');
  }
  const blob = await response.blob();
  const header = response.headers.get('Content-Disposition') || '';
  const named = header.match(/filename="([^"]+)"/);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = named?.[1] || `subtitles.${format}`;
  link.click();
  URL.revokeObjectURL(url);
}

export function burnSubtitles(projectId, reelId, renderId, trackId) {
  return apiRequest(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/subtitles/${trackId}/burn`, {
    method: 'POST',
  }).finally(() => {
    markStoryProjectChanged(projectId);
  });
}
