const M18_MESSAGES = {
  story_unit_generation_failed: 'This attempt failed. Retry it to start a new one.',
  story_unit_retry_not_allowed: 'This unit cannot be retried right now.',
  story_unit_retry_not_retryable: 'Change the prompt and generate again.',
  story_unit_retry_limit: 'This unit has used its retries.',
  story_bulk_selection_changed: 'The selection changed. Check it again before approving.',
  story_bulk_in_progress: 'Another approval is still finishing. Try again in a moment.',
  story_bulk_kind_invalid: 'That cannot be approved as a group.',
  story_bulk_selection_invalid: 'Choose between 1 and 50 items.',
};

export function m18Message(error) {
  const code = error?.errorCode || '';
  return M18_MESSAGES[code] || '';
}
