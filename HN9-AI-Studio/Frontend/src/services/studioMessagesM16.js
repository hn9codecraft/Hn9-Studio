const M16_MESSAGES = {
  story_voice_timing_requires_regeneration: 'Changed scenes already have video clips. Confirm that those scenes need new clips. Nothing is generated now.',
  story_voice_timing_in_progress: 'Scene lengths are already being matched for this project.',
  story_media_unavailable: 'FFmpeg/ffprobe was not found, so the voiceover length could not be measured.',
  story_subtitle_burn_unavailable: 'This FFmpeg build cannot draw subtitles. Install a full build with libass.',
  story_subtitle_font_missing: 'Set a subtitle font that supports Gujarati, then try again.',
  story_subtitle_burn_in_progress: 'Subtitles are already being burned for this video.',
  story_render_in_progress: 'A video is already being built for this reel.',
  story_subtitle_burn_failed: 'The subtitled video could not be built.',
};

export function m16Message(error) {
  const code = error?.errorCode || '';
  return M16_MESSAGES[code] || '';
}
