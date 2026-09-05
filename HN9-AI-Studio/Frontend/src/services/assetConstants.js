export const ASSET_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'ready', label: 'Ready' },
  { value: 'archived', label: 'Archived' },
];

export const ASSET_TYPES = [
  { value: 'image', label: 'Image' },
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
  { value: 'document', label: 'Document' },
  { value: 'other', label: 'Other' },
];

export const ASSET_SOURCES = [
  { value: 'manual', label: 'Manual' },
  { value: 'external', label: 'External URL' },
];

export const ASSET_SOURCE_LABELS = {
  manual: 'Manual',
  external: 'External URL',
  upload: 'Upload',
  generated: 'Generated',
};

export function assetStatusLabel(status) {
  return ASSET_STATUSES.find((item) => item.value === status)?.label || status || 'Unknown';
}

export function assetTypeLabel(type) {
  return ASSET_TYPES.find((item) => item.value === type)?.label || type || 'Unknown';
}

export function assetSourceLabel(source) {
  return ASSET_SOURCE_LABELS[source] || source || 'Unknown';
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
