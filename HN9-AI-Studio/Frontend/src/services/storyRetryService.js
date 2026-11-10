import { storyGet, storyWrite } from './storyService';

function unitPath(projectId, planId, unitId) {
  return `/story/projects/${projectId}/production-plans/${planId}/units/${unitId}`;
}

export function getUnitAttempts(projectId, planId, unitId) {
  return storyGet(`${unitPath(projectId, planId, unitId)}/attempts`);
}

export function previewUnitRetry(projectId, planId, unitId, payload) {
  return storyWrite(`${unitPath(projectId, planId, unitId)}/retry/prompt-preview`, {
    method: 'POST',
    body: payload,
  });
}

export function retryProductionUnit(projectId, planId, unitId, payload) {
  return storyWrite(`${unitPath(projectId, planId, unitId)}/retry`, {
    method: 'POST',
    body: payload,
  });
}
