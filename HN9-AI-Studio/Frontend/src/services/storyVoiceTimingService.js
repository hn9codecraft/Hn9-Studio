import { apiRequest } from './apiClient';
import { markStoryProjectChanged } from './storyService';

export function previewVoiceTiming(projectId, planId, query = {}) {
  const params = new URLSearchParams();
  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value));
  });
  const suffix = params.toString() ? `?${params.toString()}` : '';
  return apiRequest(`/story/projects/${projectId}/production-plans/${planId}/voice-timing${suffix}`);
}

export function applyVoiceTiming(projectId, planId, payload) {
  return apiRequest(`/story/projects/${projectId}/production-plans/${planId}/voice-timing/apply`, {
    method: 'POST',
    body: payload,
  }).finally(() => {
    markStoryProjectChanged(projectId);
  });
}
