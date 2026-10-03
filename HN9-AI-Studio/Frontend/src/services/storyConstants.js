export const STORY_LANGUAGES = [
  { value: '', label: 'Not set' },
  { value: 'en', label: 'English' },
  { value: 'hi', label: 'Hindi' },
  { value: 'gu', label: 'Gujarati' },
];

export const STORY_ASPECT_RATIOS = [
  { value: '16:9', label: 'Landscape (16:9)', hint: 'YouTube, TV and desktop screens' },
  { value: '9:16', label: 'Portrait (9:16)', hint: 'Reels, Shorts and TikTok' },
];

export function aspectRatioLabel(value) {
  return STORY_ASPECT_RATIOS.find((item) => item.value === value)?.label || 'Not chosen yet';
}

export function storyFieldError(error, field) {
  return error?.errors?.[field]?.[0] || '';
}

// Values must match App\Support\StudioWorkflows on the backend.
export const STUDIO_WORKFLOWS = [
  {
    value: 'story',
    label: 'Story',
    icon: 'bi-journal-text',
    description: 'Write a story and let the studio turn it into scenes.',
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
    description: 'Create scene videos from a description, a picture or your characters.',
  },
  {
    value: 'audio',
    label: 'Audio',
    icon: 'bi-soundwave',
    description: 'Add narration, dialogue, music and sound effects to scenes.',
  },
];

// `workflows: null` means the step is always shown.
export const STUDIO_STEPS = [
  {
    key: 'story',
    label: 'Story',
    icon: 'bi-journal-text',
    workflows: null,
    description: 'Your idea, audience, visual style and video format.',
  },
  {
    key: 'cast',
    label: 'Cast & Look',
    icon: 'bi-people',
    workflows: null,
    description: 'Characters and the look & feel, each with an approved picture.',
  },
  {
    key: 'scenes',
    label: 'Scenes',
    icon: 'bi-film',
    workflows: ['story', 'videos', 'audio'],
    description: 'Plan scenes, create each scene’s video and approve it.',
  },
  {
    key: 'sound',
    label: 'Sound',
    icon: 'bi-soundwave',
    workflows: ['story', 'audio'],
    description: 'Narration, dialogue, music and sound effects for each scene.',
  },
  {
    key: 'final',
    label: 'Final Video',
    icon: 'bi-collection-play',
    workflows: ['story', 'videos', 'audio'],
    description: 'Arrange approved scenes, build the video, review and download it.',
  },
];

export const STUDIO_SECONDARY = [
  { key: 'images', label: 'Images', icon: 'bi-image', workflows: ['images'] },
  { key: 'history', label: 'History', icon: 'bi-clock-history', workflows: null },
  { key: 'settings', label: 'Studio settings', icon: 'bi-sliders', workflows: null },
];

const LEGACY_SECTIONS = {
  overview: 'story',
  characters: 'cast',
  style: 'cast',
  planner: 'scenes',
  reels: 'scenes',
  video: 'scenes',
  audio: 'sound',
  timeline: 'final',
};

/** Maps old `?section=` values (bookmarks, shared links) onto the current steps. */
export function resolveStudioSection(value) {
  return LEGACY_SECTIONS[value] || value || 'story';
}

export function normalizeStudioWorkflows(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return STUDIO_WORKFLOWS.map((item) => item.value).filter((item) => value.includes(item));
}

function forWorkflows(items, workflows) {
  const selected = normalizeStudioWorkflows(workflows);

  if (selected.length === 0) {
    return items;
  }

  return items.filter((item) => !item.workflows || item.workflows.some((workflow) => selected.includes(workflow)));
}

/** Projects without a saved workflow choice keep every step. */
export function visibleStudioSteps(workflows) {
  return forWorkflows(STUDIO_STEPS, workflows);
}

export function visibleStudioSecondary(workflows) {
  return forWorkflows(STUDIO_SECONDARY, workflows);
}

export function studioWorkflowLabel(value) {
  return STUDIO_WORKFLOWS.find((item) => item.value === value)?.label || value;
}
