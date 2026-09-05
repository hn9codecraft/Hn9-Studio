export const VIDEO_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'archived', label: 'Archived' },
];

export const VIDEO_STATUS_LABELS = {
  draft: 'Draft',
  pending: 'Pending',
  processing: 'Processing',
  completed: 'Completed',
  failed: 'Failed',
  archived: 'Archived',
};

export const VIDEO_ASPECT_RATIOS = [
  { value: '16:9', label: '16:9 Landscape' },
  { value: '9:16', label: '9:16 Portrait' },
  { value: '1:1', label: '1:1 Square' },
];

export const VIDEO_DURATIONS = [
  { value: 5, label: '5 seconds' },
  { value: 10, label: '10 seconds' },
  { value: 15, label: '15 seconds' },
  { value: 30, label: '30 seconds' },
];

export function videoStatusLabel(status) {
  return VIDEO_STATUS_LABELS[status] || status || 'Unknown';
}

export function videoAspectRatioLabel(ratio) {
  return VIDEO_ASPECT_RATIOS.find((item) => item.value === ratio)?.label || ratio || 'Unknown';
}

export function videoDurationLabel(duration) {
  const seconds = Number(duration);
  return VIDEO_DURATIONS.find((item) => item.value === seconds)?.label || (seconds ? `${seconds} seconds` : 'Unknown');
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
