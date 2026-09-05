export const PROJECT_TYPES = [
  { value: '', label: 'Unspecified' },
  { value: 'reel', label: 'Reel' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'linkedin', label: 'LinkedIn' },
  { value: 'blog', label: 'Blog' },
  { value: 'campaign', label: 'Campaign' },
  { value: 'image', label: 'Image' },
  { value: 'other', label: 'Other' },
];

export const PROJECT_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Completed' },
  { value: 'archived', label: 'Archived' },
];

export const STATUS_TRANSITIONS = {
  draft: ['draft', 'active', 'archived'],
  active: ['active', 'completed', 'archived'],
  completed: ['completed', 'archived'],
  archived: ['archived', 'active'],
};

export const WORKSPACE_SECTIONS = [
  { key: 'overview', label: 'Overview', path: '' },
  { key: 'scripts', label: 'Scripts', path: 'scripts' },
  { key: 'images', label: 'Images', path: 'images' },
  { key: 'videos', label: 'Videos', path: 'videos' },
  { key: 'assets', label: 'Assets', path: 'assets' },
  { key: 'activity', label: 'Activity', path: 'activity' },
];

export function statusLabel(status) {
  return PROJECT_STATUSES.find((item) => item.value === status)?.label || status || 'Unknown';
}

export function typeLabel(type) {
  if (!type) {
    return 'Unspecified';
  }

  return PROJECT_TYPES.find((item) => item.value === type)?.label || type;
}

export function allowedStatuses(currentStatus, { creating = false } = {}) {
  if (creating) {
    return PROJECT_STATUSES.filter((item) => item.value === 'draft' || item.value === 'active');
  }

  const allowed = STATUS_TRANSITIONS[currentStatus] || [currentStatus];
  return PROJECT_STATUSES.filter((item) => allowed.includes(item.value));
}

export function formatProjectDate(value) {
  if (!value) {
    return '—';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
