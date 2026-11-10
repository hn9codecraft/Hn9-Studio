import { apiRequest } from './apiClient';

export function listProviders({ perPage = 50, status, category, search } = {}) {
  const params = new URLSearchParams();
  params.set('perPage', String(perPage));

  if (status) {
    params.set('status', status);
  }

  if (category) {
    params.set('category', category);
  }

  if (search) {
    params.set('search', search);
  }

  return apiRequest(`/providers?${params.toString()}`, { withMeta: true }).then((result) => ({
    data: Array.isArray(result.data) ? result.data : [],
    meta: result.meta,
  }));
}

export function getProvider(providerId) {
  return apiRequest(`/providers/${providerId}`);
}

export function updateProvider(providerId, payload) {
  return apiRequest(`/providers/${providerId}`, {
    method: 'PATCH',
    body: payload,
  });
}

export function enableProvider(providerId) {
  return apiRequest(`/providers/${providerId}/enable`, { method: 'POST' });
}

export function disableProvider(providerId) {
  return apiRequest(`/providers/${providerId}/disable`, { method: 'POST' });
}

export function listProviderCredentials() {
  return apiRequest('/provider-credentials');
}

export function saveProviderCredential(slug, payload) {
  return apiRequest(`/provider-credentials/${slug}`, { method: 'PUT', body: payload });
}

export function removeProviderCredential(slug) {
  return apiRequest(`/provider-credentials/${slug}/key`, { method: 'DELETE' });
}

export function testProviderCredential(slug) {
  return apiRequest(`/provider-credentials/${slug}/test`, { method: 'POST' });
}

export function updateProviderSetting(settingId, payload) {
  return apiRequest(`/provider-settings/${settingId}`, {
    method: 'PATCH',
    body: payload,
  });
}
