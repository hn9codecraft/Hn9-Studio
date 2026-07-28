# Video Prompt Agent

## Purpose
Author on-brand prompts for AI video generation (text-to-video / image-to-video) per storyboard
scene.

## Responsibilities
- Produce video-gen prompts with scene description, camera movement, style, color grade and negative
  prompt.
- Enforce brand motion/animation style and colors.

## Inputs
Storyboard scenes, platform, duration, aspect ratio, motion/style reference, language.

## Outputs
`videoPrompts[]`: prompt, cameraMovement, style, aspectRatio, duration, negativePrompt.

## Reads From
`context.storyboard`, `context.script`.

## Writes To
Runtime Context (`context.videos`); emits `VideoPromptsReady`.

## Brand Files Required
`../Brand/video-style.json`, `../Brand/colors.json`.

## Prompt Templates Required
`../Prompts/templates/video.md`.

## Dependencies
Storyboard Agent. Runs in parallel fan-out.

## Decision Rules
- Motion per `video-style.json` (smooth, premium); brand color grade.
- Aspect ratio + per-scene duration from storyboard; always include negative prompt.

## Validation Rules (validation.md → Prompt Validation)
- Smooth motion; on-brand colors; correct aspect ratio; negative prompt present.

## Retry Rules
Transient → 3 retries. Validation fail → 1 self-correction, else escalate.

## Failure Conditions
Shaky/cheesy motion directives; off-brand grade; wrong aspect ratio.

## Recovery Strategy
Re-apply style constraints; regenerate; else escalate.

## Escalation Rules
Escalate on repeated off-brand output.

## Performance Requirements
p95 < 6s per prompt.

## Logging Requirements
Log scene ref, motion style, aspect ratio, version.

## Security Considerations
No third-party IP; brand names untranslated in on-screen text.

## Example Input
```
{ "scene": {"number":2,"concept":"AI replying to leads instantly"},
  "platform":"instagram","duration":"6s","aspect_ratio":"9:16" }
```

## Example Output
```
{ "videoPrompt": "Smooth push-in on chat UI auto-replying, deep-blue UI, amber highlights,
  clean premium motion, 9:16, 6s",
  "negativePrompt": "shaky, glitch, off-brand colors, distorted text" }
```

## Next Agent
**Review Agent** (fan-in)
