# Image Prompt Agent

## Purpose
Author on-brand prompts for AI image generation (post visuals, backgrounds, illustrations, mockups),
one per storyboard scene or static asset.

## Responsibilities
- Produce detailed image-gen prompts with composition, style, palette and negative prompt.
- Enforce brand colors, fonts-style and illustration style.

## Inputs
Storyboard scenes (or static concept), platform, aspect ratio, language (for in-image text), style reference.

## Outputs
`imagePrompts[]`: prompt, style, palette, aspectRatio, negativePrompt.

## Reads From
`context.storyboard` (or `context.strategy` for static), `context.script`.

## Writes To
Runtime Context (`context.images`); emits `ImagePromptsReady`.

## Brand Files Required
`../Brand/colors.json`, `../Brand/design-system.json`, `../Brand/fonts.json`.

## Prompt Templates Required
`../Prompts/templates/image.md`.

## Dependencies
Storyboard Agent (media) or Strategy Agent (static). Runs in parallel fan-out.

## Decision Rules
- Brand palette only; illustration style per `design-system.json`.
- Aspect ratio from platform; include a negative prompt every time.

## Validation Rules (validation.md → Prompt Validation)
- Palette on-brand; aspect ratio correct; negative prompt present; in-image text in `{{language}}`.

## Retry Rules
Transient → 3 retries. Validation fail → 1 self-correction, else escalate.

## Failure Conditions
Off-brand colors; missing negative prompt; wrong aspect ratio.

## Recovery Strategy
Re-apply brand palette/style; regenerate; else escalate.

## Escalation Rules
Escalate on repeated off-brand output.

## Performance Requirements
p95 < 6s per prompt; batch scenes where possible.

## Logging Requirements
Log scene ref, palette, aspect ratio, version.

## Security Considerations
No third-party IP/brands; brand names untranslated in text overlays.

## Example Input
```
{ "scene": {"number":1,"concept":"founder overwhelmed by manual work"},
  "platform":"instagram","aspect_ratio":"9:16","language":"en" }
```

## Example Output
```
{ "imagePrompt": "Modern flat geometric illustration, founder at laptop, deep-blue base,
  amber accents, rounded shapes, premium, 9:16",
  "negativePrompt": "photo-real, clutter, off-brand colors, distorted text" }
```

## Next Agent
**Review Agent** (fan-in)
