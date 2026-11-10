import { markStoryProjectChanged, storyWrite, storyGet } from './storyService';

export function lockCharacter(projectId, characterId, referenceId = null) {
  return storyWrite(`/story/projects/${projectId}/characters/${characterId}/lock`, {
    method: 'POST',
    body: referenceId ? { reference_uuid: referenceId } : {},
  }).finally(() => markStoryProjectChanged(projectId));
}

export function unlockCharacter(projectId, characterId) {
  return storyWrite(`/story/projects/${projectId}/characters/${characterId}/lock`, {
    method: 'DELETE',
  }).finally(() => markStoryProjectChanged(projectId));
}

export function listCharacterLocks(projectId) {
  return storyGet(`/story/projects/${projectId}/character-locks`).then((payload) => (Array.isArray(payload) ? payload : []));
}
