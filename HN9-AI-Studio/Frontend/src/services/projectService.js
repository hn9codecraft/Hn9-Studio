import { apiRequest } from './apiClient';
import { cachedRequest, invalidateCachedRequests } from './requestCache';
import { markStoryProjectChanged } from './storyService';

const PROJECT_LIST_TTL_MS = 60 * 1000;

export function listProjects({
  perPage = 50,
  search = '',
  status = '',
  sort = 'created_at',
  order = 'desc',
  cache = false,
  page = 1,
} = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));
  if (page > 1) {
    params.set('page', String(page));
  }
  params.set('sort', sort);
  params.set('order', order);

  if (search) {
    params.set('search', search);
  }

  if (status) {
    params.set('status', status);
  }

  const path = `/projects?${params.toString()}`;
  const load = () =>
    apiRequest(path, { withMeta: true }).then((result) => {
      const raw = result?.data;
      const data = Array.isArray(raw) ? raw : Array.isArray(raw?.data) ? raw.data : [];
      return { data, meta: result?.meta ?? null };
    });

  return cache ? cachedRequest(`projects:${path}`, load, { ttlMs: PROJECT_LIST_TTL_MS }) : load();
}

export function getProject(projectId) {
  return unwrap(apiRequest(`/projects/${projectId}`));
}

export function createProject(payload) {
  return unwrap(apiRequest('/projects', { method: 'POST', body: payload }).finally(() => invalidateProjectLists()));
}

export function updateProject(projectId, payload) {
  return unwrap(
    apiRequest(`/projects/${projectId}`, { method: 'PATCH', body: payload }).finally(() =>
      invalidateProjectLists(projectId),
    ),
  );
}

export function deleteProject(projectId) {
  return apiRequest(`/projects/${projectId}`, { method: 'DELETE' }).finally(() => invalidateProjectLists(projectId));
}

function invalidateProjectLists(projectId = null) {
  invalidateCachedRequests('projects:');
  if (projectId) {
    markStoryProjectChanged(projectId);
  }
}

async function unwrap(promise) {
  const payload = await promise;
  if (payload && typeof payload === 'object' && payload.id) {
    return payload;
  }

  if (payload && typeof payload === 'object' && payload.data?.id) {
    return payload.data;
  }

  return payload;
}
