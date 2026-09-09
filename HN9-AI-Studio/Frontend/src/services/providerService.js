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

export function updateProviderSetting(settingId, payload) {
  return apiRequest(`/provider-settings/${settingId}`, {
    method: 'PATCH',
    body: payload,
  });
}
