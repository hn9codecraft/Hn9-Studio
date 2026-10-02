export const STORY_LANGUAGES = [
  { value: '', label: 'Not set' },
  { value: 'en', label: 'English' },
  { value: 'hi', label: 'Hindi' },
  { value: 'gu', label: 'Gujarati' },
];

export const STORY_ASPECT_RATIOS = [
  { value: '', label: 'Not set' },
  { value: '16:9', label: '16:9 Landscape' },
  { value: '9:16', label: '9:16 Portrait' },
];

export const CHARACTER_REFERENCE_STATUS_LABELS = {
  draft: 'Draft',
  pending_review: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  archived: 'Archived',
};

export function characterReferenceStatusLabel(status) {
  return CHARACTER_REFERENCE_STATUS_LABELS[status] || status || 'Unknown';
}

export function storyFieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}

export function storyCapabilityLabel(capability) {
  const labels = {
    text_to_video: 'Text to Video',
    image_to_video: 'Image to Video',
    reference_to_video: 'Reference to Video',
    video_edit: 'Video Edit',
    video_extend: 'Video Extend',
    audio: 'Audio',
  };

  return labels[capability] || capability || 'Unknown';
}

// Values must match App\Support\StudioWorkflows on the backend.
export const STUDIO_WORKFLOWS = [
  {
    value: 'story',
    label: 'Story',
    icon: 'bi-journal-text',
    description: 'Plan a narrative with story context, a scene plan, reels and scenes.',
  },
  {
    value: 'images',
    label: 'Images',
    icon: 'bi-image',
    description: 'Create and review still images with consistent characters and style.',
  },
  {
    value: 'videos',
    label: 'Video',
    icon: 'bi-camera-reels',
    description: 'Generate video clips from prompts, images or references.',
  },
  {
    value: 'audio',
    label: 'Audio',
    icon: 'bi-soundwave',
    description: 'Add voice, music and sound effects to scenes.',
  },
];

export const STUDIO_GROUPS = [
  { key: 'start', label: '' },
  { key: 'plan', label: 'Plan' },
  { key: 'produce', label: 'Produce' },
  { key: 'finish', label: 'Finish' },
];

// `workflows: null` means the section is always shown.
export const STUDIO_SECTIONS = [
  { key: 'overview', label: 'Overview', group: 'start', workflows: null, description: '' },
  {
    key: 'story',
    label: 'Story Context',
    group: 'plan',
    workflows: ['story'],
    description: 'Concept, audience, tone and world for the whole project.',
  },
  {
    key: 'characters',
    label: 'Characters',
    group: 'plan',
    workflows: ['story', 'images', 'videos'],
    description: 'People and subjects, with approved reference images.',
  },
  {
    key: 'style',
    label: 'Visual Style',
    group: 'plan',
    workflows: ['story', 'images', 'videos'],
    description: 'Look, lighting, color and style reference images.',
  },
  {
    key: 'planner',
    label: 'Story Planner',
    group: 'plan',
    workflows: ['story'],
    description: 'Turn an idea into a timed scene plan.',
  },
  {
    key: 'reels',
    label: 'Reels & Scenes',
    group: 'produce',
    workflows: ['story', 'audio'],
    description: 'Organise reels, write scenes and review them.',
  },
  {
    key: 'video',
    label: 'Video',
    group: 'produce',
    workflows: ['story', 'videos'],
    description: 'Generate video clips for scenes or standalone.',
  },
  {
    key: 'audio',
    label: 'Audio',
    group: 'produce',
    workflows: ['audio'],
    description: 'Voice, music and sound effects for scenes.',
  },
  {
    key: 'timeline',
    label: 'Timeline & Review',
    group: 'finish',
    workflows: ['story', 'audio'],
    description: 'Assemble clips, render, review and export.',
  },
  {
    key: 'history',
    label: 'History',
    group: 'finish',
    workflows: null,
    description: 'Everything generated in this project.',
  },
];

export const STUDIO_PROJECT_TOOLS = [
  { key: 'images', label: 'Image Studio', icon: 'bi-image', path: 'images', workflow: 'images' },
  { key: 'videos', label: 'Video Studio', icon: 'bi-film', path: 'videos', workflow: 'videos' },
  { key: 'scripts', label: 'Scripts', icon: 'bi-file-earmark-text', path: 'scripts', workflow: null },
  { key: 'assets', label: 'Assets', icon: 'bi-folder2-open', path: 'assets', workflow: null },
  { key: 'final', label: 'Final output', icon: 'bi-box-seam', path: 'final', workflow: null },
];

export function normalizeStudioWorkflows(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return STUDIO_WORKFLOWS.map((item) => item.value).filter((item) => value.includes(item));
}

/** Projects without a saved workflow choice keep every section. */
export function visibleStudioSections(workflows) {
  const selected = normalizeStudioWorkflows(workflows);

  if (selected.length === 0) {
    return STUDIO_SECTIONS;
  }

  return STUDIO_SECTIONS.filter(
    (section) => !section.workflows || section.workflows.some((item) => selected.includes(item)),
  );
}

export function studioWorkflowLabel(value) {
  return STUDIO_WORKFLOWS.find((item) => item.value === value)?.label || value;
}
