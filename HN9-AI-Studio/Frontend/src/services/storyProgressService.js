import { storyGet } from './storyService';

export function getStoryProgress(projectId) {
  return storyGet(`/story/projects/${projectId}/progress`);
}
