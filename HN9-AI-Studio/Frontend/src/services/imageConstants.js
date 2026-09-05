export const IMAGE_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'archived', label: 'Archived' },
];

export const IMAGE_STATUS_LABELS = {
  draft: 'Draft',
  pending: 'Pending',
  processing: 'Processing',
  completed: 'Completed',
  failed: 'Failed',
  archived: 'Archived',
};

export const IMAGE_ASPECT_RATIOS = [
  { value: '1:1', label: '1:1 Square' },
  { value: '16:9', label: '16:9 Landscape' },
  { value: '9:16', label: '9:16 Portrait' },
  { value: '4:3', label: '4:3 Standard' },
  { value: '3:4', label: '3:4 Tall' },
];

export function imageStatusLabel(status) {
  return IMAGE_STATUS_LABELS[status] || status || 'Unknown';
}

export function imageAspectRatioLabel(ratio) {
  return IMAGE_ASPECT_RATIOS.find((item) => item.value === ratio)?.label || ratio || 'Unknown';
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
