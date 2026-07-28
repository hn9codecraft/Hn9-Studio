# Storyboard Agent

## Purpose
Convert a script into a scene-by-scene visual plan (shots, on-screen text, b-roll, transitions) for
media deliverables.

## Responsibilities
- Break the script into timed scenes.
- Specify shot type, movement, on-screen text and b-roll per scene.
- Define transitions and a branded end card.

## Inputs
Script, platform, duration, aspect ratio, scene count, language.

## Outputs
Storyboard: `scenes[]` (number, duration, shot, onScreenText, broll, transition) + `endCard`.

## Reads From
`context.script`; `context.strategy`.

## Writes To
Runtime Context (`context.storyboard`); emits `StoryboardReady` (triggers media fan-out).

## Brand Files Required
`../Brand/video-style.json`, `../Brand/colors.json`, `../Brand/design-system.json`,
`../Brand/fonts.json`.

## Prompt Templates Required
`../Prompts/templates/storyboard.md`.

## Dependencies
Script Agent. Runs only for media deliverables (reel/short/youtube).

## Decision Rules
- Scene count fits duration; Hook scene first.
- Aspect ratio from platform; brand colors/fonts applied.
- End card = logo + tagline + CTA.

## Validation Rules (validation.md → Video/Media)
- Reel structure present; aspect ratio and subtitle rules honored; branded end card present.

## Retry Rules
Transient → 3 retries. Structure/aspect fail → 1 self-correction, else escalate.

## Failure Conditions
Scenes don't fit duration; missing end card; off-brand visuals.

## Recovery Strategy
Recompute scene timing; re-apply brand style; else escalate.

## Escalation Rules
Escalate on repeated structure/brand failures.

## Performance Requirements
p95 < 8s.

## Logging Requirements
Log scene count, aspect ratio, duration fit, version.

## Security Considerations
Visual directions must not embed off-brand or third-party IP.

## Example Input
```
{ "script": {...}, "platform": "instagram", "duration": "30s",
  "aspect_ratio": "9:16", "scene_count": 5 }
```

## Example Output
```
{ "storyboard": { "scenes": [
  {"number":1,"duration":"0-3s","shot":"close-up","onScreenText":"Still chasing leads?","transition":"cut"} ],
  "endCard": "logo + tagline + CTA" } }
```

## Next Agent
Fan-out → **Image Prompt · Video Prompt · Voice · Caption · Thumbnail Agents**
