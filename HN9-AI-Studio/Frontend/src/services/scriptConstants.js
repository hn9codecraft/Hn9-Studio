export const SCRIPT_STATUSES = [
  { value: 'draft', label: 'Draft' },
  { value: 'ready', label: 'Ready' },
  { value: 'pending_review', label: 'Pending review' },
  { value: 'needs_rework', label: 'Needs rework' },
  { value: 'approved', label: 'Approved' },
  { value: 'archived', label: 'Archived' },
];

export const SCRIPT_ASSIGNABLE_STATUSES = SCRIPT_STATUSES.filter((status) =>
  ['draft', 'ready', 'archived'].includes(status.value),
);

export const SCRIPT_PLATFORMS = [
  { value: 'instagram', label: 'Instagram Reels' },
  { value: 'youtube', label: 'YouTube Shorts' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'facebook', label: 'Facebook Reels' },
  { value: 'linkedin', label: 'LinkedIn' },
];

export const SCRIPT_LANGUAGES = [
  { value: 'en', label: 'English' },
  { value: 'hi', label: 'Hindi' },
  { value: 'gu', label: 'Gujarati' },
];

const REVIEW_ACTION_LABELS = {
  submitted_for_review: 'Submitted for review',
  resubmitted: 'Resubmitted for review',
  approved: 'Approved',
  needs_rework: 'Needs rework',
  reworked: 'Reworked',
};

export function scriptStatusLabel(status) {
  return SCRIPT_STATUSES.find((item) => item.value === status)?.label || status || 'Unknown';
}

export function scriptStatusClass(status) {
  const map = {
    draft: 'status-draft',
    ready: 'status-ready',
    archived: 'status-archived',
    pending_review: 'status-pending-review',
    approved: 'status-approved',
    needs_rework: 'status-needs-rework',
  };

  return map[status] || 'status-draft';
}

export function scriptReviewActionLabel(action) {
  return REVIEW_ACTION_LABELS[action] || action || 'Review event';
}

export function scriptOriginLabel(source) {
  return source === 'ai' ? 'AI generated' : 'Manual';
}

export function isAiScript(script) {
  return script?.source === 'ai';
}

export function scriptCapabilities(script) {
  return {
    edit: Boolean(script?.capabilities?.edit),
    submit: Boolean(script?.capabilities?.submit),
    approve: Boolean(script?.capabilities?.approve),
    request_rework: Boolean(script?.capabilities?.request_rework),
    regenerate: Boolean(script?.capabilities?.regenerate),
  };
}

export function fieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}

export function generationErrorMessage(error) {
  if (!error) {
    return 'Unable to generate a script.';
  }

  if (error.errorCode === 'ai_provider_not_configured' || error.context?.reason === 'not_configured') {
    return 'AI generation is unavailable because no provider credentials are configured. Enable a text provider and add its runtime API key. No script was created.';
  }

  if (error.errorCode === 'generation_project_not_editable') {
    return 'This project cannot accept generation requests in its current status.';
  }

  if (error.errorCode === 'ai_all_providers_failed' || error.errorCode === 'ai_provider_timeout') {
    return error.message || 'The AI provider failed. No script was created.';
  }

  if (error.status === 401) {
    return 'You need to sign in again to generate a script.';
  }

  if (error.status === 403) {
    return 'You do not have permission to generate a script for this project.';
  }

  return error.message || 'Unable to generate a script.';
}

export function workflowErrorMessage(error, fallback = 'Unable to complete that review action.') {
  if (!error) {
    return fallback;
  }

  if (error.errorCode === 'script_workflow_project_not_editable') {
    return 'This project cannot accept review actions in its current status.';
  }

  if (error.errorCode === 'script_workflow_edit_locked') {
    return 'This script is locked and cannot be edited in its current status.';
  }

  if (error.errorCode === 'script_workflow_comment_required') {
    return 'A rework comment is required so the creator can see what to change.';
  }

  if (error.status === 403) {
    return 'You do not have permission to perform this review action.';
  }

  return error.message || fallback;
}

export function briefFromScript(script) {
  const generation = script?.generation || {};
  const payload = generation.payload || {};

  return {
    topic: generation.topic || script?.title || '',
    platform: generation.platform || 'instagram',
    language: generation.language || 'en',
    goal: generation.goal || '',
    duration: payload.duration || '',
    audience: payload.audience || '',
    tone: payload.tone || '',
    cta: payload.cta || '',
    service: payload.service || '',
    video_style: payload.video_style || '',
    voice_style: payload.voice_style || '',
    brand_rules: payload.brand_rules || '',
    key_points: payload.key_points || '',
    additional_instructions: payload.additional_instructions || '',
    title: script?.title || '',
  };
}
