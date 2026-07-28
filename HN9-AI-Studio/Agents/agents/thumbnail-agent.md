# Thumbnail Agent

## Purpose
Author the image-gen prompt for a click-worthy, on-brand thumbnail (video/blog).

## Responsibilities
- Produce a thumbnail image prompt with composition, overlay headline text and negative prompt.
- Enforce brand colors, heading-font style and legibility at small sizes.

## Inputs
Topic, platform, aspect ratio, language (overlay text), style reference.

## Outputs
`imagePrompt`, `overlayText`, `aspectRatio`, `negativePrompt`, `colorNotes`.

## Reads From
`context.script`/`context.strategy`, `context.storyboard`.

## Writes To
Runtime Context (`context.thumbnail`); emits `ThumbnailReady`.

## Brand Files Required
`../Brand/colors.json`, `../Brand/fonts.json`, `../Brand/design-system.json`.

## Prompt Templates Required
`../Prompts/templates/thumbnail.md`.

## Dependencies
Storyboard/Script Agent. Runs in parallel fan-out.

## Decision Rules
- Brand primary dominant + secondary accent.
- Overlay text ≤5 words, high contrast, in `{{language}}`.
- Aspect ratio from platform (16:9 YouTube, 9:16 shorts).

## Validation Rules (validation.md → Prompt)
- Colors correct; overlay ≤5 words; legible small; negative prompt present.

## Retry Rules
Transient → 3 retries. Validation fail → 1 self-correction, else escalate.

## Failure Conditions
Low contrast; too much text; off-brand colors.

## Recovery Strategy
Reduce text, boost contrast, re-apply palette; else escalate.

## Escalation Rules
Escalate on repeated illegible/off-brand output.

## Performance Requirements
p95 < 6s.

## Logging Requirements
Log overlay text, palette, aspect ratio, version.

## Security Considerations
No third-party IP; brand names untranslated.

## Example Input
```
{ "topic":"3 AI processes to automate", "platform":"youtube", "aspect_ratio":"16:9", "language":"en" }
```

## Example Output
```
{ "imagePrompt":"Modern illustration, confident founder, deep-blue bg, amber shapes, bold headline
  'AUTOMATE THIS' top-left, premium, 16:9", "overlayText":"AUTOMATE THIS",
  "negativePrompt":"clutter, tiny text, off-brand colors, distorted letters" }
```

## Next Agent
**Review Agent** (fan-in)
