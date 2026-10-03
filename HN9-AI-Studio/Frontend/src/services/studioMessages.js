// Plain-language copy for the Creative Studio. Nothing in here should ever show
// a status code, an internal id or a raw server message to the person using it.

export const NOT_CONNECTED = {
  video: 'Video creation is not connected yet. Connect a video provider in Settings to create this video.',
  sound: 'Sound creation is not connected yet. Connect an audio provider in Settings to create sound for scenes.',
  plan: 'Automatic scene planning is not connected yet. Connect a text AI provider in Settings, or add your scenes yourself.',
  images: 'Picture creation is not connected yet. Upload a picture instead, or connect an image provider in Settings.',
};

const REVIEW_STATUS = {
  draft: 'Draft',
  pending_review: 'Waiting for review',
  approved: 'Approved',
  needs_rework: 'Changes requested',
  rejected: 'Changes requested',
  archived: 'Removed',
};

const JOB_STATUS = {
  queued: 'Waiting to start',
  submitted: 'Sent for creation',
  processing: 'Being created',
  completed: 'Ready',
  failed: 'Failed',
  cancelled: 'Cancelled',
  not_connected: 'Not connected',
};

const ACTION_WORDS = {
  submitted: 'sent for review',
  approved: 'approved',
  rejected: 'sent back',
  needs_rework: 'sent back for changes',
  archived: 'removed',
};

const PROVIDER_REASONS = {
  provider_not_connected: 'No provider was connected, so nothing was sent.',
  GENERATION_NOT_ENABLED: 'No provider was connected, so nothing was sent.',
  QUOTA_EXCEEDED: 'The provider account has run out of credit.',
  RATE_LIMITED: 'The provider was busy. Try again in a minute.',
  TIMEOUT: 'The provider took too long to answer.',
  AUTHENTICATION_FAILED: 'The provider rejected the connection details. An admin needs to check Settings.',
  DOWNLOAD_FAILED: 'The result could not be downloaded from the provider.',
  INVALID_PROVIDER_RESPONSE: 'The provider sent back something unusable.',
  SUBMISSION_UNCONFIRMED: 'The provider did not confirm the request.',
  story_render_empty: 'The timeline had no clips.',
  story_render_source_missing: 'A clip’s video file was missing.',
};

export function reviewStatusLabel(status) {
  return REVIEW_STATUS[status] || 'Draft';
}

export function jobStatusLabel(status) {
  return JOB_STATUS[status] || 'Not started';
}

export function isJobActive(status) {
  return status === 'queued' || status === 'submitted' || status === 'processing';
}

/** Short reason for a failed attempt, suitable for History and status rows. */
export function failureReason(errorCode, status = 'failed') {
  if (status === 'not_connected') return PROVIDER_REASONS.provider_not_connected;
  return PROVIDER_REASONS[errorCode] || 'The provider could not finish this. You can try again.';
}

function transitionMessage(raw) {
  const match = /cannot be (\w+) from status '(\w+)'/.exec(raw || '');
  if (!match) return 'That step is not available right now. Refresh to see the latest status.';

  const [, action, from] = match;
  if (from === 'missing' || from === 'draft') {
    return action === 'submitted'
      ? 'There is nothing to send for review yet. Create the video first.'
      : 'This is still a draft. Send it for review first, then approve it or ask for changes.';
  }
  if (from === 'approved') return 'This is already approved.';
  if (from === 'pending_review' && action === 'submitted') return 'This is already waiting for review.';

  return `This cannot be ${ACTION_WORDS[action] || 'changed'} while it is “${reviewStatusLabel(from).toLowerCase()}”.`;
}

const INPUT_MESSAGES = [
  [/stored scene video is required/i, 'This scene has no finished video yet. Create its video first.'],
  [/no stored (final )?render/i, 'Build the final video first.'],
  [/duration/i, 'That length is not supported. Pick a different duration.'],
];

function looksTechnical(text) {
  return (
    !text ||
    /[a-z]+_[a-z_]+/.test(text) ||
    /SQLSTATE|Exception|Laravel|Stack trace|\bstatus '|uuid|\bid\b|sprint|foundation release|capabilit|router/i.test(text)
  );
}

/**
 * Turns any thrown error into one sentence a non-technical person can act on.
 * `area` picks the right "not connected" wording: video, sound, plan or images.
 */
export function friendlyError(error, fallback = 'Something went wrong. Please try again.', area = null) {
  if (!error) return fallback;

  const code = error.errorCode || '';
  const status = Number(error.status || 0);
  const raw = typeof error.message === 'string' ? error.message : '';

  if (code === 'network' || (status === 0 && /fetch|network/i.test(raw))) {
    return 'The studio cannot reach the server. Check your connection, then try again.';
  }

  if (
    code === 'GENERATION_NOT_ENABLED' ||
    code === 'story_generation_not_available' ||
    code === 'VIDEO_CAPABILITY_NOT_AVAILABLE' ||
    code.startsWith('ai_no_') ||
    code === 'ai_provider_not_configured' ||
    /No AI provider is (available|configured)/i.test(raw)
  ) {
    return NOT_CONNECTED[area] || 'This needs a provider that is not connected yet. An admin can connect one in Settings.';
  }

  if (code.endsWith('_generation_failed')) {
    return 'The provider could not finish this. Try again in a moment.';
  }

  if (code.endsWith('_invalid_transition')) return transitionMessage(raw);
  if (code === 'story_render_empty') {
    return 'There are no clips to build yet. Approve scene videos first; they appear on the timeline automatically.';
  }
  if (code === 'story_render_source_missing') return 'A clip’s video file is missing. Swap or remove that clip, then build again.';
  if (code === 'story_already_materialized') return 'Scenes were already created from this plan.';
  if (code === 'story_plan_not_generatable') return 'This plan is already being written or is finished.';
  if (code.endsWith('_invalid_image')) return 'That file is not a picture we can read. Try a JPG, PNG or WebP image.';
  if (code.endsWith('_archived')) return 'This was removed, so it can no longer be changed.';
  if (code.endsWith('_not_ready')) return 'The file is not ready yet. Try again in a moment.';

  if (status === 401) return 'Your session has ended. Sign in again to continue.';
  if (status === 403) return 'You do not have permission to do this in this project.';
  if (status === 404) return 'This item no longer exists. Refresh to see the latest version.';
  if (status === 413) return 'That file is too large. Try a smaller one.';
  if (status === 429) return 'Too many requests in a short time. Wait a moment, then try again.';

  if (code === 'INVALID_INPUT' || status === 422) {
    const fieldMessage = error.errors ? Object.values(error.errors).flat().find(Boolean) : '';
    const text = fieldMessage || raw;
    const known = INPUT_MESSAGES.find(([pattern]) => pattern.test(text));
    if (known) return known[1];
    return looksTechnical(text) ? 'Some details need fixing. Check the highlighted fields.' : text;
  }

  if (status >= 500) return 'The server had a problem. Please try again in a moment.';

  return looksTechnical(raw) ? fallback : raw;
}

export function formatSeconds(totalSeconds) {
  const seconds = Math.max(0, Math.round(Number(totalSeconds) || 0));
  const minutes = Math.floor(seconds / 60);
  const rest = seconds % 60;
  if (minutes === 0) return `${rest} sec`;
  return rest === 0 ? `${minutes} min` : `${minutes} min ${rest} sec`;
}

/** 8250 → "0:08.3" */
export function formatTimecode(ms) {
  const value = Math.max(0, Number(ms) || 0) / 1000;
  const minutes = Math.floor(value / 60);
  const seconds = (value - minutes * 60).toFixed(1).padStart(4, '0');
  return `${minutes}:${seconds}`;
}

export function msToSeconds(ms) {
  return Math.round((Number(ms) || 0) / 100) / 10;
}

export function formatBytes(bytes) {
  const value = Number(bytes);
  if (!Number.isFinite(value) || value <= 0) return '—';
  if (value < 1024) return `${value} bytes`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;
  if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;
  return `${(value / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

export function formatDateTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export const TRANSITIONS = [
  { value: 'cut', label: 'Straight cut' },
  { value: 'dissolve', label: 'Cross-dissolve' },
  { value: 'fade', label: 'Fade through black' },
];

export const SOUND_ROLES = [
  { value: 'narration', label: 'Narration', icon: 'bi-mic', field: 'narration' },
  { value: 'dialogue', label: 'Dialogue', icon: 'bi-chat-quote', field: null },
  { value: 'music', label: 'Music', icon: 'bi-music-note-beamed', field: 'audio_direction' },
  { value: 'sfx', label: 'Sound effects', icon: 'bi-soundwave', field: null },
];

export function sortedScenes(reel) {
  return [...(reel?.scenes || [])].sort((a, b) => (a.sequence || 0) - (b.sequence || 0));
}

export function sceneName(scene) {
  if (!scene) return 'Scene';
  const number = scene.sequence ?? scene.scene_sequence;
  const title = scene.title ?? scene.scene_title;
  return title ? `Scene ${number} · ${title}` : `Scene ${number}`;
}
