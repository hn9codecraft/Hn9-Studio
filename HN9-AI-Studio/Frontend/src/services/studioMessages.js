// Plain-language copy for the Creative Studio. Nothing in here should ever show
// a status code, an internal id or a raw server message to the person using it.

export const NOT_CONNECTED = {
  video: 'Video creation is not connected yet. An administrator needs to connect a video service before videos can be made.',
  sound: 'Sound generation is not configured yet. An administrator needs to connect the sound service before scene sound can be made.',
  plan: 'Automatic scene planning is not connected yet. Add your scenes yourself, or ask an administrator to connect a writing service.',
  images: 'Picture creation is not connected yet. Upload a picture instead, or ask an administrator to connect a picture service.',
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
  provider_not_connected: 'No creation service was connected, so nothing was sent.',
  GENERATION_NOT_ENABLED: 'No creation service was connected, so nothing was sent.',
  QUOTA_EXCEEDED: 'The creation service account has run out of credit.',
  RATE_LIMITED: 'The creation service was busy. Try again in a minute.',
  TIMEOUT: 'The creation service took too long to answer.',
  AUTHENTICATION_FAILED: 'The creation service rejected the connection details. An administrator needs to check them.',
  DOWNLOAD_FAILED: 'The finished result could not be downloaded.',
  INVALID_PROVIDER_RESPONSE: 'The creation service sent back something unusable.',
  SUBMISSION_UNCONFIRMED: 'The creation service did not confirm the request, so it was not sent again.',
  INVALID_INPUT: 'The creation service could not use this request. Try changing the description or pictures.',
  story_render_empty: 'The timeline had no clips.',
  story_render_source_missing: 'A clip’s video file was missing.',
  story_media_tools_unavailable: 'The video builder is not set up on this server yet.',
  story_media_build_failed: 'The clips could not be put together into one video.',
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
  return PROVIDER_REASONS[errorCode] || 'The creation service could not finish this. You can try again.';
}

function transitionMessage(raw) {
  const match = /cannot be (\w+) from status '(\w+)'/.exec(raw || '');
  if (!match) {
    return looksTechnical(raw) ? 'That step is not available right now. Refresh to see the latest status.' : raw;
  }

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
    return NOT_CONNECTED[area] || 'This needs a creation service that is not connected yet. An administrator can connect one.';
  }

  if (code.endsWith('_generation_failed')) {
    return 'The creation service could not finish this. Try again in a moment.';
  }
  if (code === 'story_media_tools_unavailable') {
    return 'The video builder is not set up on this server yet, so clips cannot be joined into one video. An administrator needs to install it.';
  }
  if (code === 'story_media_build_failed') {
    return 'The clips could not be put together into one video. Check that every clip plays, then try again.';
  }

  if (code.endsWith('_invalid_transition')) return transitionMessage(raw);
  if (code === 'story_render_empty') {
    return 'There are no clips to build yet. Approve scene videos first; they appear on the timeline automatically.';
  }
  if (code === 'story_render_source_missing') return 'A clip’s video file is missing. Swap or remove that clip, then build again.';
  if (code === 'story_already_materialized') return 'Scenes were already created from this plan.';
  if (code === 'story_plan_not_generatable') return 'This plan is already being written or is finished.';
  if (code === 'story_approval_failed' || code === 'story_production_plan_failed') {
    return 'We couldn’t prepare this story for production. Nothing was changed.';
  }
  if (code.startsWith('story_plan_version_') || code.startsWith('story_production_') || code === 'story_approval_invalid_plan') {
    return looksTechnical(raw) ? 'This story cannot be prepared for production yet. Plan the story again, then approve it.' : raw;
  }
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

export const SOUND_MESSAGES = {
  generating: 'Generating sound…',
  ready: 'Sound ready for review.',
  approved: 'Sound approved.',
  changes: 'Changes requested.',
  selected: 'This version is now used in the video.',
  failed: 'Sound generation failed. Try again.',
};

/** Where one sound version stands, in words, with a badge tone. */
export function soundState(item) {
  if (!item) return { label: 'None yet', tone: 'neutral' };
  if (isJobActive(item.status)) return { label: 'Generating sound…', tone: 'progress' };
  if (item.status === 'failed') return { label: 'Generation failed', tone: 'danger' };
  if (item.review_status === 'approved') return { label: item.selected ? 'Approved · used in video' : 'Approved', tone: 'success' };
  if (item.review_status === 'needs_rework') return { label: 'Changes requested', tone: 'warning' };
  if (item.review_status === 'pending_review') return { label: 'Ready for review', tone: 'progress' };
  return { label: jobStatusLabel(item.status), tone: 'neutral' };
}

/** The saved failure sentence when it is plain, otherwise a general one. */
export function soundFailure(item) {
  const text = item?.error_message || '';
  return text && !looksTechnical(text) ? text : SOUND_MESSAGES.failed;
}

export function sortedScenes(reel) {
  return [...(reel?.scenes || [])].sort((a, b) => (a.sequence || 0) - (b.sequence || 0));
}

export function sceneName(scene) {
  if (!scene) return 'Scene';
  const number = scene.sequence ?? scene.scene_sequence;
  const title = scene.title ?? scene.scene_title;
  return title ? `Scene ${number} · ${title}` : `Scene ${number}`;
}
