import { ApiError, apiRequest, getApiBaseUrl } from './apiClient';
import { getToken } from './tokenStorage';

export function getFinalAssets(projectId) {
  return apiRequest(`/projects/${projectId}/final-assets`);
}

export function finalizeProject(projectId) {
  return apiRequest(`/projects/${projectId}/finalize`, { method: 'POST', body: {} });
}

export function createProjectExport(projectId) {
  return apiRequest(`/projects/${projectId}/export`, { method: 'POST', body: {} });
}

export function listProjectExports(projectId) {
  return apiRequest(`/projects/${projectId}/exports`);
}

export function getProjectExport(projectId, exportId) {
  return apiRequest(`/projects/${projectId}/exports/${exportId}`);
}

export async function downloadProjectExport(projectId, exportId, filename = 'project-export.zip') {
  const token = getToken();
  const response = await fetch(`${getApiBaseUrl()}/projects/${projectId}/exports/${exportId}/download`, {
    headers: {
      Accept: 'application/zip',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new ApiError(payload.message || 'Unable to download the export.', {
      status: response.status,
      errorCode: payload.error_code || 'export_download_failed',
      context: payload.context,
    });
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
