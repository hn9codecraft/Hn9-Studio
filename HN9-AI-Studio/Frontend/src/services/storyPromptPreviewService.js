import { storyWrite } from './storyService';

export function previewUnitPrompt(projectId, planId, unitId, payload) {
  return storyWrite(`/story/projects/${projectId}/production-plans/${planId}/units/${unitId}/prompt-preview`, {
    method: 'POST',
    body: payload,
  });
}

export function saveUnitPrompt(projectId, planId, unitId, prompt) {
  return storyWrite(`/story/projects/${projectId}/production-plans/${planId}/units/${unitId}/prompt`, {
    method: 'PUT',
    body: { prompt },
  });
}

export function resetUnitPrompt(projectId, planId, unitId) {
  return storyWrite(`/story/projects/${projectId}/production-plans/${planId}/units/${unitId}/prompt`, {
    method: 'DELETE',
  });
}

export function previewSceneRegenerate(projectId, reelId, sceneId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/regenerate/prompt-preview`, {
    method: 'POST',
    body: payload,
  });
}
