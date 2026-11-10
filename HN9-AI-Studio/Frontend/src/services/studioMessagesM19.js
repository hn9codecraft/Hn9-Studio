import { friendlyError } from './studioMessages';

const M19_MESSAGES = {
  story_version_not_restorable: 'This version cannot be restored. It may have failed, or its video file is missing.',
  story_render_in_progress: 'A video is already being built. Wait until it finishes, then try again.',
  story_voice_language_unsupported: 'Gujarati needs a newer voice model. An administrator should set ELEVENLABS_MODEL_GU to eleven_v3 or newer.',
};

export function friendlyErrorM19(error, fallback, area) {
  const code = error?.errorCode || error?.error_code || '';
  if (M19_MESSAGES[code]) return M19_MESSAGES[code];
  return friendlyError(error, fallback, area);
}
