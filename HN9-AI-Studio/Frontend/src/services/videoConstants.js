export const VIDEO_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'pending', label: 'Queued' },
  { value: 'archived', label: 'Archived' },
];

export const VIDEO_ASSIGNABLE_STATUSES = VIDEO_STATUSES.filter((status) =>
  ['draft', 'pending', 'archived'].includes(status.value),
);

export const VIDEO_STATUS_LABELS = {
  draft: 'Draft',
  pending: 'Queued',
  processing: 'Generating',
  completed: 'Completed',
  failed: 'Failed',
  archived: 'Archived',
  pending_review: 'Pending review',
  needs_rework: 'Needs rework',
  approved: 'Approved',
};

export const VIDEO_ASPECT_RATIOS = [
  { value: '16:9', label: '16:9 Landscape' },
  { value: '9:16', label: '9:16 Portrait' },
  { value: '1:1', label: '1:1 Square' },
];

export const VIDEO_GENERATION_ASPECT_RATIOS = [
  { value: '16:9', label: '16:9 Landscape' },
  { value: '9:16', label: '9:16 Portrait' },
];

export const VIDEO_DURATIONS = [
  { value: 5, label: '5 seconds' },
  { value: 10, label: '10 seconds' },
  { value: 15, label: '15 seconds' },
  { value: 30, label: '30 seconds' },
];

export const VIDEO_GENERATION_DURATIONS = [{ value: 8, label: '8 seconds' }];

export const VIDEO_RESOLUTIONS = [
  { value: '', label: 'Provider default' },
  { value: '720p', label: '720p' },
  { value: '1080p', label: '1080p' },
  { value: '4k', label: '4k' },
];

export function videoStatusLabel(status) {
  return VIDEO_STATUS_LABELS[status] || status || 'Unknown';
}

export function videoStatusClass(status) {
  const map = {
    draft: 'status-draft',
    pending: 'status-pending',
    processing: 'status-processing',
    completed: 'status-completed',
    failed: 'status-failed',
    archived: 'status-archived',
    pending_review: 'status-pending-review',
    approved: 'status-approved',
    needs_rework: 'status-needs-rework',
  };

  return map[status] || 'status-draft';
}

export function videoAspectRatioLabel(ratio) {
  return VIDEO_ASPECT_RATIOS.find((item) => item.value === ratio)?.label || ratio || 'Unknown';
}

export function videoDurationLabel(duration) {
  const seconds = Number(duration);
  return (
    [...VIDEO_DURATIONS, ...VIDEO_GENERATION_DURATIONS].find((item) => item.value === seconds)?.label ||
    (seconds ? `${seconds} seconds` : 'Unknown')
  );
}

export function videoCapabilities(video) {
  return {
    edit: Boolean(video?.capabilities?.edit),
    submit: Boolean(video?.capabilities?.submit),
    approve: Boolean(video?.capabilities?.approve),
    request_rework: Boolean(video?.capabilities?.request_rework),
    regenerate: Boolean(video?.capabilities?.regenerate),
  };
}

export function videoGenerationErrorMessage(error) {
  if (!error) {
    return 'Unable to generate a video.';
  }

  if (error.errorCode === 'ai_provider_not_configured' || error.context?.reason === 'not_configured') {
    return 'Video generation is unavailable because no video-capable provider is configured. Add Gemini Veo credentials and GEMINI_VIDEO_MODELS on the server. No video was created.';
  }

  if (error.errorCode === 'video_generation_unsupported') {
    return 'The selected provider or model does not support video generation.';
  }

  if (error.errorCode === 'video_generation_project_not_editable' || error.errorCode === 'generation_project_not_editable') {
    return 'This project cannot accept generation requests in its current status.';
  }

  if (error.errorCode === 'video_generation_in_progress') {
    return 'A video generation request for this prompt is already in progress.';
  }

  if (error.errorCode === 'video_storage_failed') {
    return 'The provider returned a video, but it could not be stored.';
  }

  if (error.errorCode === 'video_generation_timeout') {
    return 'The video provider did not finish before the timeout.';
  }

  if (
    error.errorCode === 'ai_all_providers_failed' ||
    error.errorCode === 'ai_provider_timeout' ||
    error.errorCode === 'video_generation_failed'
  ) {
    return error.message || 'The video provider failed.';
  }

  return error.message || 'Unable to generate a video.';
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}
