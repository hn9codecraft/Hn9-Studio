export const SCRIPT_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'ready', label: 'Ready' },
  { value: 'archived', label: 'Archived' },
];

export function scriptStatusLabel(status) {
  return SCRIPT_STATUSES.find((item) => item.value === status)?.label || status || 'Unknown';
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
