import { apiRequest } from './apiClient';
import { cachedRequest } from './requestCache';
import { markStoryProjectChanged } from './storyService';
import { selectProductionUnitVersion } from './storyService';

export function listSceneVersions(projectId, planId, sceneId) {
  return apiRequest(`/story/projects/${projectId}/production-plans/${planId}/scenes/${sceneId}/versions`);
}

export function restoreAssembly(projectId, planId, sceneId, assemblyId) {
  return apiRequest(
    `/story/projects/${projectId}/production-plans/${planId}/scenes/${sceneId}/assemblies/${assemblyId}/restore`,
    { method: 'POST', body: {} },
  ).finally(() => invalidateSceneMedia(projectId));
}

export function restoreSceneVersion(projectId, reelId, sceneId, versionId) {
  return apiRequest(
    `/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/versions/${versionId}/restore`,
    { method: 'POST', body: {} },
  ).finally(() => invalidateSceneMedia(projectId));
}

export function selectUnitVersion(projectId, planId, unitId, versionId) {
  return selectProductionUnitVersion(projectId, planId, unitId, versionId).finally(() => invalidateSceneMedia(projectId));
}

function invalidateSceneMedia(projectId) {
  markStoryProjectChanged(projectId);
}
