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
