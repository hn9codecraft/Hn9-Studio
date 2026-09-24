export const IMAGE_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Pending' },
  { value: 'pending_review', label: 'Pending review' },
  { value: 'needs_rework', label: 'Needs rework' },
  { value: 'approved', label: 'Approved' },
  { value: 'archived', label: 'Archived' },
];

export const IMAGE_ASSIGNABLE_STATUSES = IMAGE_STATUSES.filter((status) =>
  ['draft', 'pending', 'archived'].includes(status.value),
);

export const IMAGE_STATUS_LABELS = {
  draft: 'Draft',
  pending: 'Pending',
  processing: 'Processing',
  completed: 'Completed',
  failed: 'Failed',
  archived: 'Archived',
  pending_review: 'Pending review',
  needs_rework: 'Needs rework',
  approved: 'Approved',
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

export function imageStatusClass(status) {
  const map = {
    draft: 'status-draft',
    pending: 'status-pending',
    archived: 'status-archived',
    pending_review: 'status-pending-review',
    approved: 'status-approved',
    needs_rework: 'status-needs-rework',
    failed: 'status-failed',
    completed: 'status-completed',
  };

  return map[status] || 'status-draft';
}

export function imageCapabilities(image) {
  return {
    edit: Boolean(image?.capabilities?.edit),
    submit: Boolean(image?.capabilities?.submit),
    approve: Boolean(image?.capabilities?.approve),
    request_rework: Boolean(image?.capabilities?.request_rework),
    regenerate: Boolean(image?.capabilities?.regenerate),
  };
}

export function imageGenerationErrorMessage(error) {
  if (!error) {
    return 'Unable to generate an image.';
  }

  if (error.errorCode === 'ai_provider_not_configured' || error.context?.reason === 'not_configured') {
    return 'Image generation is unavailable because no image-capable provider is configured. Add image model credentials on the server. No image was created.';
  }

  if (error.errorCode === 'image_generation_unsupported') {
    return 'The selected provider or model does not support image generation.';
  }

  if (error.errorCode === 'generation_project_not_editable') {
    return 'This project cannot accept generation requests in its current status.';
  }

  if (error.errorCode === 'image_storage_failed') {
    return 'The provider returned an image, but it could not be stored. No studio image was created.';
  }

  if (error.errorCode === 'ai_all_providers_failed' || error.errorCode === 'ai_provider_timeout' || error.errorCode === 'image_generation_malformed_response') {
    return error.message || 'The image provider failed. No image was created.';
  }

  return error.message || 'Unable to generate an image.';
}

export function imageAspectRatioLabel(ratio) {
  return IMAGE_ASPECT_RATIOS.find((item) => item.value === ratio)?.label || ratio || 'Unknown';
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
