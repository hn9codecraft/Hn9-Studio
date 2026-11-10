import { storyWrite } from './storyService';

export function previewBulkApprove(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/bulk-approve/preview`, {
    method: 'POST',
    body: payload,
  });
}

export function approveBulkSelection(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/bulk-approve`, {
    method: 'POST',
    body: payload,
  });
}
