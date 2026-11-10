const M17_MESSAGES = {
  story_character_lock_needs_reference: 'Approve a reference picture for this character first.',
  story_character_locked: 'Unlock this character to change its look.',
  story_character_reference_locked: 'Unlock this character before changing the locked picture.',
  story_prompt_preview_stale: 'The prompt, picture, price or provider changed since your preview. Check the preview again.',
  story_unit_prompt_invalid: 'Write a shorter scene description before saving it.',
};

export function m17Message(error) {
  const code = error?.errorCode || '';
  return M17_MESSAGES[code] || '';
}
