export const EXPORT_STATE_LABELS = {
  not_ready: 'Not Ready',
  ready_to_finalize: 'Ready for Finalization',
  finalizing: 'Finalizing',
  ready_to_export: 'Ready to Export',
  exporting: 'Exporting',
  export_ready: 'Export Ready',
  export_failed: 'Export Failed',
};

export function exportStateLabel(state) {
  return EXPORT_STATE_LABELS[state] || 'Not Ready';
}

export function exportStateClass(state) {
  switch (state) {
    case 'ready_to_finalize':
    case 'ready_to_export':
      return 'status-active';
    case 'export_ready':
      return 'status-completed';
    case 'exporting':
    case 'finalizing':
      return 'status-pending';
    case 'export_failed':
      return 'status-failed';
    default:
      return 'status-draft';
  }
}

export function exportErrorMessage(error) {
  const code = error?.errorCode;
  const issues = Array.isArray(error?.context?.issues) ? error.context.issues : [];
  const firstIssue = issues[0]?.code;

  if (code === 'project_not_ready_for_export' || firstIssue) {
    return issueMessage(firstIssue) || 'Project is not ready for export.';
  }

  if (code === 'project_not_finalized') {
    return 'Finalize the project before exporting.';
  }

  if (code === 'export_project_archived') {
    return 'Archived projects cannot be finalized or exported.';
  }

  if (code === 'export_in_progress') {
    return 'An export is already in progress for this project.';
  }

  if (code === 'export_package_missing') {
    return 'The export package is missing. Please export again.';
  }

  if (code === 'export_not_ready') {
    return 'This export is not ready to download.';
  }

  if (code === 'export_package_failed') {
    return 'Export generation failed. Please try again.';
  }

  return error?.message || 'Unable to complete that export action.';
}

export function issueMessage(code) {
  switch (code) {
    case 'script_not_approved':
      return 'An approved script is required.';
    case 'script_empty':
      return 'The approved script has no content.';
    case 'image_not_approved':
      return 'An approved image is required.';
    case 'image_file_missing':
    case 'export_asset_file_missing':
      return 'An approved asset is missing.';
    case 'image_invalid_mime':
      return 'An approved image has an invalid file type.';
    case 'video_not_approved':
      return 'An approved video is required.';
    case 'video_file_missing':
      return 'An approved video is missing.';
    case 'video_invalid_mime':
      return 'An approved video has an invalid file type.';
    case 'video_processing':
      return 'Video is still processing.';
    case 'project_archived':
      return 'This project is archived.';
    default:
      return '';
  }
}
