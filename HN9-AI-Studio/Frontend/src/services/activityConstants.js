export const ACTIVITY_MODULES = [
  { value: 'project', label: 'Project' },
  { value: 'script', label: 'Script' },
  { value: 'image', label: 'Image' },
  { value: 'video', label: 'Video' },
  { value: 'asset', label: 'Asset' },
];

const MODULE_ICONS = {
  project: 'bi-folder2-open',
  script: 'bi-file-text',
  image: 'bi-image',
  video: 'bi-camera-video',
  asset: 'bi-archive',
};

export function activityModuleLabel(module) {
  return ACTIVITY_MODULES.find((item) => item.value === module)?.label || module || 'Activity';
}

export function activityModuleIcon(module) {
  return MODULE_ICONS[module] || 'bi-clock-history';
}

export function activityActionLabel(action) {
  if (!action) {
    return 'Activity';
  }

  const part = action.split('.').pop() || action;
  return part.replace(/_/g, ' ');
}

export function formatActivityDateTime(value) {
  if (!value) {
    return '—';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export function activitySubjectPath(projectId, item) {
  const subjectId = item?.subject?.id;
  if (!projectId || !subjectId) {
    return null;
  }

  switch (item.module) {
    case 'script':
      return `/projects/${projectId}/scripts/${subjectId}`;
    case 'image':
      return `/projects/${projectId}/images/${subjectId}`;
    case 'video':
      return `/projects/${projectId}/videos/${subjectId}`;
    case 'asset':
      return `/projects/${projectId}/assets/${subjectId}`;
    case 'project':
      return `/projects/${projectId}`;
    default:
      return null;
  }
}
