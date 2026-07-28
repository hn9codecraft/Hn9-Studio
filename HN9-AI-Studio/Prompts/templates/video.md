# Template — Video Prompt (AI Video Generation)

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a prompt for AI video-generation tools (text-to-video / image-to-video) to produce an
on-brand clip or scene for downstream models.

## 2. Inputs
- Scene concept, platform, duration, aspect ratio, motion/style reference, language (for text).

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}` (scene concept), `{{duration}}`, `{{aspect_ratio}}`,
`{{company_name}}`, `{{video_style}}`, `{{animation_style}}`, `{{primary_color}}`,
`{{secondary_color}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{style_reference}}`, `{{music_style}}`, `{{service}}`.

## 5. Prompt Template
```
Write an AI video-generation prompt (in English for the model) for {{company_name}}.
Scene: "{{topic}}". Platform: {{platform}}. Duration: {{duration}}. Aspect ratio: {{aspect_ratio}}.
Motion/animation per {{video_style}} and {{animation_style}} (smooth, premium, purposeful).
Brand colors: {{primary_color}}, {{secondary_color}}. Obey: {{brand_rules}}.

Specify:
- Scene description & camera movement (subtle, gimbal-like).
- Visual style & mood (modern, premium, clean).
- Color grade (accurate brand colors).
- Any on-screen text in {{language}} (brand names untranslated).
- Negative prompt: shaky motion, cheesy effects, off-brand colors, distorted text.
```

## 6. Output Structure
```
videoPrompt, cameraMovement, style, aspectRatio, duration, negativePrompt
```

## 7. Validation Checklist
- [ ] Smooth motion; premium style per `video-style.json`.
- [ ] Brand colors accurate; aspect ratio correct.
- [ ] On-screen text in `{{language}}`; brand names intact.
- [ ] Negative prompt included.

## 8. Example Input
```
platform: instagram | language: en | topic: "Dashboard analytics animating up" | duration: 6s
aspect_ratio: 9:16
```

## 9. Example Output
```
videoPrompt: "Smooth push-in on a modern analytics dashboard, deep-blue UI, amber highlights,
numbers ticking up, clean premium motion, 9:16, 6s"
negativePrompt: "shaky, glitchy, off-brand colors, distorted text"
```
