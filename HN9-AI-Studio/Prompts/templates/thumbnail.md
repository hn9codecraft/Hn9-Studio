# Template — Thumbnail Prompt

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate an image-generation prompt for a click-worthy, on-brand video/blog thumbnail (for
downstream image models).

## 2. Inputs
- Video/blog topic, platform, aspect ratio, language (for on-thumb text), style reference.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{aspect_ratio}}`, `{{company_name}}`,
`{{primary_color}}`, `{{secondary_color}}`, `{{heading_font}}`, `{{design_system}}`,
`{{brand_rules}}`.

## 4. Optional Variables
`{{style_reference}}`, `{{service}}`.

## 5. Prompt Template
```
Write an image-generation prompt (in English for the model) for a {{platform}} thumbnail by
{{company_name}} about "{{topic}}".
Aspect ratio {{aspect_ratio}}. Use brand colors {{primary_color}} (dominant) + {{secondary_color}}
(accent). Headline font style like {{heading_font}}. Design per {{design_system}}. Obey: {{brand_rules}}.

Specify:
- Composition (subject, focal point, background).
- Overlaid headline text in {{language}} (<=5 words, high contrast).
- Mood: modern, premium, clean.
- Negative prompt: clutter, low contrast, off-brand colors, distorted text.

Do not translate brand names. Keep it legible at small sizes.
```

## 6. Output Structure
```
imagePrompt, overlayText, aspectRatio, negativePrompt, colorNotes
```

## 7. Validation Checklist
- [ ] Brand colors dominant/accent correct.
- [ ] Overlay text ≤5 words, high contrast, in `{{language}}`.
- [ ] Correct aspect ratio; legible small.
- [ ] Negative prompt included; on-brand.

## 8. Example Input
```
platform: youtube | language: en | topic: "3 AI processes to automate" | aspect_ratio: 16:9
```

## 9. Example Output
```
imagePrompt: "Modern flat illustration, confident founder at laptop, deep-blue background,
amber accent shapes, bold headline 'AUTOMATE THIS' top-left, premium, minimal, 16:9"
negativePrompt: "clutter, tiny text, gradients off-brand, distorted letters"
```
