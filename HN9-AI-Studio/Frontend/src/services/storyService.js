import { apiRequest, getApiBaseUrl } from './apiClient';
import { cachedRequest, invalidateCachedRequests } from './requestCache';
import { getToken } from './tokenStorage';

const CATALOG_TTL_MS = 5 * 60 * 1000;
const CONTEXT_TTL_MS = 30 * 1000;
const projectRevisions = new Map();

// Every GET shares in-flight requests; only reads passed a TTL are reused after
// they settle. Polled job/render/export status reads must stay uncached.
function storyGet(path, ttlMs = 0) {
  return cachedRequest(`story:${path}`, () => apiRequest(path), { ttlMs });
}

function storyWrite(path, options) {
  return apiRequest(path, options).finally(() => {
    const match = path.match(/^\/story\/projects\/([^/?]+)/);
    if (match) {
      markStoryProjectChanged(match[1]);
    }
  });
}

export function markStoryProjectChanged(projectId) {
  invalidateCachedRequests(`story:/story/projects/${projectId}`);
  projectRevisions.set(projectId, (projectRevisions.get(projectId) || 0) + 1);
}

/** Increments whenever this client writes to the project's story data. */
export function getStoryProjectRevision(projectId) {
  return projectRevisions.get(projectId) || 0;
}

export function getStoryEntry() {
  return storyGet('/story', CATALOG_TTL_MS);
}

export function listStoryProjects({ perPage = 50, search = '', status = '', sort = 'created_at', order = 'desc' } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  params.set('sort', sort);
  params.set('order', order);

  if (search) {
    params.set('search', search);
  }

  if (status) {
    params.set('status', status);
  }

  return apiRequest(`/story/projects?${params.toString()}`, { withMeta: true }).then((result) => {
    const raw = result?.data;
    const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
    return { data, meta: result?.meta ?? null };
  });
}

export function getStoryWorkspace(projectId) {
  return storyGet(`/story/projects/${projectId}`, CONTEXT_TTL_MS);
}

export function getStoryBible(projectId) {
  return storyGet(`/story/projects/${projectId}/bible`, CONTEXT_TTL_MS);
}

export function initializeStoryBible(projectId) {
  return storyWrite(`/story/projects/${projectId}/bible`, { method: 'POST' });
}

export function updateStoryBible(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/bible`, { method: 'PATCH', body: payload });
}

export function listStoryCharacters(projectId) {
  return storyGet(`/story/projects/${projectId}/characters`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createStoryCharacter(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/characters`, { method: 'POST', body: payload });
}

export function getStoryCharacter(projectId, characterId) {
  return storyGet(`/story/projects/${projectId}/characters/${characterId}`);
}

export function updateStoryCharacter(projectId, characterId, payload) {
  return storyWrite(`/story/projects/${projectId}/characters/${characterId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function archiveStoryCharacter(projectId, characterId) {
  return storyWrite(`/story/projects/${projectId}/characters/${characterId}`, { method: 'DELETE' });
}

export function listStoryCharacterReferences(projectId, characterId) {
  return storyGet(`/story/projects/${projectId}/characters/${characterId}/references`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function uploadStoryCharacterReference(projectId, characterId, file, role = 'primary') {
  const body = new FormData();
  body.append('file', file);
  if (role) {
    body.append('role', role);
  }

  return storyWrite(`/story/projects/${projectId}/characters/${characterId}/references/upload`, {
    method: 'POST',
    body,
  });
}

export function generateStoryCharacterReference(projectId, characterId, payload = {}) {
  return storyWrite(`/story/projects/${projectId}/characters/${characterId}/references/generate`, {
    method: 'POST',
    body: payload,
  });
}

export function submitStoryCharacterReferenceReview(projectId, characterId, referenceId, comment = null) {
  return storyWrite(
    `/story/projects/${projectId}/characters/${characterId}/references/${referenceId}/submit-review`,
    { method: 'POST', body: { comment } },
  );
}

export function approveStoryCharacterReference(projectId, characterId, referenceId, comment = null) {
  return storyWrite(
    `/story/projects/${projectId}/characters/${characterId}/references/${referenceId}/approve`,
    { method: 'POST', body: { comment } },
  );
}

export function rejectStoryCharacterReference(projectId, characterId, referenceId, comment) {
  return storyWrite(
    `/story/projects/${projectId}/characters/${characterId}/references/${referenceId}/reject`,
    { method: 'POST', body: { comment } },
  );
}

export function archiveStoryCharacterReference(projectId, characterId, referenceId) {
  return storyWrite(
    `/story/projects/${projectId}/characters/${characterId}/references/${referenceId}/archive`,
    { method: 'POST' },
  );
}

// Stored files never change for a given id, so their object URLs are kept for
// the session; callers must not revoke them.
function storyFileUrl(path, accept, errorMessage) {
  return cachedRequest(
    `storyfile:${path}`,
    async () => {
      const token = getToken();
      const response = await fetch(`${getApiBaseUrl()}${path}`, {
        headers: {
          Accept: accept,
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
      });

      if (!response.ok) {
        throw new Error(errorMessage);
      }

      return URL.createObjectURL(await response.blob());
    },
    { ttlMs: Number.POSITIVE_INFINITY },
  );
}

export function getStoryCharacterReferenceFileUrl(projectId, characterId, referenceId) {
  return storyFileUrl(
    `/story/projects/${projectId}/characters/${characterId}/references/${referenceId}/file`,
    'image/*',
    'This picture could not be loaded.',
  );
}

export function getStoryStyle(projectId) {
  return storyGet(`/story/projects/${projectId}/style`, CONTEXT_TTL_MS);
}

export function initializeStoryStyle(projectId) {
  return storyWrite(`/story/projects/${projectId}/style`, { method: 'POST' });
}

export function updateStoryStyle(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/style`, { method: 'PATCH', body: payload });
}

export function listStoryStyleReferences(projectId) {
  return storyGet(`/story/projects/${projectId}/style/references`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function uploadStoryStyleReference(projectId, file, role = 'primary') {
  const body = new FormData();
  body.append('file', file);
  if (role) body.append('role', role);

  return storyWrite(`/story/projects/${projectId}/style/references/upload`, {
    method: 'POST',
    body,
  });
}

export function generateStoryStyleReference(projectId, payload = {}) {
  return storyWrite(`/story/projects/${projectId}/style/references/generate`, {
    method: 'POST',
    body: payload,
  });
}

export function submitStoryStyleReferenceReview(projectId, referenceId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/style/references/${referenceId}/submit-review`, {
    method: 'POST',
    body: { comment },
  });
}

export function approveStoryStyleReference(projectId, referenceId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/style/references/${referenceId}/approve`, {
    method: 'POST',
    body: { comment },
  });
}

export function rejectStoryStyleReference(projectId, referenceId, comment) {
  return storyWrite(`/story/projects/${projectId}/style/references/${referenceId}/reject`, {
    method: 'POST',
    body: { comment },
  });
}

export function archiveStoryStyleReference(projectId, referenceId) {
  return storyWrite(`/story/projects/${projectId}/style/references/${referenceId}/archive`, {
    method: 'POST',
  });
}

export function getStoryStyleReferenceFileUrl(projectId, referenceId) {
  return storyFileUrl(
    `/story/projects/${projectId}/style/references/${referenceId}/file`,
    'image/*',
    'This picture could not be loaded.',
  );
}

export function listStoryPlans(projectId) {
  return storyGet(`/story/projects/${projectId}/plans`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createStoryPlan(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/plans`, { method: 'POST', body: payload });
}

export function getStoryPlan(projectId, planId) {
  return storyGet(`/story/projects/${projectId}/plans/${planId}`);
}

export function generateStoryPlan(projectId, planId, payload = {}) {
  return storyWrite(`/story/projects/${projectId}/plans/${planId}/generate`, {
    method: 'POST',
    body: payload,
  });
}

export function regenerateStoryPlan(projectId, planId, payload = {}) {
  return storyWrite(`/story/projects/${projectId}/plans/${planId}/regenerate`, {
    method: 'POST',
    body: payload,
  });
}

export function listStoryPlanVersions(projectId, planId) {
  return storyGet(`/story/projects/${projectId}/plans/${planId}/versions`).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function materializeStoryPlanVersion(projectId, planId, versionId) {
  return storyWrite(`/story/projects/${projectId}/plans/${planId}/versions/${versionId}/materialize`, {
    method: 'POST',
  });
}

export function listStoryReels(projectId) {
  return storyGet(`/story/projects/${projectId}/reels`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createStoryReel(projectId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels`, { method: 'POST', body: payload });
}

export function getStoryReel(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}`);
}

export function updateStoryReel(projectId, reelId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function archiveStoryReel(projectId, reelId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/archive`, { method: 'POST' });
}

export function reorderStoryReels(projectId, orderedIds) {
  return storyWrite(`/story/projects/${projectId}/reels/reorder`, {
    method: 'POST',
    body: { ordered_ids: orderedIds },
  }).then((payload) => (Array.isArray(payload) ? payload : []));
}

export function listStoryScenes(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes`).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createStoryScene(projectId, reelId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes`, {
    method: 'POST',
    body: payload,
  });
}

export function getStoryScene(projectId, reelId, sceneId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}`);
}

export function updateStoryScene(projectId, reelId, sceneId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function duplicateStoryScene(projectId, reelId, sceneId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/duplicate`, {
    method: 'POST',
  });
}

export function archiveStoryScene(projectId, reelId, sceneId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/archive`, {
    method: 'POST',
  });
}

export function reorderStoryScenes(projectId, reelId, orderedIds) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/reorder`, {
    method: 'POST',
    body: { ordered_ids: orderedIds },
  }).then((payload) => (Array.isArray(payload) ? payload : []));
}

export function listStorySceneVersions(projectId, reelId, sceneId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/versions`).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function commentOnStoryScene(projectId, reelId, sceneId, body) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/comments`, {
    method: 'POST',
    body: { body },
  });
}

export function submitStorySceneReview(projectId, reelId, sceneId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/submit-review`, {
    method: 'POST',
    body: { comment },
  });
}

export function approveStoryScene(projectId, reelId, sceneId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/approve`, {
    method: 'POST',
    body: { comment },
  });
}

export function reworkStoryScene(projectId, reelId, sceneId, comment) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/needs-rework`, {
    method: 'POST',
    body: { comment },
  });
}

/** Creates a new scene version and requests its video ({ mode, prompt, reference_id, comment }). */
export function regenerateStoryScene(projectId, reelId, sceneId, { comment = null, mode = 'text', prompt = null, referenceId = null } = {}) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/regenerate`, {
    method: 'POST',
    body: { comment, mode, prompt, reference_id: referenceId },
  });
}

/** Latest version, video and sound status for every scene in a reel (one request). */
export function getStoryReelSceneStatus(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scene-status`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function listStoryReelAudio(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/audio`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function listStoryRenders(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/renders`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function editStorySceneVersion(projectId, reelId, sceneId, versionId, instruction) {
  return storyWrite(
    `/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/versions/${versionId}/edit`,
    {
      method: 'POST',
      body: { instruction },
    },
  );
}

export function extendStorySceneVersion(projectId, reelId, sceneId, versionId, instruction) {
  return storyWrite(
    `/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/versions/${versionId}/extend`,
    {
      method: 'POST',
      body: { instruction },
    },
  );
}

export function getStoryScenePreview(projectId, reelId, sceneId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/preview`);
}

export function submitStoryReelReview(projectId, reelId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/submit-review`, {
    method: 'POST',
    body: { comment },
  });
}

export function approveStoryReel(projectId, reelId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/approve`, {
    method: 'POST',
    body: { comment },
  });
}

export function getStorySceneContinuity(projectId, reelId, sceneId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/continuity`);
}

export function estimateStorySceneCount(durationSeconds) {
  const total = Number(durationSeconds) || 0;
  if (total < 30) return 0;
  return Math.ceil(total / 30);
}

export function getStoryTimeline(projectId, reelId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/timeline`, CONTEXT_TTL_MS);
}

export function placeStoryTimelineClip(projectId, reelId, mediaKind, sourceId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips`, {
    method: 'POST',
    body: { media_kind: mediaKind, source_id: sourceId },
  });
}

export function reorderStoryTimeline(projectId, reelId, orderedIds) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/reorder`, {
    method: 'POST',
    body: { ordered_ids: orderedIds },
  });
}

export function trimStoryTimelineClip(projectId, reelId, clipId, inMs, outMs) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips/${clipId}/trim`, {
    method: 'POST',
    body: { in_ms: inMs, out_ms: outMs },
  });
}

export function splitStoryTimelineClip(projectId, reelId, clipId, atMs) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips/${clipId}/split`, {
    method: 'POST',
    body: { at_ms: atMs },
  });
}

export function replaceStoryTimelineClip(projectId, reelId, clipId, sourceId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips/${clipId}/replace`, {
    method: 'POST',
    body: { source_id: sourceId },
  });
}

export function duplicateStoryTimelineClip(projectId, reelId, clipId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips/${clipId}/duplicate`, {
    method: 'POST',
  });
}

export function deleteStoryTimelineClip(projectId, reelId, clipId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/clips/${clipId}`, {
    method: 'DELETE',
  });
}

export function startStoryRender(projectId, reelId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/renders`, { method: 'POST' });
}

export function getStoryRender(projectId, reelId, renderId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}`);
}

export function getStoryRenderFileUrl(projectId, reelId, renderId) {
  return storyFileUrl(
    `/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/file`,
    'video/mp4',
    'The final video file is not available yet.',
  );
}

export function submitStoryRenderReview(projectId, reelId, renderId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/submit-review`, {
    method: 'POST',
    body: { comment },
  });
}

export function approveStoryRenderReview(projectId, reelId, renderId, comment = null) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/approve`, {
    method: 'POST',
    body: { comment },
  });
}

export function reworkStoryRender(projectId, reelId, renderId, comment, targetKind, targetId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/needs-rework`, {
    method: 'POST',
    body: { comment, target_kind: targetKind, target_id: targetId },
  });
}

export function getStoryHistory(projectId) {
  return storyGet(`/story/projects/${projectId}/history`, CONTEXT_TTL_MS).then((payload) =>
    Array.isArray(payload) ? payload : [],
  );
}

export function createStoryExport(projectId, reelId, renderId) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/renders/${renderId}/exports`, { method: 'POST' });
}

export function getStoryExport(projectId, reelId, exportId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/exports/${exportId}`);
}

export async function downloadStoryExport(projectId, reelId, exportId, filename = 'story-export.zip') {
  const token = getToken();
  const base = getApiBaseUrl();
  const response = await fetch(
    `${base}/story/projects/${projectId}/reels/${reelId}/exports/${exportId}/download`,
    {
      headers: {
        Accept: 'application/zip',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    },
  );

  if (!response.ok) {
    throw new Error('The story export is not available.');
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export function setStoryTimelineTransition(projectId, reelId, fromClipId, toClipId, type, durationMs = 0) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/timeline/transitions`, {
    method: 'POST',
    body: {
      from_clip_id: fromClipId,
      to_clip_id: toClipId,
      type,
      duration_ms: durationMs,
    },
  });
}

export function getStoryVideoJob(projectId, jobId) {
  return storyGet(`/story/projects/${projectId}/video/jobs/${jobId}`);
}

export function getStoryVideoFileUrl(projectId, jobId) {
  return storyFileUrl(
    `/story/projects/${projectId}/video/jobs/${jobId}/file`,
    'video/mp4',
    'This video could not be loaded.',
  );
}

export function getStorySceneVersionFileUrl(projectId, reelId, sceneId, versionId) {
  return storyFileUrl(
    `/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/versions/${versionId}/file`,
    'video/mp4',
    'This version could not be loaded.',
  );
}

export function getStoryAudioRoles() {
  return storyGet('/story/audio/roles', CATALOG_TTL_MS);
}

export function listStorySceneAudio(projectId, reelId, sceneId, role = null) {
  const query = role ? `?role=${encodeURIComponent(role)}` : '';
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/audio${query}`);
}

export function createStorySceneAudio(projectId, reelId, sceneId, payload) {
  return storyWrite(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/audio`, {
    method: 'POST',
    body: payload,
  });
}

export function getStorySceneAudio(projectId, reelId, sceneId, audioId) {
  return storyGet(`/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/audio/${audioId}`);
}

export function getStorySceneAudioFileUrl(projectId, reelId, sceneId, audioId) {
  return storyFileUrl(
    `/story/projects/${projectId}/reels/${reelId}/scenes/${sceneId}/audio/${audioId}/file`,
    'audio/*',
    'This sound could not be loaded.',
  );
}
