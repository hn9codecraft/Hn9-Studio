import { fieldError as projectFieldError } from './projectConstants';

export const SECRET_PLACEHOLDER = '********';

export function fieldError(error, field) {
  return projectFieldError(error, field);
}

export function statusLabel(status) {
  const labels = {
    active: 'Active',
    inactive: 'Inactive',
    disabled: 'Disabled',
    archived: 'Archived',
  };

  return labels[status] || status || 'Unknown';
}

export function isProviderEnabled(status) {
  return status === 'active';
}

export function formatCapabilities(capabilities) {
  if (!Array.isArray(capabilities) || capabilities.length === 0) {
    return 'None recorded';
  }

  return capabilities
    .map((item) => (typeof item === 'string' ? item : JSON.stringify(item)))
    .join(', ');
}
