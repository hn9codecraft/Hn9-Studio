import { apiRequest } from './apiClient';

export function listNotifications() {
  return apiRequest('/dashboard/notifications');
}

export function markNotificationRead(id) {
  return apiRequest(`/notifications/${id}/read`, { method: 'POST', body: {} });
}

export function markAllNotificationsRead() {
  return apiRequest('/notifications/read-all', { method: 'POST', body: {} });
}
